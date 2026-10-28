<?php
/**
 * Quiz level engine.
 * ==================================================================
 *
 * The question bank is cut into levels. With the level size set to 10,
 * a bank of 300 questions is 30 levels; add 40 more questions and it
 * becomes 34 levels on its own. The size, the pass mark and the bonus
 * are admin-controlled per topic and per difficulty.
 *
 * Two rules shape which questions a player actually sees.
 *
 *  1. **Every player gets their own cut of the bank.** Each account has
 *     a random `quiz_seed`. The bank is ordered by MD5(question id +
 *     seed) before it is sliced into levels, so my level 3 and your
 *     level 3 are different questions drawn from the same bank. No two
 *     players are handed the same set unless they share a seed, which
 *     they never do.
 *
 *  2. **A question a player has already answered stays away.** Every
 *     served question is written to `quiz_question_history`; anything
 *     seen inside the block window (default 180 days, admin-set) is
 *     filtered out of the draw and replaced from the unseen pool. Only
 *     if the bank runs dry does the engine fall back to the questions
 *     seen longest ago, so a round is always playable.
 *
 * Progression is a ladder: level N+1 opens when level N is passed, and
 * Mid opens only when every Easy level is passed, Hard only when every
 * Mid level is passed.
 */

/** The difficulties in ladder order. */
function quiz_difficulty_order(): array
{
    return ['easy', 'mid', 'hard'];
}

/** Is the level system installed? */
function quiz_levels_ready(): bool
{
    return has_table('quiz_user_levels') && has_table('quiz_level_config');
}

// ---------------------------------------------------------------
// Per-player shuffle seed
// ---------------------------------------------------------------

/**
 * The player's private shuffle key. Created on first use and never
 * changed afterwards, so their level map stays stable between visits.
 */
function quiz_seed(int $userId): string
{
    static $cache = [];

    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    $seed = (string) fetch_col('SELECT quiz_seed FROM users WHERE id = ?', [$userId], '');

    if ($seed === '' || strlen($seed) < 8) {
        $seed = substr(bin2hex(random_bytes(8)), 0, 16);
        q('UPDATE users SET quiz_seed = ? WHERE id = ?', [$seed, $userId]);
    }

    return $cache[$userId] = $seed;
}

// ---------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------

/**
 * Level rules for a topic + difficulty. A topic-specific row wins; if
 * there is none, the global row (category_id IS NULL) is used; if the
 * table is missing entirely, the settings defaults are used.
 */
function quiz_level_cfg(int $catId, string $diff): array
{
    static $cache = [];
    $key = $catId . ':' . $diff;

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $base = [
        'questions_per_level' => max(1, (int) setting('quiz_questions_per_level', '10')),
        'pass_percent'        => max(1, min(100, (int) setting('quiz_pass_percent', '60'))),
        'max_levels'          => 0,
        'time_seconds'        => null,
        'reward_coins'        => null,
        'level_clear_bonus'   => max(0, (int) setting('quiz_level_clear_bonus', '50')),
        'is_active'           => 1,
    ];

    if (quiz_levels_ready()) {
        $row = null;
        if ($catId > 0) {
            $row = fetch_one(
                'SELECT * FROM quiz_level_config WHERE category_id = ? AND difficulty = ?',
                [$catId, $diff]
            );
        }
        if (!$row) {
            $row = fetch_one(
                'SELECT * FROM quiz_level_config WHERE category_id IS NULL AND difficulty = ?',
                [$diff]
            );
        }
        if ($row) {
            $base['questions_per_level'] = max(1, (int) $row['questions_per_level']);
            $base['pass_percent']        = max(1, min(100, (int) $row['pass_percent']));
            $base['max_levels']          = max(0, (int) $row['max_levels']);
            $base['time_seconds']        = $row['time_seconds'] !== null ? (int) $row['time_seconds'] : null;
            $base['reward_coins']        = $row['reward_coins'] !== null ? (int) $row['reward_coins'] : null;
            $base['level_clear_bonus']   = max(0, (int) $row['level_clear_bonus']);
            $base['is_active']           = (int) $row['is_active'];
        }
    }

    // fold in the per-difficulty defaults so callers get one flat array
    $lv = quiz_level($diff);
    $base['label']   = $lv['label'];
    $base['icon']    = $lv['icon'];
    $base['blurb']   = $lv['blurb'];
    $base['xp']      = $lv['xp'];
    $base['seconds'] = $base['time_seconds'] ?? (int) $lv['seconds'];
    $base['reward']  = $base['reward_coins'] ?? (int) $lv['reward'];

    return $cache[$key] = $base;
}

