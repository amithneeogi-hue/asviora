<?php
/**
 * QUIZ LEVEL ENGINE
 * ===================================================================
 * Everything the level system needs, in one place.
 *
 * How the levels work
 * -------------------
 * A level is a *round of N questions*, not a fixed folder of questions.
 * The bank decides how many levels a difficulty has:
 *
 *      300 active Easy questions  /  10 questions per level  =  30 levels
 *
 * Both numbers are admin-editable (Admin -> Quiz Levels), and each
 * difficulty can also be pinned to a fixed number of levels.
 *
 * Because a level is a *count* and not a fixed set, two players sitting on
 * Level 1 are dealt two different question sets — the set is drawn at the
 * moment the round starts, at random, from everything that player has not
 * already seen. That is also what makes the repeat rule work: a question
 * served to a player is written to `quiz_seen` and is not served to that
 * same player again until `quiz_repeat_months` have passed (default 6).
 *
 * Unlock chain
 * ------------
 * Level 1 is open. Level n opens when level n-1 has been cleared. When
 * every Easy level is cleared, Mid opens; when every Mid level is cleared,
 * Hard opens. "Cleared" means scoring at least `quiz_pass_percent` of the
 * marks on offer in that round.
 */

/** Difficulty keys in the order they unlock. */
function quiz_chain(): array
{
    return ['easy', 'mid', 'hard'];
}

/** Is the level system switched on? (Off = the old free-play rounds.) */
function quiz_level_mode(): bool
{
    return setting('quiz_level_mode', '1') === '1';
}

/** Questions in one level. */
function quiz_per_level(): int
{
    return max(1, (int) setting('quiz_questions_per_level', '10'));
}

/** Percentage of the marks on offer needed to clear a level. */
function quiz_pass_percent(): int
{
    return min(100, max(1, (int) setting('quiz_pass_percent', '60')));
}

/** Months a question stays "used up" for the player who saw it. */
function quiz_repeat_months(): int
{
    return max(0, (int) setting('quiz_repeat_months', '6'));
}

/** Coins paid the first time a level is cleared. */
function quiz_level_bonus(): int
{
    return max(0, (int) setting('quiz_level_bonus', '50'));
}

/** Active questions available at a difficulty (optionally inside one topic). */
function quiz_bank_count(string $difficulty, int $catId = 0): int
{
    $sql    = 'SELECT COUNT(*) FROM quiz_questions WHERE is_active = 1 AND difficulty = ?';
    $params = [$difficulty];
    if ($catId > 0) {
        $sql     .= ' AND category_id = ?';
        $params[] = $catId;
    }
    return (int) fetch_col($sql, $params, 0);
}

/**
 * How many levels a difficulty has.
 * The admin can pin a number; 0 means "work it out from the bank".
 */
function quiz_level_count(string $difficulty, int $catId = 0): int
{
    $pinned = (int) setting('quiz_levels_' . $difficulty, '0');
    if ($pinned > 0) {
        return $pinned;
    }
    return (int) floor(quiz_bank_count($difficulty, $catId) / quiz_per_level());
}

/** Every level row a player has for one difficulty, keyed by level number. */
function quiz_progress(int $userId, string $difficulty): array
{
    if (!$userId || !has_table('quiz_level_progress')) {
        return [];
    }
    $out = [];
    foreach (fetch_all(
        'SELECT * FROM quiz_level_progress WHERE user_id = ? AND difficulty = ?',
        [$userId, $difficulty]) as $r) {
        $out[(int) $r['level_no']] = $r;
    }
    return $out;
}

/** How many levels of a difficulty the player has cleared. */
function quiz_cleared_count(int $userId, string $difficulty): int
{
    if (!$userId || !has_table('quiz_level_progress')) {
        return 0;
    }
    return (int) fetch_col(
        'SELECT COUNT(*) FROM quiz_level_progress
          WHERE user_id = ? AND difficulty = ? AND passed = 1',
        [$userId, $difficulty], 0);
}

/**
 * Is a whole difficulty open to this player?
 * Easy always is. Mid needs every Easy level cleared, Hard every Mid level.
 */
function quiz_difficulty_open(int $userId, string $difficulty, int $catId = 0): bool
{
    if (!quiz_level_mode()) {
        return true;
    }
    $chain = quiz_chain();
    $pos   = array_search($difficulty, $chain, true);
    if ($pos === false || $pos === 0) {
        return true;
    }
    $prev  = $chain[$pos - 1];
    $need  = quiz_level_count($prev, $catId);
    if ($need <= 0) {
        return true;             // nothing to clear at the level below
    }
    return quiz_cleared_count($userId, $prev) >= $need;
}

