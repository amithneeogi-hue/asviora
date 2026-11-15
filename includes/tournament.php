<?php
/**
 * 3D CUBE DAILY TOURNAMENT  (v8)
 *
 * Daily (00:00-23:59 IST) ranking of completed 3D Cube solves by fastest
 * time. Admin distributes prizes (coins) to the top 3 via a button.
 */
if (!defined('ROOT_PATH')) { /* noop */ }

const CUBE_SLUG = 'cube3d';

/** Is the daily tournament switched on? */
function cube_tournament_on(): bool
{
    return setting('cube_tournament_enabled', '0') === '1';
}

/** Prize (coins) for a rank (1..3). */
function cube_prize(int $rank): int
{
    $k = 'cube_prize_' . ($rank === 1 ? '1st' : ($rank === 2 ? '2nd' : '3rd'));
    return max(0, (int) setting($k, '0'));
}

/** Min completed solves required to award 2nd/3rd. */
function cube_tournament_min_plays(): int
{
    return max(1, (int) setting('cube_tournament_min_plays', '10'));
}

/** Game id for the cube, or null. */
function cube_game_id(): ?int
{
    $id = fetch_col('SELECT id FROM games WHERE slug = ? LIMIT 1', [CUBE_SLUG], null);
    return $id === null ? null : (int) $id;
}

/** Today's date (IST) string YYYY-MM-DD. */
function cube_tournament_date(?string $d = null): string
{
    if ($d !== null && $d !== '') return $d;
    return date('Y-m-d');
}

/** Completed solves for a date window, best per user. */
function cube_tournament_leaderboard(string $date): array
{
    $gid = cube_game_id();
    if ($gid === null) return [];
    $start = $date . ' 00:00:00';
    $end   = $date . ' 23:59:59';
    // best (fastest) solve per user
    $rows = fetch_all(
        'SELECT s.user_id, u.username, u.name, u.avatar,
                MIN(s.time_taken) AS best_time,
                MIN(s.played_at)  AS first_at
           FROM scores s JOIN users u ON u.id = s.user_id
          WHERE s.game_id = ? AND s.result = "win"
            AND s.played_at >= ? AND s.played_at <= ?
          GROUP BY s.user_id, u.username, u.name, u.avatar
          ORDER BY best_time ASC, first_at ASC',
        [$gid, $start, $end]);
    $out = [];
    $rank = 0;
    foreach ($rows as $r) {
        $rank++;
        $out[] = [
            'rank'       => $rank,
            'user_id'    => (int) $r['user_id'],
            'username'   => $r['username'],
            'name'       => $r['name'],
            'avatar'     => $r['avatar'],
            'time_taken' => (int) $r['best_time'],
            'played_at'  => $r['first_at'],
        ];
    }
    return $out;
}

/** Count of completed solves for a date. */
function cube_tournament_play_count(string $date): int
{
    $gid = cube_game_id();
    if ($gid === null) return 0;
    return (int) fetch_col(
        'SELECT COUNT(*) FROM scores
          WHERE game_id = ? AND result = "win"
            AND played_at >= ? AND played_at <= ?',
        [$gid, $date . ' 00:00:00', $date . ' 23:59:59'], 0);
}

/** Result row for a date, or null. */
function cube_tournament_result(string $date): ?array
{
    return fetch_one('SELECT * FROM cube_tournament_results WHERE tournament_date = ?', [$date]);
}

