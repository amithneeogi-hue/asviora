<?php
/**
 * Mobile API v1 — router.
 *
 * One front controller, one place to see the whole contract. The Android
 * app talks only to this file; nothing else on the site is a public API.
 *
 *   GET  /api/v1/config                    what is switched on, app version
 *   POST /api/v1/auth/register             create an account, returns a token
 *   POST /api/v1/auth/login                username or email + password
 *   POST /api/v1/auth/logout               revoke this device's token
 *   GET  /api/v1/auth/me                   the signed-in player
 *
 *   GET  /api/v1/games                     the live catalogue
 *   GET  /api/v1/games/ticket              ?game=slug -> signed WebView URL
 *   GET  /api/v1/leaderboard               ?game=slug&period=today|week|month|all
 *
 *   GET  /api/v1/quiz/topics               quiz categories with counts
 *   GET  /api/v1/quiz/overview             the three ladders and progress
 *   GET  /api/v1/quiz/levels               ?difficulty=easy&cat=0  -> level map
 *   POST /api/v1/quiz/start                {difficulty, level, cat} -> session
 *   POST /api/v1/quiz/question             {session}
 *   POST /api/v1/quiz/answer               {session, choice}
 *   POST /api/v1/quiz/finish               {session}
 *
 *   GET  /api/v1/spin/status               allowance, prize table
 *   POST /api/v1/spin/play                 take a spin
 *
 *   GET  /api/v1/profile                   stats, wallet, recent rounds
 *   POST /api/v1/profile/update            name, bio
 *
 *   GET  /api/v1/exam                      govt exam — status, copy, countdown
 *   POST /api/v1/exam/notify               join the interest list
 *
 *   POST /api/v1/chess/find|create|join|state|move|resign|draw
 *
 * Routes work with or without URL rewriting: /api/v1/quiz/levels and
 * /api/v1/index.php?r=quiz/levels are the same request.
 */
require_once __DIR__ . '/bootstrap.php';
require_once ROOT_PATH . '/includes/chesslobby.php';

if (setting('api_enabled', '1') !== '1' || !module_on('mobile_api')) {
    api_fail('api_off', 'The app service is paused for maintenance.', 503);
}

// -------------------------------------------------------------------
// Work out the route
// -------------------------------------------------------------------
$route = trim((string) ($_GET['r'] ?? ''), '/');

if ($route === '') {
    // /api/v1/quiz/levels  ->  quiz/levels
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
    if (preg_match('#/api/v1/(.*)$#', $path, $m)) {
        $route = trim(preg_replace('#^index\.php/?#', '', $m[1]), '/');
    }
}
$route = strtolower(preg_replace('/[^a-z0-9\/_\-]/i', '', $route));
if ($route === '' || $route === 'index.php') {
    $route = 'config';
}

$POST = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

