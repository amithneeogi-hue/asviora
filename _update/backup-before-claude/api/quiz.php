<?php
/**
 * Quiz engine API.
 *
 * Everything that matters is decided here, on the server:
 *  - the question order,
 *  - which option is correct,
 *  - whether the answer arrived inside the time limit,
 *  - how many coins were earned.
 *
 * The browser is only ever sent the question text and the four options.
 * The correct answer is released *after* the player has committed to one.
 *
 * Actions:  question | answer | finish
 */
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

/** Send JSON and stop. */
function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(['ok' => false, 'message' => 'POST only.'], 405);
}
if (!is_logged_in()) {
    out(['ok' => false, 'message' => 'Please log in again.', 'redirect' => url('login.php')], 401);
}
if (!quiz_on()) {
    out(['ok' => false, 'message' => 'The quiz is not available right now.'], 403);
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    $body = $_POST;
}

if (!isset($body['csrf'], $_SESSION['csrf'])
    || !hash_equals($_SESSION['csrf'], (string) $body['csrf'])) {
    out(['ok' => false, 'message' => 'Session expired. Reload the page.'], 419);
}

$user   = current_user();
$uid    = (int) $user['id'];
$action = (string) ($body['action'] ?? '');
$sid    = (int) ($body['session'] ?? 0);

$sess = fetch_one('SELECT * FROM quiz_sessions WHERE id = ? AND user_id = ?', [$sid, $uid]);
if (!$sess) {
    out(['ok' => false, 'message' => 'Round not found.'], 404);
}

$payload = json_decode((string) $sess['payload'], true);
if (!is_array($payload) || empty($payload['ids'])) {
    out(['ok' => false, 'message' => 'Round data is corrupt. Start a new round.'], 422);
}

$ids   = $payload['ids'];
$res   = $payload['res'] ?? [];
$level = quiz_level($sess['difficulty']);
$total = count($ids);
$idx   = count($res);            // 0-based index of the question now in play

/** Persist the payload back onto the session row. */
function save_payload(int $sid, array $payload): void
{
    q('UPDATE quiz_sessions SET payload = ? WHERE id = ?', [json_encode($payload), $sid]);
}

/** Human wording for why a round was killed. */
function void_label(?string $kind): string
{
    switch ($kind) {
        case 'blur':
        case 'hidden':      return 'you left the quiz window';
        case 'fullscreen':  return 'you left fullscreen exam mode';
        case 'devtools':    return 'developer tools were opened';
        case 'copy':        return 'copying the question was attempted';
        case 'duplicate':   return 'the quiz was opened in another tab';
        default:            return 'the exam rules were broken';
    }
}

/** Build the summary a finished round returns. */
function summarise(array $sess, int $total): array
{
    $correct = (int) $sess['correct'];

    // With marks in play the percentage that decides a level is the marks
    // percentage, not the head-count of right answers.
    $marksTotal  = (int) ($sess['marks_total'] ?? 0);
    $marksScored = (int) ($sess['marks_scored'] ?? 0);
    $pct = $marksTotal > 0
        ? (int) round($marksScored / $marksTotal * 100)
        : ($total > 0 ? (int) round($correct / $total * 100) : 0);

    return [
        'correct'      => $correct,
        'wrong'        => (int) $sess['answered'] - $correct,
        'total'        => $total,
        'percent'      => $pct,
        'marks'        => $marksScored,
        'marks_total'  => $marksTotal,
        'level'        => (int) ($sess['level_no'] ?? 0),
        'pass_mark'    => quiz_pass_percent(),
        'passed'       => (int) ($sess['passed'] ?? 0) === 1,
        'coins'        => (int) $sess['coins_earned'],
        'xp'           => (int) $sess['xp_earned'],
        'time'         => (int) $sess['time_taken'],
    ];
}