/** Distribute prizes for a date. Returns [ok, message]. */
function cube_tournament_distribute(string $date, int $adminId): array
{
    if (!cube_tournament_on()) {
        return [false, 'Tournament is switched off.'];
    }
    $existing = cube_tournament_result($date);
    if ($existing && empty($existing['undone_at'])) {
        return [false, 'Already distributed for ' . $date . '.'];
    }

    $lb = cube_tournament_leaderboard($date);
    $plays = cube_tournament_play_count($date);
    if (!$lb) {
        return [false, 'No completed solves on ' . $date . '.'];
    }

    $minPlays = cube_tournament_min_plays();
    $award2nd3rd = $plays >= $minPlays;

    $r1 = $lb[0] ?? null;
    $r2 = ($award2nd3rd && isset($lb[1])) ? $lb[1] : null;
    $r3 = ($award2nd3rd && isset($lb[2])) ? $lb[2] : null;

    $p1 = $r1 ? cube_prize(1) : 0;
    $p2 = $r2 ? cube_prize(2) : 0;
    $p3 = $r3 ? cube_prize(3) : 0;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($r1 && $p1 > 0) {
            add_coins((int) $r1['user_id'], $p1, 'tournament', '3D Cube tournament 1st — ' . $date);
        }
        if ($r2 && $p2 > 0) {
            add_coins((int) $r2['user_id'], $p2, 'tournament', '3D Cube tournament 2nd — ' . $date);
        }
        if ($r3 && $p3 > 0) {
            add_coins((int) $r3['user_id'], $p3, 'tournament', '3D Cube tournament 3rd — ' . $date);
        }

        if ($existing) {
            // re-distribute after undo: update row
            q('UPDATE cube_tournament_results SET distributed_at = NOW(), distributed_by = ?,
                   total_plays = ?, rank1_user = ?, rank1_time = ?, rank1_prize = ?,
                   rank2_user = ?, rank2_time = ?, rank2_prize = ?,
                   rank3_user = ?, rank3_time = ?, rank3_prize = ?, undone_at = NULL, note = ?
               WHERE tournament_date = ?',
              [$adminId, $plays,
               $r1['user_id'] ?? null, $r1['time_taken'] ?? null, $p1,
               $r2['user_id'] ?? null, $r2['time_taken'] ?? null, $p2,
               $r3['user_id'] ?? null, $r3['time_taken'] ?? null, $p3,
               'Redistributed', $date]);
        } else {
            insert_row('cube_tournament_results', [
                'tournament_date' => $date,
                'distributed_at'  => date('Y-m-d H:i:s'),
                'distributed_by'  => $adminId,
                'total_plays'     => $plays,
                'rank1_user'      => $r1['user_id'] ?? null,
                'rank1_time'      => $r1['time_taken'] ?? null,
                'rank1_prize'     => $p1,
                'rank2_user'      => $r2['user_id'] ?? null,
                'rank2_time'      => $r2['time_taken'] ?? null,
                'rank2_prize'     => $p2,
                'rank3_user'      => $r3['user_id'] ?? null,
                'rank3_time'      => $r3['time_taken'] ?? null,
                'rank3_prize'     => $p3,
                'note'            => $award2nd3rd ? null : ('Only 1st awarded (plays ' . $plays . ' < ' . $minPlays . ')'),
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $msg = 'Distributed: 1st ' . ($r1 ? $r1['username'] . ' (' . num($p1) . ' coins)' : 'none');
    if ($r2) $msg .= ', 2nd ' . $r2['username'] . ' (' . num($p2) . ')';
    if ($r3) $msg .= ', 3rd ' . $r3['username'] . ' (' . num($p3) . ')';
    if (!$award2nd3rd) $msg .= ' (only 1st — ' . $plays . ' solves < ' . $minPlays . ')';
    return [true, $msg];
}

/** Undo a distribution within 24h. Returns [ok, message]. */
function cube_tournament_undo(string $date, int $adminId): array
{
    $row = cube_tournament_result($date);
    if (!$row || empty($row['distributed_at']) || !empty($row['undone_at'])) {
        return [false, 'Nothing to undo for ' . $date . '.'];
    }
    $distributed = strtotime((string) $row['distributed_at']);
    if (time() - $distributed > 24 * 3600) {
        return [false, 'Undo window (24h) has expired.'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // debit back
        foreach ([1, 2, 3] as $rk) {
            $uid = (int) ($row['rank' . $rk . '_user'] ?? 0);
            $pr  = (int) ($row['rank' . $rk . '_prize'] ?? 0);
            if ($uid > 0 && $pr > 0) {
                q('UPDATE users SET coins = GREATEST(0, coins - ?) WHERE id = ?', [$pr, $uid]);
                $bal = (int) fetch_col('SELECT coins FROM users WHERE id = ?', [$uid], 0);
                insert_row('coin_transactions', [
                    'user_id' => $uid, 'amount' => -$pr, 'balance' => $bal,
                    'type' => 'admin_adjust', 'note' => 'Undo 3D Cube tournament ' . $date,
                ]);
            }
        }
        q('UPDATE cube_tournament_results SET undone_at = NOW(), note = ? WHERE tournament_date = ?',
          ['Undone by admin #' . $adminId, $date]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return [true, 'Distribution for ' . $date . ' undone.'];
}