/** How many live questions the bank holds for this topic + difficulty. */
function quiz_bank_count(int $catId, string $diff): int
{
    $sql = 'SELECT COUNT(*) FROM quiz_questions WHERE is_active = 1 AND difficulty = ?';
    $par = [$diff];
    if ($catId > 0) {
        $sql .= ' AND category_id = ?';
        $par[] = $catId;
    }
    return (int) fetch_col($sql, $par, 0);
}

/**
 * How many levels the bank produces.
 *   300 questions, level size 10  ->  30 levels
 * A partly-filled last level still counts, so 305 questions is 31 levels.
 */
function quiz_level_total(int $catId, string $diff): int
{
    $cfg  = quiz_level_cfg($catId, $diff);
    $bank = quiz_bank_count($catId, $diff);

    if ($bank <= 0) {
        return 0;
    }

    $n = (int) ceil($bank / $cfg['questions_per_level']);

    if ($cfg['max_levels'] > 0) {
        $n = min($n, $cfg['max_levels']);
    }
    return max(0, $n);
}

// ---------------------------------------------------------------
// Progress
// ---------------------------------------------------------------

/** The player's rows for one ladder, keyed by level number. */
function quiz_progress(int $userId, int $catId, string $diff): array
{
    if (!quiz_levels_ready()) {
        return [];
    }

    $out = [];
    foreach (fetch_all(
        'SELECT * FROM quiz_user_levels
          WHERE user_id = ? AND category_id = ? AND difficulty = ?
          ORDER BY level_no',
        [$userId, $catId, $diff]) as $r) {
        $out[(int) $r['level_no']] = $r;
    }
    return $out;
}

/** How many levels of this ladder the player has passed. */
function quiz_passed_count(int $userId, int $catId, string $diff): int
{
    if (!quiz_levels_ready()) {
        return 0;
    }
    return (int) fetch_col(
        'SELECT COUNT(*) FROM quiz_user_levels
          WHERE user_id = ? AND category_id = ? AND difficulty = ? AND status = "passed"',
        [$userId, $catId, $diff], 0
    );
}

/**
 * Is this difficulty open to the player?
 *
 * Easy is always open. Mid opens when every Easy level has been passed,
 * Hard when every Mid level has been passed. Switching
 * `quiz_unlock_next_difficulty` off in settings opens all three.
 */
function quiz_difficulty_unlocked(int $userId, int $catId, string $diff): bool
{
    if (setting('quiz_unlock_next_difficulty', '1') !== '1') {
        return true;
    }

    $order = quiz_difficulty_order();
    $pos   = array_search($diff, $order, true);

    if ($pos === false || $pos === 0) {
        return true;
    }

    $prev  = $order[$pos - 1];
    $total = quiz_level_total($catId, $prev);

    // a previous difficulty with no questions cannot block anything
    if ($total <= 0) {
        return true;
    }

    return quiz_passed_count($userId, $catId, $prev) >= $total;
}

/** Why a difficulty is locked, in words the player can act on. */
function quiz_difficulty_lock_reason(int $userId, int $catId, string $diff): string
{
    $order = quiz_difficulty_order();
    $pos   = array_search($diff, $order, true);
    if ($pos === false || $pos === 0) {
        return '';
    }

    $prev  = $order[$pos - 1];
    $cfg   = quiz_level_cfg($catId, $prev);
    $total = quiz_level_total($catId, $prev);
    $done  = quiz_passed_count($userId, $catId, $prev);
    $left  = max(0, $total - $done);

    return 'Clear all ' . $total . ' ' . $cfg['label'] . ' levels first — '
         . $left . ' to go.';
}

/**
 * Is one level open? Level 1 of an open difficulty always is; after
 * that a level opens when the level before it has been passed.
 */