// -------------------------------------------------------------------
// A voided round is dead. This check is what makes the anti-cheat real:
// the browser can be told to stop reporting, but it cannot un-void a
// session, so no further question, answer or payout is ever served.
// -------------------------------------------------------------------
if ($sess['status'] === 'voided') {
    out([
        'ok'      => false,
        'voided'  => true,
        'done'    => true,
        'message' => 'This round was cancelled because ' . void_label($sess['void_reason']) . '.',
        'summary' => summarise($sess, $total),
    ]);
}

// -------------------------------------------------------------------
// Already finished? Only `finish` makes sense.
// -------------------------------------------------------------------
if ($sess['status'] !== 'active' && $action !== 'finish') {
    out(['ok' => false, 'message' => 'This round is already over.', 'done' => true]);
}

switch ($action) {

// -------------------------------------------------------------------
// Serve the next question (never includes the correct answer)
// -------------------------------------------------------------------
case 'question':

    if ($idx >= $total) {
        out(['ok' => true, 'done' => true, 'summary' => summarise($sess, $total)]);
    }

    $qid = (int) $ids[$idx];
    $row = fetch_one('SELECT id, question, option_a, option_b, option_c, option_d, image,
                             marks, time_limit
                        FROM quiz_questions WHERE id = ?', [$qid]);
    if (!$row) {
        // question was deleted mid-round — skip it cleanly
        $res[] = ['q' => $qid, 'choice' => 0, 'ok' => false, 'sec' => 0, 'skipped' => true];
        $payload['res'] = $res;
        save_payload($sid, $payload);
        out(['ok' => true, 'retry' => true]);
    }

    // A question may carry its own clock (set on import or in the admin);
    // otherwise the level's default applies. The limit is stamped onto the
    // session so grading uses exactly what the player was shown.
    $seconds = (int) ($row['time_limit'] ?: $level['seconds']);

    // stamp the serve time — this is what the answer is measured against
    $payload['served'] = time();
    $payload['limit']  = $seconds;
    save_payload($sid, $payload);

    $options = array_values(array_filter([
        $row['option_a'], $row['option_b'], $row['option_c'], $row['option_d'],
    ], static fn($o) => $o !== null && $o !== ''));

    out([
        'ok'       => true,
        'index'    => $idx + 1,
        'total'    => $total,
        'seconds'  => $seconds,
        'marks'    => (int) $row['marks'],
        'question' => $row['question'],
        'image'    => $row['image'] ? UPLOAD_URL . '/quiz/' . rawurlencode($row['image']) : null,
        'options'  => $options,
    ]);

// -------------------------------------------------------------------
// Grade an answer
// -------------------------------------------------------------------
case 'answer':

    if ($idx >= $total) {
        out(['ok' => true, 'done' => true, 'summary' => summarise($sess, $total)]);
    }

    $qid    = (int) $ids[$idx];
    $choice = (int) ($body['choice'] ?? 0);          // 0 = ran out of time
    $choice = ($choice >= 1 && $choice <= 4) ? $choice : 0;

    $row = fetch_one('SELECT * FROM quiz_questions WHERE id = ?', [$qid]);
    if (!$row) {
        out(['ok' => false, 'message' => 'Question no longer exists.'], 410);
    }

    // ---- server-side clock ----
    // 2 extra seconds of slack for the round-trip; anything later is a timeout.
    $served  = (int) ($payload['served'] ?? time());
    $elapsed = max(0, time() - $served);
    $limit   = (int) ($payload['limit'] ?? ($row['time_limit'] ?: $level['seconds']));
    $expired = $elapsed > $limit + 2;

    if ($expired) {
        $choice = 0;
    }

    $isRight = ($choice > 0 && $choice === (int) $row['correct']);

    // ---- reward ----
    // Coins are only paid for the first N rounds of the day; after that the
    // quiz still runs (and still scores) but stops paying out.
    $coins = 0;
    if ($isRight && !empty($payload['paid'])) {
        $coins = (int) $level['reward'];
    }

    $marks = $isRight ? (int) $row['marks'] : 0;

    $res[] = [
        'q'      => $qid,
        'choice' => $choice,
        'ok'     => $isRight,
        'sec'    => min($elapsed, $limit),
        'coins'  => $coins,
        'marks'  => $marks,
    ];
    $payload['res'] = $res;
    unset($payload['served'], $payload['limit']);
    save_payload($sid, $payload);

    q('UPDATE quiz_questions SET times_asked = times_asked + 1,
                                 times_right = times_right + ?
        WHERE id = ?', [$isRight ? 1 : 0, $qid]);

    q('UPDATE quiz_sessions
          SET answered = answered + 1,
              correct  = correct + ?,
              marks_scored = marks_scored + ?,
              coins_earned = coins_earned + ?,
              xp_earned    = xp_earned + ?,
              time_taken   = time_taken + ?
        WHERE id = ?',
      [$isRight ? 1 : 0, $marks, $coins, $isRight ? (int) $level['xp'] : 0,
       min($elapsed, $limit), $sid]);

    if ($coins > 0) {
        add_coins($uid, $coins, 'quiz',
                  ucfirst($sess['difficulty']) . ' quiz — correct answer');
    }
    if ($isRight) {
        add_xp($uid, (int) $level['xp']);
    }

    $done = count($res) >= $total;

    out([
        'ok'          => true,
        'correct'     => $isRight,
        'expired'     => $expired,
        'answer'      => (int) $row['correct'],
        'explanation' => $row['explanation'],
        'coins'       => $coins,
        'marks'       => $marks,
        'done'        => $done,
    ]);

// -------------------------------------------------------------------
// The proctor caught something.
//
// The browser reports what it saw; the server decides what it costs.
// Each report is logged, the strike count lives in the database (not in
// JavaScript, where it could be reset), and once the allowance is spent
// the round is voided for good.
// -------------------------------------------------------------------
case 'violation':

    $kind = (string) ($body['kind'] ?? 'unknown');
    if (!preg_match('/^[a-z_]{1,40}$/', $kind)) {
        $kind = 'unknown';
    }

    insert_row('quiz_violations', [
        'session_id' => $sid,
        'user_id'    => $uid,
        'kind'       => $kind,
        'detail'     => mb_substr((string) ($body['detail'] ?? ''), 0, 190) ?: null,
        'ip_address' => client_ip(),
    ]);

    // Some detections are recorded but do not cost a strike — a stray Ctrl+A
    // is worth knowing about, not worth ending someone's round over.
    if (!empty($body['soft'])) {
        out(['ok' => true, 'voided' => false, 'logged' => true]);
    }

    $strikes = (int) $sess['strikes'] + 1;
    $limit   = max(0, (int) setting('quiz_strike_limit', '1'));

    if ($strikes > $limit) {
        q('UPDATE quiz_sessions
              SET status = "voided", strikes = ?, void_reason = ?, finished_at = NOW()
            WHERE id = ? AND status = "active"', [$strikes, $kind, $sid]);

        $sess = fetch_one('SELECT * FROM quiz_sessions WHERE id = ?', [$sid]);

        out([
            'ok'      => true,
            'voided'  => true,
            'strikes' => $strikes,
            'message' => 'Round cancelled — ' . void_label($kind) . '.',
            'summary' => summarise($sess, $total),
        ]);
    }

    q('UPDATE quiz_sessions SET strikes = ? WHERE id = ?', [$strikes, $sid]);

    out([
        'ok'        => true,
        'voided'    => false,
        'strikes'   => $strikes,
        'remaining' => $limit - $strikes + 1,
        'message'   => 'Warning — ' . void_label($kind) . '. The next one ends the round.',
    ]);

// -------------------------------------------------------------------
// Close the round out and pay the perfect-round bonus
// -------------------------------------------------------------------
case 'finish':

    if ($sess['status'] === 'finished') {
        out(['ok' => true, 'summary' => summarise($sess, $total)]);
    }

    $correct = (int) $sess['correct'];
    $bonus   = 0;

    if (!empty($payload['paid']) && $total > 0 && $correct === $total) {
        $bonus = (int) setting('quiz_perfect_bonus', '100');
        if ($bonus > 0) {
            add_coins($uid, $bonus, 'quiz',
                      'Perfect ' . $sess['difficulty'] . ' round — all ' . $total . ' correct');
        }
    }

    // ---------------------------------------------------------------
    // Level result
    //
    // The pass mark is measured in MARKS, not in answers, so a level made
    // of heavier questions is worth clearing carefully. Clearing a level
    // for the first time opens the next one and pays the clear bonus once.
    // ---------------------------------------------------------------
    $levelNo    = (int) ($sess['level_no'] ?? 0);
    $marksTotal = (int) ($sess['marks_total'] ?? 0);
    $marksGot   = (int) ($sess['marks_scored'] ?? 0);
    $passed     = false;
    $firstClear = false;
    $levelCoins = 0;

    if (quiz_level_mode() && $levelNo > 0) {
        [$passed, $firstClear] = quiz_save_level_result(
            $uid, $sess['difficulty'], $levelNo, $correct, $marksGot, $marksTotal);

        if ($firstClear && !empty($payload['paid'])) {
            $levelCoins = quiz_level_bonus();
            if ($levelCoins > 0) {
                add_coins($uid, $levelCoins, 'quiz',
                          ucfirst($sess['difficulty']) . ' level ' . $levelNo . ' cleared');
            }
        }
        q('UPDATE quiz_sessions SET passed = ? WHERE id = ?', [$passed ? 1 : 0, $sid]);
    }

    q('UPDATE quiz_sessions
          SET status = "finished", finished_at = NOW(), coins_earned = coins_earned + ?
        WHERE id = ?', [$bonus + $levelCoins, $sid]);

    $sess = fetch_one('SELECT * FROM quiz_sessions WHERE id = ?', [$sid]);

    $summary                = summarise($sess, $total);
    $summary['bonus']       = $bonus;
    $summary['level_bonus'] = $levelCoins;
    $summary['first_clear'] = $firstClear;
    $summary['paid']        = !empty($payload['paid']);
    $summary['balance']     = (int) fetch_col('SELECT coins FROM users WHERE id = ?', [$uid], 0);

    // where to go next
    if (quiz_level_mode() && $levelNo > 0) {
        $catId  = (int) ($payload['cat'] ?? 0);
        $diff   = $sess['difficulty'];
        $totalL = quiz_level_count($diff, $catId);
        $qs     = $catId ? '&cat=' . $catId : '';

        if ($passed && $levelNo < $totalL) {
            $summary['next_url']   = url('quiz-play.php?level=' . $diff . '&lv=' . ($levelNo + 1) . $qs);
            $summary['next_label'] = 'Level ' . ($levelNo + 1) . ' →';
        } elseif ($passed) {
            $chain = quiz_chain();
            $pos   = array_search($diff, $chain, true);
            $next  = ($pos !== false && isset($chain[$pos + 1])) ? $chain[$pos + 1] : '';
            if ($next !== '' && quiz_level_count($next, $catId) > 0) {
                $summary['next_url']   = url('quiz-play.php?level=' . $next . '&lv=1' . $qs);
                $summary['next_label'] = ucfirst($next) . ' — Level 1 →';
            }
        } else {
            $summary['next_url']   = url('quiz-play.php?level=' . $diff . '&lv=' . $levelNo . $qs);
            $summary['next_label'] = 'Try level ' . $levelNo . ' again';
        }
    }

    out(['ok' => true, 'summary' => $summary]);

default:
    out(['ok' => false, 'message' => 'Unknown action.'], 400);
}
