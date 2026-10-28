<?php
/**
 * Who is allowed to spin the wheel, and how often.
 *
 * Two modes, set in Admin -> Site Settings (or Admin -> Spin Access):
 *
 *   all       every signed-in player gets the default number of spins a day
 *   assigned  only players the admin has granted access can spin at all
 *
 * On top of that, each player can carry a personal allowance (more spins a
 * day than everyone else), one-off bonus spins, and a validity window.
 */

/** 'all' or 'assigned'. */
function spin_access_mode(): string
{
    return setting('spin_access_mode', 'all') === 'assigned' ? 'assigned' : 'all';
}

/** Default spins per day for players with no personal row. */
function spin_default_per_day(): int
{
    return max(0, (int) setting('spin_default_per_day', '1'));
}

/**
 * ---- Site-wide limits (Admin -> Daily Spin -> Spin limits) ----------
 * A player row on `spin_access` may override any of these; a NULL there
 * means "use the site value below". 0 always means "no limit".
 */

/** Minutes a player must wait between two spins. 0 = no wait. */
function spin_default_cooldown(): int
{
    return max(0, (int) setting('spin_cooldown_minutes', '0'));
}

/** Spins allowed per rolling 7 days. 0 = no cap. */
function spin_default_weekly_cap(): int
{
    return max(0, (int) setting('spin_weekly_cap', '0'));
}

/** Spins allowed per rolling 30 days. 0 = no cap. */
function spin_default_monthly_cap(): int
{
    return max(0, (int) setting('spin_monthly_cap', '0'));
}

