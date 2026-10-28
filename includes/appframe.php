<?php
/**
 * Signed play tickets for the mobile app.
 *
 * Since v6.1 every playable page on the site is behind require_play_login(),
 * and the arcade HTML itself is served by game-frame.php after a session
 * check. The Android app has no PHP session — it carries a bearer token —
 * so without this file its WebView would land on login.php instead of a
 * game board.
 *
 * A ticket is a short-lived HMAC over "<user>.<expiry>.<slug>", signed with
 * a secret kept in the settings table. Nothing is stored per ticket: it
 * cannot be listed, cannot leak a password, dies in fifteen minutes, and is
 * only good for the one game it names. game-frame.php verifies it and opens
 * a normal session for that WebView, so from there on the frame behaves
 * exactly as it does in a browser.
 */

/** The signing secret, made once and then reused. */
function app_frame_secret(): string
{
    $s = setting('app_frame_secret', '');
    if (strlen($s) < 32) {
        $s = bin2hex(random_bytes(32));
        set_setting('app_frame_secret', $s);
    }
    return $s;
}

/** Mint a ticket. Default life: fifteen minutes. */
function app_frame_ticket(int $userId, string $slug, int $ttl = 900): string
{
    $exp  = time() + max(60, $ttl);
    $sig  = hash_hmac('sha256', $userId . '.' . $exp . '.' . $slug, app_frame_secret());
    return $userId . '.' . $exp . '.' . substr($sig, 0, 32);
}

/**
 * Verify a ticket for one game. Returns the player id, or 0.
 *
 * The signature covers the slug, so a ticket for sudoku cannot be replayed
 * against chess, and the account is re-checked here — a player blocked
 * since the ticket was issued gets nothing.
 */
function app_frame_user(string $ticket, string $slug): int
{
    $p = explode('.', $ticket);
    if (count($p) !== 3) {
        return 0;
    }
    [$uid, $exp, $sig] = $p;
    if (!ctype_digit($uid) || !ctype_digit($exp) || (int) $exp < time()) {
        return 0;
    }

    $want = substr(hash_hmac('sha256', $uid . '.' . $exp . '.' . $slug,
                             app_frame_secret()), 0, 32);
    if (!hash_equals($want, $sig)) {
        return 0;
    }

    return (int) fetch_col('SELECT id FROM users WHERE id = ? AND status = "active"',
                           [(int) $uid], 0);
}

/** The full URL the app should open in its WebView. */
function app_frame_url(int $userId, string $slug): string
{
    return url('game-frame.php?game=' . urlencode($slug)
             . '&t=' . urlencode(app_frame_ticket($userId, $slug)));
}