/** The first level of a difficulty the player has not cleared yet. */
function quiz_next_level(int $userId, string $difficulty): int
{
    $prog  = quiz_progress($userId, $difficulty);
    $level = 1;
    while (!empty($prog[$level]['passed'])) {
        $level++;
    }
    return $level;
}

/** May this player start this exact level right now? */
function quiz_level_open(int $userId, string $difficulty, int $level, int $catId = 0): bool
{
    if (!quiz_level_mode()) {
        return true;
    }
    if ($level < 1 || $level > quiz_level_count($difficulty, $catId)) {
        return false;
    }
    if (!quiz_difficulty_open($userId, $difficulty, $catId)) {
        return false;
    }
    // an already cleared level may be replayed for practice
    return $level <= quiz_next_level($userId, $difficulty);
}

/**
 * Deal one round of questions to a player.
 *
 * Order of preference:
 *   1. questions this player has never been served, at random;
 *   2. if that is not enough, the ones they saw longest ago, oldest first —
 *      but only ones outside the repeat window;
 *   3. if the bank still cannot fill a round, whatever is left, oldest seen
 *      first, so the round can still be played.
 *
 * @return array{ids:int[], fresh:int, recycled:int}
 */
function quiz_deal(int $userId, string $difficulty, int $catId, int $limit): array
{
    $limit  = max(1, $limit);
    $params = [$userId, $difficulty];
    $catSql = '';
    if ($catId > 0) {
        $catSql   = ' AND q.category_id = ?';
        $params[] = $catId;
    }

    // ---- 1. never seen by this player -------------------------------
    $sql = 'SELECT q.id FROM quiz_questions q
             LEFT JOIN quiz_seen s ON s.question_id = q.id AND s.user_id = ?
            WHERE q.is_active = 1 AND q.difficulty = ?' . $catSql . '
              AND s.question_id IS NULL
            ORDER BY RAND() LIMIT ' . (int) $limit;

    $ids   = array_map('intval', array_column(fetch_all($sql, $params), 'id'));
    $fresh = count($ids);

    // ---- 2. seen, but long enough ago -------------------------------
    if ($fresh < $limit) {
        $need   = $limit - $fresh;
        $months = quiz_repeat_months();
        $p2     = [$userId, $difficulty];
        $c2     = '';
        if ($catId > 0) {
            $c2   = ' AND q.category_id = ?';
            $p2[] = $catId;
        }
        $notIn = $ids ? ' AND q.id NOT IN (' . implode(',', $ids) . ')' : '';
        $sql2  = 'SELECT q.id FROM quiz_questions q
                    JOIN quiz_seen s ON s.question_id = q.id AND s.user_id = ?
                   WHERE q.is_active = 1 AND q.difficulty = ?' . $c2 . $notIn . '
                     AND s.seen_at < DATE_SUB(NOW(), INTERVAL ' . (int) $months . ' MONTH)
                   ORDER BY s.seen_at ASC LIMIT ' . (int) $need;
        foreach (fetch_all($sql2, $p2) as $r) {
            $ids[] = (int) $r['id'];
        }
    }

    // ---- 3. last resort: the oldest seen, whatever their age ---------
    if (count($ids) < $limit) {
        $need = $limit - count($ids);
        $p3   = [$userId, $difficulty];
        $c3   = '';
        if ($catId > 0) {
            $c3   = ' AND q.category_id = ?';
            $p3[] = $catId;
        }
        $notIn = $ids ? ' AND q.id NOT IN (' . implode(',', $ids) . ')' : '';
        $sql3  = 'SELECT q.id FROM quiz_questions q
                    JOIN quiz_seen s ON s.question_id = q.id AND s.user_id = ?
                   WHERE q.is_active = 1 AND q.difficulty = ?' . $c3 . $notIn . '
                   ORDER BY s.seen_at ASC LIMIT ' . (int) $need;
        foreach (fetch_all($sql3, $p3) as $r) {
            $ids[] = (int) $r['id'];
        }
    }

    shuffle($ids);

    return [
        'ids'      => $ids,
        'fresh'    => $fresh,
        'recycled' => max(0, count($ids) - $fresh),
    ];
}

