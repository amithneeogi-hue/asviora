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

// v7 — registration fee / paid plan (answers 402 with a pay_url)
json_require_game_access('quiz');

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

$ids     = $payload['ids'];
$res     = $payload['res'] ?? [];
$catId   = (int) ($payload['cat']   ?? (int) ($sess['category_id'] ?? 0));
$levelNo = (int) ($payload['level'] ?? (int) ($sess['level_no'] ?? 0));
$diff    = (string) $sess['difficulty'];

// the level config carries any per-topic overrides of the clock and reward
$level = quiz_level_cfg($catId, $diff);

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
    $pct     = $total > 0 ? round($correct / $total * 100) : 0;
    return [
        'correct' => $correct,
        'wrong'   => (int) $sess['answered'] - $correct,
        'total'   => $total,
        'percent' => $pct,
        'coins'   => (int) $sess['coins_earned'],
        'xp'      => (int) $sess['xp_earned'],
        'time'    => (int) $sess['time_taken'],
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

    // a question can carry its own clock; otherwise the level's applies
    $seconds = (int) ($row['time_limit'] ?: $level['seconds']);

    // stamp the serve time — this is what the answer is measured against
    $payload['served'] = time();
    $payload['clock']  = $seconds;
    save_payload($sid, $payload);

    $options = array_values(array_filter([
        $row['option_a'], $row['option_b'], $row['option_c'], $row['option_d'],
    ], static fn($o) => $o !== null && $o !== ''));

    out([
        'ok'       => true,
        'index'    => $idx + 1,
        'total'    => $total,
        'level'    => $levelNo,
        'seconds'  => $seconds,
        'marks'    => (int) ($row['marks'] ?: 1),
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
    $limit   = (int) ($payload['clock'] ?? $row['time_limit'] ?: $level['seconds']);
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

    $marks = (int) ($row['marks'] ?: 1);

    $res[] = [
        'q'      => $qid,
        'choice' => $choice,
        'ok'     => $isRight,
        'sec'    => min($elapsed, $limit),
        'coins'  => $coins,
        'marks'  => $isRight ? $marks : 0,
    ];
    $payload['res'] = $res;
    unset($payload['served'], $payload['clock']);
    save_payload($sid, $payload);

    q('UPDATE quiz_questions SET times_asked = times_asked + 1,
                                 times_right = times_right + ?
        WHERE id = ?', [$isRight ? 1 : 0, $qid]);

    q('UPDATE quiz_sessions
          SET answered = answered + 1,
              correct  = correct + ?,
              marks_earned = marks_earned + ?,
              coins_earned = coins_earned + ?,
              xp_earned    = xp_earned + ?,
              time_taken   = time_taken + ?
        WHERE id = ?',
      [$isRight ? 1 : 0, $isRight ? $marks : 0, $coins,
       $isRight ? (int) $level['xp'] : 0, min($elapsed, $limit), $sid]);

    // Remember that this player has now seen this question. This is the
    // record the no-repeat window is measured against — it is written the
    // moment the question is answered, not at the end of the round, so a
    // round abandoned halfway still counts as "seen".
    quiz_history_add($uid, $qid, $sid, $diff, $levelNo, $isRight);

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
        'marks'       => $isRight ? $marks : 0,
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
    $paid    = !empty($payload['paid']);

    if ($paid && $total > 0 && $correct === $total) {
        $bonus = (int) setting('quiz_perfect_bonus', '100');
        if ($bonus > 0) {
            add_coins($uid, $bonus, 'quiz',
                      'Perfect ' . $sess['difficulty'] . ' round — all ' . $total . ' correct');
        }
    }

    // -----------------------------------------------------------------
    // Close the level: decide pass/fail, unlock the next one, pay the
    // clear bonus the first time it is beaten.
    // -----------------------------------------------------------------
    $lvl = null;
    if ($levelNo > 0 && quiz_levels_ready()) {
        $lvl    = quiz_level_finish($uid, $catId, $diff, $levelNo, $correct, $total, $paid);
        $bonus += (int) $lvl['bonus'];
    }

    q('UPDATE quiz_sessions
          SET status = "finished", finished_at = NOW(),
              coins_earned = coins_earned + ?,
              passed = ?
        WHERE id = ?',
      [$bonus, $lvl ? ($lvl['passed'] ? 1 : 0) : 0, $sid]);

    $sess = fetch_one('SELECT * FROM quiz_sessions WHERE id = ?', [$sid]);

    $summary            = summarise($sess, $total);
    $summary['bonus']   = $bonus;
    $summary['paid']    = $paid;
    $summary['marks']   = (int) $sess['marks_earned'];
    $summary['marks_of']= (int) $sess['marks_total'];
    $summary['balance'] = (int) fetch_col('SELECT coins FROM users WHERE id = ?', [$uid], 0);

    if ($lvl) {
        $summary['level']         = $levelNo;
        $summary['level_passed']  = (bool) $lvl['passed'];
        $summary['pass_mark']     = (int) $lvl['pass_mark'];
        $summary['stars']         = (int) $lvl['stars'];
        $summary['first_clear']   = (bool) $lvl['first_clear'];
        $summary['next_level']    = (int) $lvl['next_level'];
        $summary['next_unlocked'] = (bool) $lvl['next_unlocked'];
        $summary['ladder_done']   = (bool) $lvl['ladder_done'];
        $summary['total_levels']  = (int) $lvl['total_levels'];
    }

    out(['ok' => true, 'summary' => $summary]);

default:
    out(['ok' => false, 'message' => 'Unknown action.'], 400);
}