/** Does the spin_access table carry the v6 limit columns yet? */
function spin_limits_installed(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    if (!has_table('spin_access')) {
        return $ok = false;
    }
    try {
        $c = fetch_col(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "spin_access"
                AND COLUMN_NAME = "cooldown_minutes"', [], 0);
        return $ok = ((int) $c === 1);
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/** Spins taken in the last N days (rolling window, not calendar). */
function spin_used_since(int $userId, int $days): int
{
    if (!has_table('spin_results') || $days <= 0) {
        return 0;
    }
    return (int) fetch_col(
        'SELECT COUNT(*) FROM spin_results
          WHERE user_id = ? AND spin_date > (CURDATE() - INTERVAL ' . (int) $days . ' DAY)',
        [$userId], 0
    );
}

/**
 * Seconds still to wait before this player may spin again. 0 = ready.
 *
 * The whole sum is done inside MySQL on purpose. Reading `created_at`
 * out and comparing it with PHP's time() mixes two clocks — MySQL on a
 * shared host is usually UTC while PHP here is Asia/Kolkata — and a
 * five-and-a-half hour gap makes every cooldown look long expired.
 * includes/db.php now aligns the two, and doing the arithmetic in one
 * place keeps this correct even on a host that refuses that.
 */
function spin_cooldown_left(int $userId, int $cooldownMinutes): int
{
    if ($cooldownMinutes <= 0 || !has_table('spin_results')) {
        return 0;
    }
    $left = fetch_col(
        'SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(),
                             created_at + INTERVAL ' . (int) $cooldownMinutes . ' MINUTE))
           FROM spin_results WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [$userId], 0
    );
    return max(0, (int) $left);
}

/** "4m 20s" / "1h 05m" — for the message shown to the player. */
function spin_wait_text(int $seconds): string
{
    if ($seconds >= 3600) {
        return sprintf('%dh %02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
    if ($seconds >= 60) {
        return sprintf('%dm %02ds', intdiv($seconds, 60), $seconds % 60);
    }
    return $seconds . 's';
}

/** The player's access row, or null if they have never been given one. */
function spin_access_row(int $userId): ?array
{
    if (!has_table('spin_access')) {
        return null;
    }
    return fetch_one('SELECT * FROM spin_access WHERE user_id = ?', [$userId]);
}

/** How many spins the player has already used today. */
function spin_used_today(int $userId): int
{
    if (!has_table('spin_results')) {
        return 0;
    }
    return (int) fetch_col(
        'SELECT COUNT(*) FROM spin_results WHERE user_id = ? AND spin_date = CURDATE()',
        [$userId], 0
    );
}

/**
 * The whole picture for one player.
 *
 * ['allowed' => bool, 'reason' => string, 'per_day' => int, 'used' => int,
 *  'bonus' => int, 'remaining' => int, 'granted' => bool]
 */
function spin_status(int $userId): array
{
    $row     = spin_access_row($userId);
    $mode    = spin_access_mode();
    $today   = date('Y-m-d');
    $granted = $row !== null;

    $out = [
        'allowed'      => true,
        'reason'       => '',
        'per_day'      => spin_default_per_day(),
        'used'         => spin_used_today($userId),
        'bonus'        => 0,
        'remaining'    => 0,
        'granted'      => $granted,
        'mode'         => $mode,
        'note'         => $row['note'] ?? '',
        // --- limits (v6) ---
        'cooldown'     => spin_default_cooldown(),
        'wait'         => 0,
        'weekly_cap'   => spin_default_weekly_cap(),
        'weekly_used'  => 0,
        'monthly_cap'  => spin_default_monthly_cap(),
        'monthly_used' => 0,
    ];

    // --- assigned mode: no row, no spin ---------------------------------
    if ($mode === 'assigned' && !$granted) {
        $out['allowed'] = false;
        $out['reason']  = 'The wheel is invitation-only right now. An admin has to switch it on for your account.';
        return $out;
    }

    if ($granted) {
        if ((int) $row['is_allowed'] !== 1) {
            $out['allowed'] = false;
            $out['reason']  = 'Spin access is switched off for your account.';
            return $out;
        }
        if (!empty($row['valid_from']) && $row['valid_from'] > $today) {
            $out['allowed'] = false;
            $out['reason']  = 'Your spin access starts on ' . fdate($row['valid_from']) . '.';
            return $out;
        }
        if (!empty($row['valid_until']) && $row['valid_until'] < $today) {
            $out['allowed'] = false;
            $out['reason']  = 'Your spin access ended on ' . fdate($row['valid_until']) . '.';
            return $out;
        }

        $out['per_day'] = max(0, (int) $row['spins_per_day']);
        $out['bonus']   = max(0, (int) $row['bonus_spins']);

        // per-player overrides of the site limits; NULL = follow the site
        if (spin_limits_installed()) {
            if ($row['cooldown_minutes'] !== null) {
                $out['cooldown'] = max(0, (int) $row['cooldown_minutes']);
            }
            if ($row['weekly_cap'] !== null) {
                $out['weekly_cap'] = max(0, (int) $row['weekly_cap']);
            }
            if ($row['monthly_cap'] !== null) {
                $out['monthly_cap'] = max(0, (int) $row['monthly_cap']);
            }
        }
    }

    $out['remaining'] = max(0, $out['per_day'] - $out['used']) + $out['bonus'];

    if ($out['remaining'] <= 0) {
        $out['allowed'] = false;
        $out['reason']  = $out['per_day'] > 0
            ? 'You have used all ' . $out['per_day'] . ' of today\'s spins. Come back tomorrow.'
            : 'No spins are allocated to your account.';
        return $out;
    }

    // ---- weekly cap ----------------------------------------------------
    if ($out['weekly_cap'] > 0) {
        $out['weekly_used'] = spin_used_since($userId, 7);
        if ($out['weekly_used'] >= $out['weekly_cap']) {
            $out['allowed']   = false;
            $out['remaining'] = 0;
            $out['reason']    = 'You have reached this week\'s limit of '
                              . $out['weekly_cap'] . ' spins.';
            return $out;
        }
        $out['remaining'] = min($out['remaining'],
                                $out['weekly_cap'] - $out['weekly_used']);
    }

    // ---- monthly cap ---------------------------------------------------
    if ($out['monthly_cap'] > 0) {
        $out['monthly_used'] = spin_used_since($userId, 30);
        if ($out['monthly_used'] >= $out['monthly_cap']) {
            $out['allowed']   = false;
            $out['remaining'] = 0;
            $out['reason']    = 'You have reached this month\'s limit of '
                              . $out['monthly_cap'] . ' spins.';
            return $out;
        }
        $out['remaining'] = min($out['remaining'],
                                $out['monthly_cap'] - $out['monthly_used']);
    }

    // ---- cooldown between spins ---------------------------------------
    if ($out['cooldown'] > 0) {
        $out['wait'] = spin_cooldown_left($userId, $out['cooldown']);
        if ($out['wait'] > 0) {
            $out['allowed'] = false;
            $out['reason']  = 'Another spin in ' . spin_wait_text($out['wait']) . '.';
            return $out;
        }
    }

    return $out;
}

/** Shorthand: may this player spin right now? */
function spin_can(int $userId): bool
{
    return spin_status($userId)['allowed'];
}

/**
 * Book one spin. Call this straight after a spin is recorded so that a
 * bonus spin is burnt rather than the daily allowance, when one is due.
 */
function spin_consume(int $userId): void
{
    if (!has_table('spin_access')) {
        return;
    }

    $row = spin_access_row($userId);
    if (!$row || (int) $row['bonus_spins'] <= 0) {
        return;
    }

    // the daily allowance is counted from spin_results; a bonus spin is
    // only burnt once that allowance is already spent
    $used = spin_used_today($userId);          // includes the spin just taken
    if ($used > max(0, (int) $row['spins_per_day'])) {
        q('UPDATE spin_access SET bonus_spins = GREATEST(bonus_spins - 1, 0) WHERE user_id = ?',
          [$userId]);
    }
}

/** Create or update a player's access row. */
function spin_access_set(int $userId, array $data, ?int $adminId = null): void
{
    if (!has_table('spin_access')) {
        return;
    }

    $fields = [
        'is_allowed'    => isset($data['is_allowed']) ? (int) (bool) $data['is_allowed'] : 1,
        'spins_per_day' => max(0, (int) ($data['spins_per_day'] ?? spin_default_per_day())),
        'bonus_spins'   => max(0, (int) ($data['bonus_spins'] ?? 0)),
        'valid_from'    => $data['valid_from'] ?: null,
        'valid_until'   => $data['valid_until'] ?: null,
        'note'          => mb_substr((string) ($data['note'] ?? ''), 0, 190) ?: null,
        'granted_by'    => $adminId,
    ];

    // v6 limit overrides — only written once the columns exist. An empty
    // string means "follow the site setting", so it is stored as NULL.
    if (spin_limits_installed()) {
        foreach (['cooldown_minutes', 'weekly_cap', 'monthly_cap'] as $k) {
            if (array_key_exists($k, $data)) {
                $v = $data[$k];
                $fields[$k] = ($v === null || $v === '') ? null : max(0, (int) $v);
            }
        }
    }

    if (spin_access_row($userId)) {
        update_row('spin_access', $fields, 'user_id = ?', [$userId]);
    } else {
        $fields['user_id'] = $userId;
        insert_row('spin_access', $fields);
    }
}

/** Remove a player's personal row — they fall back to the site default. */
function spin_access_clear(int $userId): void
{
    if (has_table('spin_access')) {
        q('DELETE FROM spin_access WHERE user_id = ?', [$userId]);
    }
}
