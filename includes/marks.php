<?php
/**
 * Marks editing.
 *
 * An admin can correct a game score or a quiz result — a round that was
 * wrongly voided, a marking mistake, a goodwill adjustment. Every change
 * goes through here so that two things always happen together: the row is
 * updated, and the change is written to `score_edits` with who did it, what
 * it was before, and why. Nothing is ever edited silently.
 *
 * Coin differences are settled through the normal coin ledger, so a
 * player's balance and their transaction history never drift apart.
 */

/** Write one field change to the audit log. */
function marks_log(string $kind, int $refId, ?int $userId, string $field,
                   $old, $new, string $reason, ?array $admin = null): void
{
    if (!has_table('score_edits')) {
        return;
    }

    insert_row('score_edits', [
        'admin_id'   => $admin['id']   ?? null,
        'admin_name' => $admin['name'] ?? null,
        'kind'       => $kind === 'quiz' ? 'quiz' : 'score',
        'ref_id'     => $refId,
        'user_id'    => $userId,
        'field'      => mb_substr($field, 0, 40),
        'old_value'  => mb_substr((string) $old, 0, 120),
        'new_value'  => mb_substr((string) $new, 0, 120),
        'reason'     => mb_substr($reason, 0, 190) ?: null,
    ]);
}

/**
 * Edit a game score row.
 *
 * $changes may contain: score, time_taken, moves, coins_earned, result.
 * Returns the number of fields that actually changed.
 */
function marks_edit_score(int $scoreId, array $changes, string $reason, ?array $admin = null): int
{
    $row = fetch_one('SELECT * FROM scores WHERE id = ?', [$scoreId]);
    if (!$row) {
        return 0;
    }

    $allowed = ['score', 'time_taken', 'moves', 'coins_earned', 'result'];
    $set     = [];
    $n       = 0;
    $uid     = (int) $row['user_id'];

    foreach ($allowed as $f) {
        if (!array_key_exists($f, $changes)) {
            continue;
        }

        $new = $f === 'result'
             ? (in_array($changes[$f], ['win', 'loss', 'incomplete'], true) ? $changes[$f] : $row[$f])
             : max(0, (int) $changes[$f]);

        if ((string) $new === (string) $row[$f]) {
            continue;
        }

        // coins are money: move the difference through the ledger
        if ($f === 'coins_earned') {
            $diff = (int) $new - (int) $row[$f];
            if ($diff !== 0) {
                add_coins($uid, $diff, 'admin_adjust',
                          'Score #' . $scoreId . ' corrected by admin');
            }
        }

        marks_log('score', $scoreId, $uid, $f, $row[$f], $new, $reason, $admin);
        $set[$f] = $new;
        $n++;
    }

    if ($set) {
        $set['edited_by'] = $admin['id'] ?? null;
        $set['edited_at'] = date('Y-m-d H:i:s');
        update_row('scores', $set, 'id = ?', [$scoreId]);
    }

    return $n;
}

/**
 * Edit a quiz round.
 *
 * $changes may contain: correct, answered, coins_earned, xp_earned, status.
 * Changing `correct` re-evaluates the level pass/fail, so a corrected round
 * can unlock the next level exactly as a fair one would have.
 */
function marks_edit_quiz(int $sessionId, array $changes, string $reason, ?array $admin = null): int
{
    $row = fetch_one('SELECT * FROM quiz_sessions WHERE id = ?', [$sessionId]);
    if (!$row) {
        return 0;
    }

    $allowed = ['correct', 'answered', 'coins_earned', 'xp_earned', 'status'];
    $set     = [];
    $n       = 0;
    $uid     = (int) $row['user_id'];

    foreach ($allowed as $f) {
        if (!array_key_exists($f, $changes)) {
            continue;
        }

        if ($f === 'status') {
            $new = in_array($changes[$f], ['active', 'finished', 'abandoned', 'voided'], true)
                 ? $changes[$f] : $row[$f];
        } else {
            $new = max(0, (int) $changes[$f]);
            if ($f === 'correct') {
                $new = min($new, (int) $row['total']);
            }
        }

        if ((string) $new === (string) $row[$f]) {
            continue;
        }

        if ($f === 'coins_earned') {
            $diff = (int) $new - (int) $row[$f];
            if ($diff !== 0) {
                add_coins($uid, $diff, 'admin_adjust',
                          'Quiz round #' . $sessionId . ' corrected by admin');
            }
        }
        if ($f === 'xp_earned') {
            $diff = (int) $new - (int) $row[$f];
            if ($diff > 0) {
                add_xp($uid, $diff);
            }
        }

        marks_log('quiz', $sessionId, $uid, $f, $row[$f], $new, $reason, $admin);
        $set[$f] = $new;
        $n++;
    }

    if (!$set) {
        return 0;
    }

    $set['marks_edited_by'] = $admin['id'] ?? null;
    $set['marks_edited_at'] = date('Y-m-d H:i:s');
    update_row('quiz_sessions', $set, 'id = ?', [$sessionId]);

    // a corrected mark re-decides the level
    if (isset($set['correct']) && function_exists('quiz_level_finish')
        && (int) $row['level_no'] > 0 && quiz_levels_ready()) {

        $res = quiz_level_finish(
            $uid,
            (int) ($row['category_id'] ?? 0),
            (string) $row['difficulty'],
            (int) $row['level_no'],
            (int) $set['correct'],
            (int) $row['total'],
            false                                  // no bonus payout on a correction
        );

        q('UPDATE quiz_sessions SET passed = ? WHERE id = ?',
          [$res['passed'] ? 1 : 0, $sessionId]);

        marks_log('quiz', $sessionId, $uid, 'level_result',
                  $row['passed'] ? 'passed' : 'failed',
                  $res['passed'] ? 'passed' : 'failed', $reason, $admin);
    }

    return $n;
}

/** The edit history for one row, newest first. */
function marks_history(string $kind, int $refId, int $limit = 30): array
{
    if (!has_table('score_edits')) {
        return [];
    }
    return fetch_all(
        'SELECT * FROM score_edits WHERE kind = ? AND ref_id = ?
          ORDER BY created_at DESC LIMIT ' . max(1, (int) $limit),
        [$kind === 'quiz' ? 'quiz' : 'score', $refId]
    );
}

/** Was this row touched by an admin? Used for the "edited" badge. */
function marks_edited(string $kind, int $refId): bool
{
    if (!has_table('score_edits')) {
        return false;
    }
    return (int) fetch_col(
        'SELECT COUNT(*) FROM score_edits WHERE kind = ? AND ref_id = ?',
        [$kind === 'quiz' ? 'quiz' : 'score', $refId], 0
    ) > 0;
}