function quiz_level_unlocked(int $userId, int $catId, string $diff, int $level): bool
{
    if ($level < 1 || $level > quiz_level_total($catId, $diff)) {
        return false;
    }
    if (!quiz_difficulty_unlocked($userId, $catId, $diff)) {
        return false;
    }
    if ($level === 1) {
        return true;
    }
    if (!quiz_levels_ready()) {
        return true;
    }

    return (int) fetch_col(
        'SELECT COUNT(*) FROM quiz_user_levels
          WHERE user_id = ? AND category_id = ? AND difficulty = ?
            AND level_no = ? AND status = "passed"',
        [$userId, $catId, $diff, $level - 1], 0
    ) > 0;
}

/** The lowest level the player has not passed yet — where "Continue" goes. */
function quiz_next_level(int $userId, int $catId, string $diff): int
{
    $total = quiz_level_total($catId, $diff);
    if ($total <= 0) {
        return 0;
    }
    $passed = quiz_passed_count($userId, $catId, $diff);
    return min($total, $passed + 1);
}

/**
 * The whole ladder for one difficulty, ready for the level map:
 * one entry per level with its state, best score and stars.
 */
function quiz_level_map(int $userId, int $catId, string $diff): array
{
    $total    = quiz_level_total($catId, $diff);
    $cfg      = quiz_level_cfg($catId, $diff);
    $rows     = quiz_progress($userId, $catId, $diff);
    $bank     = quiz_bank_count($catId, $diff);
    $unlocked = quiz_difficulty_unlocked($userId, $catId, $diff);
    $open     = true;                       // level 1 is open when the difficulty is
    $map      = [];

    for ($n = 1; $n <= $total; $n++) {
        $row    = $rows[$n] ?? null;
        $passed = $row && $row['status'] === 'passed';

        // how many questions this level actually holds (last one may be short)
        $size = min(
            $cfg['questions_per_level'],
            max(0, $bank - ($n - 1) * $cfg['questions_per_level'])
        );

        $map[] = [
            'level'     => $n,
            'size'      => $size,
            'state'     => !$unlocked ? 'locked'
                         : ($passed ? 'passed' : ($open ? 'open' : 'locked')),
            'attempts'  => $row ? (int) $row['attempts'] : 0,
            'best'      => $row ? (int) $row['best_correct'] : 0,
            'best_of'   => $row ? (int) $row['best_total'] : $size,
            'percent'   => $row ? (int) $row['best_percent'] : 0,
            'stars'     => $row ? (int) $row['stars'] : 0,
            'passed_at' => $row['passed_at'] ?? null,
        ];

        // the next level is open only if this one is done
        $open = $passed;
    }

    return $map;
}

// ---------------------------------------------------------------
// Drawing the questions for a level
// ---------------------------------------------------------------

/**
 * Question ids for one attempt at one level.
 *
 * Returns ['ids' => int[], 'recycled' => bool, 'fresh' => int].
 * `recycled` is true when the bank could not fill the level with
 * questions the player has not seen recently.
 */