// ===================================================================
switch ($route) {

// -------------------------------------------------------------------
// What the app needs to know before anyone signs in
// -------------------------------------------------------------------
case 'config': {
    $arcade = module_on('games')
        ? (int) fetch_col('SELECT COUNT(*) FROM games WHERE is_active = 1', [], 0) : 0;

    // What games.php actually shows as a tile — quiz, spin and chess count
    // too, so the app's "N ways to play" matches the website and the admin
    // dashboard instead of counting arcade rows only. (v6)
    $arena = function_exists('arena_count') ? (int) arena_count() : $arcade;

    $examOn = function_exists('govt_exam_on') && govt_exam_on();

    api_ok([
        'site' => [
            'name'    => setting('site_name', 'Asviora Games'),
            'tagline' => setting('site_tagline', ''),
            'url'     => SITE_URL,
            'logo'    => logo_icon(192),
        ],
        'modules' => [
            'games'       => module_on('games'),
            'quiz'        => quiz_on(),
            'spin'        => spin_on(),
            'chess'       => module_on('chess'),
            'chess_online'=> chess_online_on(),
            'leaderboard' => module_on('leaderboard'),
            'blog'        => module_on('blog'),
            'govt_exam'   => $examOn,
        ],
        // Govt Competitive Exam — announced before it opens, so the app
        // needs the label and the status, not just an on/off. (v6)
        'govt_exam' => $examOn ? [
            'status'  => govt_exam_status(),
            'label'   => setting('govt_exam_label', 'Govt Exam'),
            'launch'  => govt_exam_launch(),
            'days'    => govt_exam_days_left(),
            'tagline' => setting('govt_exam_tagline', ''),
        ] : null,
        'quiz' => [
            'levels_enabled'    => quiz_levels_ready(),
            'per_level'         => (int) setting('quiz_questions_per_level', '10'),
            'pass_percent'      => (int) setting('quiz_pass_percent', '60'),
            'repeat_block_days' => (int) setting('quiz_repeat_block_days', '180'),
        ],
        'app' => [
            'min_version'    => (int) setting('app_min_version', '1'),
            'latest_version' => (int) setting('app_latest_version', '1'),
            'update_url'     => setting('app_update_url', ''),
        ],
        'counts' => ['games' => $arena, 'arcade' => $arcade],
    ]);
}

// -------------------------------------------------------------------
// AUTH
// -------------------------------------------------------------------
case 'auth/register': {
    if (!$POST) api_fail('method', 'POST only.', 405);
    api_rate_limit('register', 6);

    $name  = api_str('name');
    $uname = strtolower(api_str('username'));
    $email = strtolower(api_str('email'));
    $pass  = (string) api_param('password', '');
    $phone = api_str('phone');
    $ref   = strtoupper(api_str('referral'));

    if (mb_strlen($name) < 2)  api_fail('validation', 'Please give your name.', 422);
    if (!preg_match('/^[a-z0-9_]{3,20}$/', $uname)) {
        api_fail('validation', 'Username: 3-20 letters, numbers or underscore.', 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_fail('validation', 'That email address does not look right.', 422);
    }
    if (strlen($pass) < 6) api_fail('validation', 'Password needs at least 6 characters.', 422);

    if (fetch_one('SELECT id FROM users WHERE username = ?', [$uname])) {
        api_fail('taken', 'That username is already taken.', 409);
    }
    if (fetch_one('SELECT id FROM users WHERE email = ?', [$email])) {
        api_fail('taken', 'That email already has an account.', 409);
    }

    $refBy = null;
    if ($ref !== '') {
        $refBy = fetch_col('SELECT id FROM users WHERE referral_code = ?', [$ref], null);
    }

    $bonus = (int) setting('signup_bonus', '500');
    $uid = insert_row('users', [
        'name'          => mb_substr($name, 0, 100),
        'username'      => $uname,
        'email'         => $email,
        'phone'         => $phone ?: null,
        'password_hash' => password_hash($pass, PASSWORD_BCRYPT),
        'coins'         => $bonus,
        'referral_code' => strtoupper(substr(bin2hex(random_bytes(5)), 0, 8)),
        'referred_by'   => $refBy ? (int) $refBy : null,
    ]);

    insert_row('coin_transactions', [
        'user_id' => $uid, 'amount' => $bonus, 'balance' => $bonus,
        'type' => 'signup', 'note' => 'Welcome bonus (app)',
    ]);

    if ($refBy) {
        $rBonus = (int) setting('referral_bonus', '200');
        if ($rBonus > 0) {
            add_coins((int) $refBy, $rBonus, 'referral', 'Referral — ' . $uname . ' joined');
        }
    }

    $tok = api_issue_token($uid, api_str('device'), api_str('platform', 'android'),
                           api_str('app_version'));
    $u = fetch_one('SELECT * FROM users WHERE id = ?', [$uid]);

    api_ok(['user' => api_user_public($u)] + $tok, 201);
}

case 'auth/login': {
    if (!$POST) api_fail('method', 'POST only.', 405);
    api_rate_limit('login', 10);

    $id   = strtolower(api_str('login'));
    $pass = (string) api_param('password', '');

    if ($id === '' || $pass === '') {
        api_fail('validation', 'Enter your username and password.', 422);
    }

    $u = fetch_one('SELECT * FROM users WHERE username = ? OR email = ?', [$id, $id]);

    if (!$u || !password_verify($pass, $u['password_hash'])) {
        api_fail('bad_credentials', 'Those details do not match an account.', 401);
    }
    if ($u['status'] !== 'active') {
        api_fail('blocked', 'This account is ' . $u['status'] . '.', 403);
    }

    q('UPDATE users SET last_login = NOW() WHERE id = ?', [(int) $u['id']]);

    $tok = api_issue_token((int) $u['id'], api_str('device'),
                           api_str('platform', 'android'), api_str('app_version'));

    api_ok(['user' => api_user_public($u)] + $tok);
}

case 'auth/logout': {
    $u = api_require_user();
    q('UPDATE api_tokens SET revoked = 1 WHERE token_hash = ?',
      [hash('sha256', api_token_from_header())]);
    api_ok(['bye' => true]);
}

case 'auth/me': {
    $u = api_require_user();
    api_ok(['user' => api_user_public($u)]);
}

// -------------------------------------------------------------------
// GAMES + LEADERBOARD
// -------------------------------------------------------------------
case 'games': {
    api_require_module('games');
    api_ok(['games' => array_map('api_game_public', live_games())]);
}

/**
 * A signed URL the app can open in its WebView.
 *
 * Since the site put every playable page behind a login (v6.1), play.php
 * redirects a cookie-less WebView to login.php. The app asks for a ticket
 * instead: it is signed, it names one game, it expires in fifteen minutes,
 * and game-frame.php turns it into a real session for that WebView. The
 * bearer token itself never travels in a URL.
 */
case 'games/ticket': {
    api_require_module('games');
    $u = api_require_user();

    $slug = api_str('game');
    $game = fetch_one('SELECT * FROM games WHERE slug = ? AND is_active = 1', [$slug]);
    if (!$game) {
        api_fail('not_found', 'That game is not available.', 404);
    }

    api_ok([
        'game'     => api_game_public($game),
        'play_url' => app_frame_url((int) $u['id'], $game['slug']),
        'expires'  => 900,
    ]);
}

case 'leaderboard': {
    api_require_module('leaderboard');

    $slug   = api_str('game');
    $period = api_str('period', 'all');

    $since = [
        'today' => 'AND s.played_at >= CURDATE()',
        'week'  => 'AND s.played_at >= (NOW() - INTERVAL 7 DAY)',
        'month' => 'AND s.played_at >= (NOW() - INTERVAL 30 DAY)',
    ][$period] ?? '';

    $where = 'WHERE 1=1 ' . $since;
    $par   = [];
    if ($slug !== '') {
        $where .= ' AND g.slug = ?';
        $par[]  = $slug;
    }

    $rows = fetch_all(
        'SELECT u.id, u.username, u.name, u.avatar, u.level,
                MAX(s.score) AS best, SUM(s.score) AS total, COUNT(*) AS rounds
           FROM scores s
           JOIN users u ON u.id = s.user_id
           JOIN games g ON g.id = s.game_id ' . $where . '
          GROUP BY u.id, u.username, u.name, u.avatar, u.level
          ORDER BY ' . ($slug !== '' ? 'best' : 'total') . ' DESC
          LIMIT ' . (int) LEADERBOARD_LIMIT, $par);

    $out = [];
    foreach ($rows as $i => $r) {
        $out[] = [
            'rank'     => $i + 1,
            'user'     => api_user_public($r),
            'best'     => (int) $r['best'],
            'total'    => (int) $r['total'],
            'rounds'   => (int) $r['rounds'],
        ];
    }

    api_ok(['period' => $period, 'game' => $slug, 'rows' => $out]);
}

// -------------------------------------------------------------------
// QUIZ
// -------------------------------------------------------------------
case 'quiz/topics': {
    if (!quiz_on()) api_fail('module_off', 'The quiz is not available.', 403);

    $cats = fetch_all(
        'SELECT c.id, c.name, c.slug, c.icon,
                (SELECT COUNT(*) FROM quiz_questions q
                  WHERE q.category_id = c.id AND q.is_active = 1) AS questions
           FROM quiz_categories c WHERE c.is_active = 1
          ORDER BY c.sort_order, c.name');

    api_ok(['topics' => array_map(static function ($c) {
        return [
            'id'        => (int) $c['id'],
            'name'      => $c['name'],
            'slug'      => $c['slug'],
            'icon'      => html_entity_decode((string) $c['icon'], ENT_QUOTES, 'UTF-8'),
            'questions' => (int) $c['questions'],
        ];
    }, $cats)]);
}

case 'quiz/overview': {
    if (!quiz_on()) api_fail('module_off', 'The quiz is not available.', 403);
    $u   = api_require_user();
    $cat = api_int('cat');

    api_ok(['overview' => quiz_overview((int) $u['id'], $cat), 'cat' => $cat]);
}

case 'quiz/levels': {
    if (!quiz_on()) api_fail('module_off', 'The quiz is not available.', 403);
    $u    = api_require_user();
    $cat  = api_int('cat');
    $diff = api_str('difficulty', 'easy');
    if (!in_array($diff, quiz_difficulty_order(), true)) {
        $diff = 'easy';
    }

    api_ok([
        'difficulty' => $diff,
        'cat'        => $cat,
        'unlocked'   => quiz_difficulty_unlocked((int) $u['id'], $cat, $diff),
        'lock_note'  => quiz_difficulty_lock_reason((int) $u['id'], $cat, $diff),
        'config'     => quiz_level_cfg($cat, $diff),
        'levels'     => quiz_level_map((int) $u['id'], $cat, $diff),
    ]);
}

case 'quiz/start': {
    if (!$POST) api_fail('method', 'POST only.', 405);
    if (!quiz_on()) api_fail('module_off', 'The quiz is not available.', 403);

    $u   = api_require_user();
    $uid = (int) $u['id'];
    api_rate_limit('quiz_start', 20);

    $cat  = api_int('cat');
    $diff = api_str('difficulty', 'easy');
    if (!in_array($diff, quiz_difficulty_order(), true)) {
        $diff = 'easy';
    }

    $useLevels = quiz_levels_ready();
    $levelNo   = max(0, api_int('level'));
    $cfg       = quiz_level_cfg($cat, $diff);

    if ($useLevels) {
        if ($levelNo < 1) {
            $levelNo = quiz_next_level($uid, $cat, $diff);
        }
        if (!quiz_difficulty_unlocked($uid, $cat, $diff)) {
            api_fail('locked', quiz_difficulty_lock_reason($uid, $cat, $diff), 403);
        }
        if (!quiz_level_unlocked($uid, $cat, $diff, $levelNo)) {
            api_fail('locked', 'Clear level ' . max(1, $levelNo - 1) . ' first.', 403);
        }
    }

    // one live round at a time, exactly as on the website
    q('UPDATE quiz_sessions SET status = "voided", void_reason = "duplicate", finished_at = NOW()
        WHERE user_id = ? AND status = "active"', [$uid]);

    if ($useLevels) {
        $draw = quiz_level_questions($uid, $cat, $diff, $levelNo);
        $ids  = $draw['ids'];
        $recy = $draw['recycled'];
    } else {
        $sql = 'SELECT id FROM quiz_questions WHERE is_active = 1 AND difficulty = ?';
        $par = [$diff];
        if ($cat) { $sql .= ' AND category_id = ?'; $par[] = $cat; }
        $sql .= ' ORDER BY RAND() LIMIT ' . (int) quiz_round_size();
        $ids  = array_map('intval', array_column(fetch_all($sql, $par), 'id'));
        $recy = false;
    }

    if (!$ids) {
        api_fail('empty', 'There are no questions for that level yet.', 404);
    }

    $cap  = (int) setting('quiz_daily_rounds', '5');
    $done = (int) fetch_col(
        'SELECT COUNT(*) FROM quiz_sessions
          WHERE user_id = ? AND status = "finished" AND DATE(finished_at) = CURDATE()',
        [$uid], 0);
    $paid = ($cap <= 0) || ($done < $cap);

    $marks = (int) fetch_col(
        'SELECT COALESCE(SUM(marks),0) FROM quiz_questions WHERE id IN ('
        . implode(',', array_map('intval', $ids)) . ')', [], count($ids));

    $sid = insert_row('quiz_sessions', [
        'user_id'     => $uid,
        'category_id' => $cat ?: null,
        'difficulty'  => $diff,
        'level_no'    => $useLevels ? $levelNo : 0,
        'total'       => count($ids),
        'marks_total' => $marks,
        'payload'     => json_encode(['ids' => $ids, 'res' => [], 'paid' => $paid,
                                      'level' => $useLevels ? $levelNo : 0, 'cat' => $cat]),
        'status'      => 'active',
    ]);

    api_ok([
        'session'   => $sid,
        'level'     => $useLevels ? $levelNo : 0,
        'total'     => count($ids),
        'marks'     => $marks,
        'seconds'   => (int) $cfg['seconds'],
        'pass_mark' => (int) $cfg['pass_percent'],
        'paid'      => $paid,
        'recycled'  => $recy,
    ], 201);
}

case 'quiz/question':
case 'quiz/answer':
case 'quiz/finish': {
    if (!$POST) api_fail('method', 'POST only.', 405);
    if (!quiz_on()) api_fail('module_off', 'The quiz is not available.', 403);

    $u   = api_require_user();
    $uid = (int) $u['id'];
    $sid = api_int('session');

    $sess = fetch_one('SELECT * FROM quiz_sessions WHERE id = ? AND user_id = ?', [$sid, $uid]);
    if (!$sess) api_fail('not_found', 'That round does not exist.', 404);

    $payload = json_decode((string) $sess['payload'], true);
    if (!is_array($payload) || empty($payload['ids'])) {
        api_fail('corrupt', 'That round cannot be resumed. Start a new one.', 422);
    }

    $ids     = $payload['ids'];
    $res     = $payload['res'] ?? [];
    $total   = count($ids);
    $idx     = count($res);
    $catId   = (int) ($payload['cat'] ?? 0);
    $levelNo = (int) ($payload['level'] ?? 0);
    $diff    = (string) $sess['difficulty'];
    $cfg     = quiz_level_cfg($catId, $diff);

    $save = static function (int $sid, array $p): void {
        q('UPDATE quiz_sessions SET payload = ? WHERE id = ?', [json_encode($p), $sid]);
    };

    // ---- next question ------------------------------------------------
    if ($route === 'quiz/question') {
        if ($sess['status'] !== 'active') {
            api_fail('finished', 'That round is over.', 409);
        }
        if ($idx >= $total) {
            api_ok(['done' => true]);
        }

        $qid = (int) $ids[$idx];
        $row = fetch_one('SELECT id, question, option_a, option_b, option_c, option_d,
                                 image, marks, time_limit
                            FROM quiz_questions WHERE id = ?', [$qid]);
        if (!$row) {
            $res[] = ['q' => $qid, 'choice' => 0, 'ok' => false, 'sec' => 0, 'skipped' => true];
            $payload['res'] = $res;
            $save($sid, $payload);
            api_ok(['retry' => true]);
        }

        $seconds = (int) ($row['time_limit'] ?: $cfg['seconds']);
        $payload['served'] = time();
        $payload['clock']  = $seconds;
        $save($sid, $payload);

        api_ok([
            'index'    => $idx + 1,
            'total'    => $total,
            'level'    => $levelNo,
            'seconds'  => $seconds,
            'marks'    => (int) ($row['marks'] ?: 1),
            'question' => $row['question'],
            'image'    => $row['image'] ? UPLOAD_URL . '/quiz/' . rawurlencode($row['image']) : null,
            'options'  => array_values(array_filter(
                [$row['option_a'], $row['option_b'], $row['option_c'], $row['option_d']],
                static fn($o) => $o !== null && $o !== '')),
        ]);
    }

    // ---- grade an answer ----------------------------------------------
    if ($route === 'quiz/answer') {
        if ($sess['status'] !== 'active') {
            api_fail('finished', 'That round is over.', 409);
        }
        if ($idx >= $total) {
            api_ok(['done' => true]);
        }

        $qid    = (int) $ids[$idx];
        $choice = api_int('choice');
        $choice = ($choice >= 1 && $choice <= 4) ? $choice : 0;

        $row = fetch_one('SELECT * FROM quiz_questions WHERE id = ?', [$qid]);
        if (!$row) api_fail('gone', 'That question no longer exists.', 410);

        $served  = (int) ($payload['served'] ?? time());
        $limit   = (int) ($payload['clock'] ?? $cfg['seconds']);
        $elapsed = max(0, time() - $served);
        $expired = $elapsed > $limit + 3;             // a second more slack than the web
        if ($expired) $choice = 0;

        $right = ($choice > 0 && $choice === (int) $row['correct']);
        $marks = (int) ($row['marks'] ?: 1);
        $coins = ($right && !empty($payload['paid'])) ? (int) $cfg['reward'] : 0;

        $res[] = ['q' => $qid, 'choice' => $choice, 'ok' => $right,
                  'sec' => min($elapsed, $limit), 'coins' => $coins,
                  'marks' => $right ? $marks : 0];
        $payload['res'] = $res;
        unset($payload['served'], $payload['clock']);
        $save($sid, $payload);

        q('UPDATE quiz_questions SET times_asked = times_asked + 1,
                                     times_right = times_right + ? WHERE id = ?',
          [$right ? 1 : 0, $qid]);

        q('UPDATE quiz_sessions
              SET answered = answered + 1, correct = correct + ?,
                  marks_earned = marks_earned + ?, coins_earned = coins_earned + ?,
                  xp_earned = xp_earned + ?, time_taken = time_taken + ?
            WHERE id = ?',
          [$right ? 1 : 0, $right ? $marks : 0, $coins,
           $right ? (int) $cfg['xp'] : 0, min($elapsed, $limit), $sid]);

        if ($coins > 0) add_coins($uid, $coins, 'quiz', ucfirst($diff) . ' quiz — correct answer');
        if ($right)     add_xp($uid, (int) $cfg['xp']);

        quiz_history_add($uid, $qid, $sid, $diff, $levelNo, $right);

        api_ok([
            'correct'     => $right,
            'expired'     => $expired,
            'answer'      => (int) $row['correct'],
            'explanation' => $row['explanation'],
            'coins'       => $coins,
            'marks'       => $right ? $marks : 0,
            'done'        => count($res) >= $total,
        ]);
    }

    // ---- close the round ----------------------------------------------
    $correct = (int) $sess['correct'];
    $paid    = !empty($payload['paid']);
    $bonus   = 0;

    if ($sess['status'] === 'finished') {
        api_ok(['already' => true, 'correct' => $correct, 'total' => $total]);
    }

    if ($paid && $total > 0 && $correct === $total) {
        $bonus = (int) setting('quiz_perfect_bonus', '100');
        if ($bonus > 0) {
            add_coins($uid, $bonus, 'quiz', 'Perfect ' . $diff . ' round');
        }
    }

    $lvl = null;
    if ($levelNo > 0 && quiz_levels_ready()) {
        $lvl    = quiz_level_finish($uid, $catId, $diff, $levelNo, $correct, $total, $paid);
        $bonus += (int) $lvl['bonus'];
    }

    q('UPDATE quiz_sessions
          SET status = "finished", finished_at = NOW(),
              coins_earned = coins_earned + ?, passed = ?
        WHERE id = ?',
      [$bonus, $lvl ? ($lvl['passed'] ? 1 : 0) : 0, $sid]);

    $sess = fetch_one('SELECT * FROM quiz_sessions WHERE id = ?', [$sid]);

    api_ok([
        'correct'      => (int) $sess['correct'],
        'wrong'        => (int) $sess['answered'] - (int) $sess['correct'],
        'total'        => $total,
        'percent'      => $total ? (int) round($sess['correct'] / $total * 100) : 0,
        'marks'        => (int) $sess['marks_earned'],
        'marks_of'     => (int) $sess['marks_total'],
        'coins'        => (int) $sess['coins_earned'],
        'xp'           => (int) $sess['xp_earned'],
        'time'         => (int) $sess['time_taken'],
        'bonus'        => $bonus,
        'paid'         => $paid,
        'balance'      => (int) fetch_col('SELECT coins FROM users WHERE id = ?', [$uid], 0),
        'level'        => $levelNo,
        'level_passed' => $lvl ? (bool) $lvl['passed'] : null,
        'pass_mark'    => $lvl ? (int) $lvl['pass_mark'] : null,
        'stars'        => $lvl ? (int) $lvl['stars'] : null,
        'first_clear'  => $lvl ? (bool) $lvl['first_clear'] : null,
        'next_level'   => $lvl ? (int) $lvl['next_level'] : null,
        'next_unlocked'=> $lvl ? (bool) $lvl['next_unlocked'] : null,
        'ladder_done'  => $lvl ? (bool) $lvl['ladder_done'] : null,
    ]);
}

// -------------------------------------------------------------------
// SPIN
// -------------------------------------------------------------------
case 'spin/status': {
    if (!spin_on()) api_fail('module_off', 'The wheel is not available.', 403);
    $u = api_require_user();

    $segments = array_map(static function ($s) {
        return ['coins' => (int) $s['coins'], 'color' => (string) $s['color']];
    }, spin_segments());

    // v6 put cooldown / weekly / monthly caps inside spin_status(). When a
    // cooldown is what is holding the player back, counting down to midnight
    // is simply the wrong number, so 'wait' wins when it is set.
    $access = spin_status((int) $u['id']);
    $wait   = (int) ($access['wait'] ?? 0);

    api_ok([
        'segments' => $segments,
        'access'   => $access,
        'next_in'  => $wait > 0 ? $wait : seconds_to_midnight(),
        'note'     => setting('spin_limit_note', ''),
    ]);
}

case 'spin/play': {
    if (!$POST) api_fail('method', 'POST only.', 405);
    if (!spin_on()) api_fail('module_off', 'The wheel is not available.', 403);

    $u   = api_require_user();
    $uid = (int) $u['id'];
    api_rate_limit('spin', 12);

    $segments = spin_segments();
    if (!$segments) api_fail('unconfigured', 'The wheel is not set up yet.', 500);

    $lock = 'asviora_spin_' . $uid;
    fetch_col('SELECT GET_LOCK(?, 5)', [$lock], 0);

    $idx = 0; $coins = 0;
    try {
        $status = spin_status($uid);
        if (!$status['allowed']) {
            fetch_col('SELECT RELEASE_LOCK(?)', [$lock], 0);
            $wait = (int) ($status['wait'] ?? 0);
            api_fail('no_spins', $status['reason'], 409,
                     ['next_in'   => $wait > 0 ? $wait : seconds_to_midnight(),
                      'wait'      => $wait,
                      'remaining' => 0]);
        }

        $idx   = spin_pick($segments);
        $coins = (int) $segments[$idx]['coins'];

        insert_row('spin_results', [
            'user_id' => $uid, 'spin_date' => date('Y-m-d'),
            'segment' => $idx, 'coins' => $coins, 'ip_address' => client_ip(),
        ]);
        spin_consume($uid);
    } finally {
        fetch_col('SELECT RELEASE_LOCK(?)', [$lock], 0);
    }

    $balance = $coins > 0
        ? add_coins($uid, $coins, 'spin', 'Spin — segment ' . ($idx + 1))
        : (int) fetch_col('SELECT coins FROM users WHERE id = ?', [$uid], 0);

    $after = spin_status($uid);
    $wait  = (int) ($after['wait'] ?? 0);

    api_ok([
        'segment'   => $idx,
        'coins'     => $coins,
        'balance'   => $balance,
        'remaining' => (int) $after['remaining'],
        'access'    => $after,
        'wait'      => $wait,
        'next_in'   => $wait > 0 ? $wait : seconds_to_midnight(),
    ]);
}

// -------------------------------------------------------------------
// PROFILE
// -------------------------------------------------------------------
case 'profile': {
    $u   = api_require_user();
    $uid = (int) $u['id'];

    $stats = fetch_one(
        'SELECT COUNT(*) AS rounds, COALESCE(MAX(score),0) AS best,
                COALESCE(SUM(coins_earned),0) AS coins
           FROM scores WHERE user_id = ?', [$uid]);

    $recent = fetch_all(
        'SELECT s.score, s.result, s.coins_earned, s.played_at, g.title, g.slug
           FROM scores s JOIN games g ON g.id = s.game_id
          WHERE s.user_id = ? ORDER BY s.id DESC LIMIT 20', [$uid]);

    $wallet = fetch_all(
        'SELECT amount, balance, type, note, created_at FROM coin_transactions
          WHERE user_id = ? ORDER BY id DESC LIMIT 30', [$uid]);

    api_ok([
        'user'   => api_user_public($u),
        'stats'  => [
            'rounds'      => (int) $stats['rounds'],
            'best'        => (int) $stats['best'],
            'coins_won'   => (int) $stats['coins'],
            'quiz_levels' => quiz_levels_ready()
                ? (int) fetch_col('SELECT COUNT(*) FROM quiz_user_levels
                                    WHERE user_id = ? AND status = "passed"', [$uid], 0)
                : 0,
        ],
        'recent' => $recent,
        'wallet' => $wallet,
    ]);
}

case 'profile/update': {
    if (!$POST) api_fail('method', 'POST only.', 405);
    $u = api_require_user();

    $name = api_str('name');
    $bio  = api_str('bio');

    if ($name !== '' && mb_strlen($name) < 2) {
        api_fail('validation', 'That name is too short.', 422);
    }

    update_row('users', [
        'name' => $name !== '' ? mb_substr($name, 0, 100) : $u['name'],
        'bio'  => mb_substr($bio, 0, 255) ?: null,
    ], 'id = ?', [(int) $u['id']]);

    api_ok(['user' => api_user_public(
        fetch_one('SELECT * FROM users WHERE id = ?', [(int) $u['id']]))]);
}

// -------------------------------------------------------------------
// GOVT COMPETITIVE EXAM (v6) — announced before it opens
// -------------------------------------------------------------------
case 'exam': {
    if (!govt_exam_on()) {
        api_fail('module_off', 'That section is not available.', 403);
    }

    api_ok([
        'status'   => govt_exam_status(),
        'label'    => setting('govt_exam_label', 'Govt Exam'),
        'launch'   => govt_exam_launch(),
        'days'     => govt_exam_days_left(),
        'tagline'  => setting('govt_exam_tagline', ''),
        'blurb'    => setting('govt_exam_blurb', ''),
        'streams'  => govt_exam_streams(),
        'signups'  => govt_exam_signups(),
    ]);
}

case 'exam/notify': {
    if (!$POST) api_fail('method', 'POST only.', 405);
    if (!govt_exam_on()) {
        api_fail('module_off', 'That section is not available.', 403);
    }
    api_rate_limit('exam_notify', 6);

    // Signed in is not required — the website's form does not require it
    // either — but when the app does have a player, the row is tied to them.
    $me = api_user();

    [$ok, $msg] = govt_exam_join([
        'name'      => api_str('name', (string) ($me['name'] ?? '')),
        'email'     => api_str('email', (string) ($me['email'] ?? '')),
        'phone'     => api_str('phone'),
        'exam_pref' => api_str('exam_pref'),
        'note'      => api_str('note'),
    ]);

    if (!$ok) {
        api_fail('validation', $msg, 422);
    }

    // govt_exam_join() reads the PHP session for the player id, which an app
    // request does not have — so stamp it here instead.
    if ($me && has_table('exam_notify')) {
        q('UPDATE exam_notify SET user_id = ? WHERE email = ? AND user_id IS NULL',
          [(int) $me['id'], strtolower(api_str('email', (string) ($me['email'] ?? '')))]);
    }

    api_ok(['joined' => true, 'message' => $msg, 'signups' => govt_exam_signups()]);
}

// -------------------------------------------------------------------
// CHESS (online) — the same referee the website uses
// -------------------------------------------------------------------
case 'chess/current':
case 'chess/find':
case 'chess/create':
case 'chess/join':
case 'chess/state':
case 'chess/move':
case 'chess/resign':
case 'chess/draw':
case 'chess/cancel':
case 'chess/lobby': {
    if (!chess_online_on()) api_fail('module_off', 'Online chess is switched off.', 403);
    $u = api_require_user();

    // exactly the same referee the website talks to — one implementation,
    // two front doors, so the app and the browser can never disagree
    try {
        api_ok(chess_handle(substr($route, 6), (int) $u['id'], api_body()));
    } catch (ChessError $e) {
        api_fail($e->errorCode, $e->getMessage(), $e->getCode() ?: 400, $e->extra);
    }
}

// -------------------------------------------------------------------
default:
    api_fail('not_found', 'No such endpoint: ' . $route, 404);
}
