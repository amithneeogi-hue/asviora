<?php
/**
 * Module switchboard.
 *
 * Every part of the site that can be taken live or hidden has a row in
 * `modules`. Games additionally keep their own `games.is_active` flag, so a
 * single game can be hidden without hiding the arcade — and hiding the
 * `games` module hides the whole arcade in one click.
 *
 * Nothing here throws if the table has not been migrated yet: an install
 * that is still on schema v3 simply behaves as it did before, with
 * everything switched on.
 */

/** All module rows, keyed by their `mkey`. Cached for the request. */
function modules_all(bool $reload = false): array
{
    static $rows = null;

    if ($rows !== null && !$reload) {
        return $rows;
    }

    $rows = [];
    if (!has_table('modules')) {
        return $rows;
    }

    foreach (fetch_all('SELECT * FROM modules ORDER BY sort_order, label') as $r) {
        $rows[$r['mkey']] = $r;
    }
    return $rows;
}

/**
 * Is this module live?
 *
 * An unknown key is treated as live, so adding a `module_on('something')`
 * check to a page never blanks that page before the row exists.
 */
function module_on(string $key): bool
{
    $all = modules_all();
    if (!isset($all[$key])) {
        return true;
    }
    return (int) $all[$key]['is_active'] === 1;
}

/** Should this module show up in the navigation? Hidden modules never do. */
function module_in_nav(string $key): bool
{
    $all = modules_all();
    if (!isset($all[$key])) {
        return true;
    }
    return (int) $all[$key]['is_active'] === 1 && (int) $all[$key]['nav_visible'] === 1;
}

/** Human label for a module, for flash messages and the admin screen. */
function module_label(string $key): string
{
    $all = modules_all();
    return $all[$key]['label'] ?? ucfirst(str_replace('_', ' ', $key));
}

/**
 * Send the visitor away from a module that is switched off.
 * Call it at the very top of the page the module owns.
 */
function require_module(string $key, string $back = 'index.php'): void
{
    if (module_on($key)) {
        return;
    }
    flash(module_label($key) . ' is not available right now.', 'info');
    redirect($back);
}

/** Flip a module live/hidden. Returns the new state. */
function module_set(string $key, bool $live, ?int $adminId = null): bool
{
    if (!has_table('modules')) {
        return $live;
    }
    q('UPDATE modules SET is_active = ?, updated_by = ? WHERE mkey = ?',
      [$live ? 1 : 0, $adminId, $key]);
    modules_all(true);
    return $live;
}

/** Flip a module's navigation visibility. */
function module_set_nav(string $key, bool $visible, ?int $adminId = null): void
{
    if (!has_table('modules')) {
        return;
    }
    q('UPDATE modules SET nav_visible = ?, updated_by = ? WHERE mkey = ?',
      [$visible ? 1 : 0, $adminId, $key]);
    modules_all(true);
}

// ---------------------------------------------------------------
// Games
// ---------------------------------------------------------------

/**
 * Is a single game playable right now? Both switches have to agree:
 * the arcade module and the game's own row.
 */
function game_live(string $slug): bool
{
    if (!module_on('games')) {
        return false;
    }
    return (int) fetch_col(
        'SELECT is_active FROM games WHERE slug = ?', [$slug], 0
    ) === 1;
}

/** Games the public is allowed to see, in display order. */
function live_games(): array
{
    if (!module_on('games')) {
        return [];
    }
    return fetch_all('SELECT * FROM games WHERE is_active = 1 ORDER BY sort_order, title');
}

/**
 * Chess is a game row *and* a module, because online play can be switched
 * off on its own while the offline modes stay up.
 */
function chess_online_on(): bool
{
    return module_on('chess')
        && module_on('chess_online')
        && setting('chess_online_enabled', '1') === '1'
        && has_table('chess_games');
}