function quiz_level_questions(int $userId, int $catId, string $diff, int $level): array
{
    $cfg   = quiz_level_cfg($catId, $diff);
    $size  = $cfg['questions_per_level'];
    $seed  = quiz_seed($userId);
    $days  = max(0, (int) setting('quiz_repeat_block_days', '180'));
    $off   = max(0, ($level - 1) * $size);

    $where  = 'is_active = 1 AND difficulty = ?';
    $whereQ = 'q.is_active = 1 AND q.difficulty = ?';   // same filter, aliased
    $par    = [$diff];
    if ($catId > 0) {
        $where  .= ' AND category_id = ?';
        $whereQ .= ' AND q.category_id = ?';
        $par[]   = $catId;
    }

    // --- the player's own cut of the bank, sliced at this level ---------
    $slice = array_map('intval', array_column(fetch_all(
        "SELECT id FROM quiz_questions
          WHERE $where
          ORDER BY MD5(CONCAT(id, ?)), id
          LIMIT $size OFFSET $off",
        array_merge($par, [$seed])
    ), 'id'));

    // --- drop anything served to this player inside the block window ----
    $blocked = [];
    if ($days > 0 && has_table('quiz_question_history')) {
        $blocked = array_map('intval', array_column(fetch_all(
            'SELECT DISTINCT question_id AS id FROM quiz_question_history
              WHERE user_id = ? AND seen_at >= (NOW() - INTERVAL ? DAY)',
            [$userId, $days]
        ), 'id'));
    }
    $blockedSet = array_flip($blocked);

    $ids = array_values(array_filter($slice, static fn($id) => !isset($blockedSet[$id])));
    $fresh = count($ids);

    // --- top up from the rest of the bank, still unseen ------------------
    if (count($ids) < $size) {
        $need    = $size - count($ids);
        $exclude = array_unique(array_merge($slice, $blocked));
        $notIn   = '';
        $par2    = $par;

        if ($exclude) {
            $notIn = ' AND id NOT IN (' . implode(',', array_map('intval', $exclude)) . ')';
        }

        $top = array_map('intval', array_column(fetch_all(
            "SELECT id FROM quiz_questions
              WHERE $where $notIn
              ORDER BY MD5(CONCAT(id, ?, 'top')), id
              LIMIT $need",
            array_merge($par2, [$seed])
        ), 'id'));

        $ids   = array_merge($ids, $top);
        $fresh = count($ids);
    }

    // --- last resort: reuse what was seen longest ago --------------------
    $recycled = false;
    if (count($ids) < $size) {
        $need    = $size - count($ids);
        $have    = $ids;
        $notIn   = $have
            ? ' AND q.id NOT IN (' . implode(',', array_map('intval', $have)) . ')'
            : '';

        $histJoin = has_table('quiz_question_history')
            ? 'LEFT JOIN quiz_question_history h ON h.question_id = q.id AND h.user_id = ?'
            : '';
        $histSel  = has_table('quiz_question_history')
            ? "COALESCE(MAX(h.seen_at), '1970-01-01 00:00:00')"
            : "'1970-01-01 00:00:00'";
        $histPar  = has_table('quiz_question_history') ? [$userId] : [];

        $old = array_map('intval', array_column(fetch_all(
            "SELECT q.id, $histSel AS last_seen
               FROM quiz_questions q
               $histJoin
              WHERE $whereQ $notIn
              GROUP BY q.id
              ORDER BY last_seen ASC, MD5(CONCAT(q.id, ?)) ASC
              LIMIT $need",
            array_merge($histPar, $par, [$seed])
        ), 'id'));

        if ($old) {
            $ids      = array_merge($ids, $old);
            $recycled = true;
        }
    }

    // --- final in-round order: shuffled per attempt ----------------------
    shuffle($ids);

    return [
        'ids'      => array_values(array_map('intval', $ids)),
        'recycled' => $recycled,
        'fresh'    => $fresh,
    ];
}

// ---------------------------------------------------------------
// Recording what happened
// ---------------------------------------------------------------

/** Write one served question into the player's history. */
function quiz_history_add(int $userId, int $questionId, ?int $sessionId,
                          string $diff, int $level, bool $correct): void
{
    if (!has_table('quiz_question_history')) {
        return;
    }
    q('INSERT INTO quiz_question_history
         (user_id, question_id, session_id, difficulty, level_no, was_correct)
       VALUES (?,?,?,?,?,?)',
      [$userId, $questionId, $sessionId, $diff, $level, $correct ? 1 : 0]);
}

/** Stars for a percentage: 3 for perfect, 2 for 80+, 1 for a pass. */
function quiz_stars(int $percent, int $passMark): int
{
    if ($percent >= 100) return 3;
    if ($percent >= 80)  return 2;
    if ($percent >= $passMark) return 1;
    return 0;
}

/**
 * Close a level attempt: store the result, decide pass/fail, unlock the
 * next level and pay the clear bonus the first time it is passed.
 *
 * Returns a summary array for the finish screen.
 */