/** How many unseen questions this player still has at a difficulty. */
function quiz_unseen_count(int $userId, string $difficulty, int $catId = 0): int
{
    if (!has_table('quiz_seen')) {
        return quiz_bank_count($difficulty, $catId);
    }
    $params = [$userId, $difficulty];
    $cat    = '';
    if ($catId > 0) {
        $cat      = ' AND q.category_id = ?';
        $params[] = $catId;
    }
    return (int) fetch_col(
        'SELECT COUNT(*) FROM quiz_questions q
          LEFT JOIN quiz_seen s ON s.question_id = q.id AND s.user_id = ?
         WHERE q.is_active = 1 AND q.difficulty = ?' . $cat . '
           AND s.question_id IS NULL', $params, 0);
}

/**
 * Burn the questions for this player.
 *
 * Written when the round is dealt, so walking out of a round does not hand
 * the questions back. ON DUPLICATE refreshes the timestamp for a question
 * that came back round after the repeat window.
 */
function quiz_mark_seen(int $userId, array $ids, int $sessionId = 0): void
{
    if (!$ids || !has_table('quiz_seen')) {
        return;
    }
    $rows = [];
    $args = [];
    foreach ($ids as $id) {
        $rows[] = '(?,?,?)';
        $args[] = $userId;
        $args[] = (int) $id;
        $args[] = $sessionId ?: null;
    }
    q('INSERT INTO quiz_seen (user_id, question_id, session_id) VALUES '
      . implode(',', $rows)
      . ' ON DUPLICATE KEY UPDATE seen_at = NOW(), session_id = VALUES(session_id)', $args);
}

/** Total marks a set of questions is worth. */
function quiz_marks_for(array $ids): int
{
    if (!$ids) {
        return 0;
    }
    $in = implode(',', array_map('intval', $ids));
    return (int) fetch_col("SELECT COALESCE(SUM(marks),0) FROM quiz_questions WHERE id IN ($in)", [], 0);
}

/**
 * Write the outcome of a level attempt.
 * Returns [passed, firstClear] so the caller can pay the clear bonus once.
 */
function quiz_save_level_result(
    int $userId, string $difficulty, int $level,
    int $correct, int $marksScored, int $marksTotal
): array {
    if ($level < 1 || !has_table('quiz_level_progress')) {
        return [false, false];
    }

    $percent = $marksTotal > 0 ? (int) round($marksScored / $marksTotal * 100) : 0;
    $passed  = $percent >= quiz_pass_percent();

    $row = fetch_one(
        'SELECT * FROM quiz_level_progress
          WHERE user_id = ? AND difficulty = ? AND level_no = ?',
        [$userId, $difficulty, $level]);

    $firstClear = $passed && (!$row || !$row['passed']);

    if (!$row) {
        insert_row('quiz_level_progress', [
            'user_id'      => $userId,
            'difficulty'   => $difficulty,
            'level_no'     => $level,
            'attempts'     => 1,
            'best_correct' => $correct,
            'best_marks'   => $marksScored,
            'best_percent' => $percent,
            'passed'       => $passed ? 1 : 0,
            'passed_at'    => $passed ? date('Y-m-d H:i:s') : null,
        ]);
    } else {
        q('UPDATE quiz_level_progress
              SET attempts     = attempts + 1,
                  best_correct = GREATEST(best_correct, ?),
                  best_marks   = GREATEST(best_marks, ?),
                  best_percent = GREATEST(best_percent, ?),
                  passed       = GREATEST(passed, ?),
                  passed_at    = COALESCE(passed_at, ?)
            WHERE id = ?',
          [$correct, $marksScored, $percent, $passed ? 1 : 0,
           $passed ? date('Y-m-d H:i:s') : null, (int) $row['id']]);
    }

    return [$passed, $firstClear];
}

/** A short human summary of where a player is, for the lobby header. */
function quiz_player_stage(int $userId, int $catId = 0): array
{
    foreach (quiz_chain() as $d) {
        $total = quiz_level_count($d, $catId);
        if ($total <= 0) {
            continue;
        }
        $done = quiz_cleared_count($userId, $d);
        if ($done < $total) {
            return ['difficulty' => $d, 'level' => min($done + 1, $total),
                    'done' => $done, 'total' => $total];
        }
    }
    $last = 'hard';
    return ['difficulty' => $last, 'level' => quiz_level_count($last, $catId),
            'done' => quiz_cleared_count($userId, $last),
            'total' => quiz_level_count($last, $catId)];
}

// ===================================================================
// Daily spin access
// ===================================================================

/**
 * May this player use the Daily Spin?
 * Site-wide mode is either "all" or "assigned"; the per-player setting
 * (Admin -> Players) overrides it in both directions.
 */
function spin_allowed(?array $user): bool
{
    if (!$user) {
        return false;
    }
    $own = $user['spin_access'] ?? 'default';
    if ($own === 'blocked') {
        return false;
    }
    if ($own === 'allowed') {
        return true;
    }
    return setting('spin_access_mode', 'all') !== 'assigned';
}