function quiz_level_finish(int $userId, int $catId, string $diff, int $level,
                           int $correct, int $total, bool $payable = true): array
{
    $cfg     = quiz_level_cfg($catId, $diff);
    $percent = $total > 0 ? (int) round($correct / $total * 100) : 0;
    $passed  = $percent >= $cfg['pass_percent'];
    $stars   = quiz_stars($percent, $cfg['pass_percent']);
    $bonus   = 0;
    $first   = false;

    if (!quiz_levels_ready() || $level < 1) {
        return [
            'passed'       => $passed,
            'percent'      => $percent,
            'pass_mark'    => $cfg['pass_percent'],
            'stars'        => $stars,
            'bonus'        => 0,
            'first_clear'  => false,
            'next_level'   => 0,
            'next_unlocked'=> false,
            'ladder_done'  => false,
        ];
    }

    $row = fetch_one(
        'SELECT * FROM quiz_user_levels
          WHERE user_id = ? AND category_id = ? AND difficulty = ? AND level_no = ?',
        [$userId, $catId, $diff, $level]
    );

    $wasPassed = $row && $row['status'] === 'passed';

    if (!$row) {
        insert_row('quiz_user_levels', [
            'user_id'      => $userId,
            'category_id'  => $catId,
            'difficulty'   => $diff,
            'level_no'     => $level,
            'status'       => $passed ? 'passed' : 'unlocked',
            'attempts'     => 1,
            'best_correct' => $correct,
            'best_total'   => $total,
            'best_percent' => $percent,
            'stars'        => $stars,
            'passed_at'    => $passed ? date('Y-m-d H:i:s') : null,
        ]);
    } else {
        $best        = max((int) $row['best_correct'], $correct);
        $bestPercent = max((int) $row['best_percent'], $percent);
        $bestStars   = max((int) $row['stars'], $stars);

        q('UPDATE quiz_user_levels
              SET attempts = attempts + 1,
                  best_correct = ?, best_total = ?, best_percent = ?, stars = ?,
                  status = ?,
                  passed_at = COALESCE(passed_at, ?)
            WHERE id = ?',
          [$best, max((int) $row['best_total'], $total), $bestPercent, $bestStars,
           ($wasPassed || $passed) ? 'passed' : 'unlocked',
           $passed ? date('Y-m-d H:i:s') : null,
           (int) $row['id']]);
    }

    // clear bonus is paid once, the first time the level is passed
    if ($passed && !$wasPassed) {
        $first = true;
        if ($payable && $cfg['level_clear_bonus'] > 0) {
            $bonus = (int) $cfg['level_clear_bonus'];
            add_coins($userId, $bonus, 'quiz',
                      ucfirst($diff) . ' level ' . $level . ' cleared');
        }
    }

    $total_levels = quiz_level_total($catId, $diff);
    $next         = $level + 1;
    $ladderDone   = quiz_passed_count($userId, $catId, $diff) >= $total_levels;

    // open the next level so it shows as playable straight away
    if ($passed && $next <= $total_levels) {
        q('INSERT IGNORE INTO quiz_user_levels
             (user_id, category_id, difficulty, level_no, status)
           VALUES (?,?,?,?, "unlocked")',
          [$userId, $catId, $diff, $next]);
    }

    return [
        'passed'        => $passed,
        'percent'       => $percent,
        'pass_mark'     => $cfg['pass_percent'],
        'stars'         => $stars,
        'bonus'         => $bonus,
        'first_clear'   => $first,
        'next_level'    => $next <= $total_levels ? $next : 0,
        'next_unlocked' => $passed && $next <= $total_levels,
        'ladder_done'   => $ladderDone,
        'total_levels'  => $total_levels,
    ];
}

/**
 * A one-line summary of a player's standing across the whole quiz —
 * used on the lobby, the dashboard and in the mobile API.
 */
function quiz_overview(int $userId, int $catId = 0): array
{
    $out = ['difficulties' => [], 'passed' => 0, 'total' => 0];

    foreach (quiz_difficulty_order() as $d) {
        $total  = quiz_level_total($catId, $d);
        $passed = quiz_passed_count($userId, $catId, $d);
        $cfg    = quiz_level_cfg($catId, $d);

        $out['difficulties'][$d] = [
            'key'        => $d,
            'label'      => $cfg['label'],
            'icon'       => $cfg['icon'],
            'blurb'      => $cfg['blurb'],
            'levels'     => $total,
            'passed'     => $passed,
            'percent'    => $total > 0 ? (int) round($passed / $total * 100) : 0,
            'unlocked'   => quiz_difficulty_unlocked($userId, $catId, $d),
            'lock_note'  => quiz_difficulty_unlocked($userId, $catId, $d)
                            ? '' : quiz_difficulty_lock_reason($userId, $catId, $d),
            'next_level' => quiz_next_level($userId, $catId, $d),
            'per_level'  => $cfg['questions_per_level'],
            'pass_mark'  => $cfg['pass_percent'],
            'seconds'    => $cfg['seconds'],
            'reward'     => $cfg['reward'],
            'bank'       => quiz_bank_count($catId, $d),
        ];

        $out['passed'] += $passed;
        $out['total']  += $total;
    }

    return $out;
}
