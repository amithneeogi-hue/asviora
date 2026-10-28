<?php
/**
 * ============================================================
 *  ONE-TIME UTILITY — DELETE THIS FILE AFTER YOU HAVE USED IT
 * ============================================================
 * Sets a fresh password for an admin account when you cannot sign in
 * to /admin any more.
 *
 * ------------------------------------------------------------------
 * HOW TO USE IT ON THE LIVE SERVER
 * ------------------------------------------------------------------
 * On localhost it just works. On a public domain it would be a wide
 * open back door, so it stays shut until you deliberately open it:
 *
 *   1. put a long random string between the quotes on the RESET_KEY
 *      line below (30+ characters, letters and digits);
 *   2. upload the file;
 *   3. visit  https://your-domain/reset-admin-password.php?key=THAT-STRING
 *   4. set the new password, then press "Delete this file" on the
 *      confirmation screen — or delete it in File Manager.
 *
 * Leave RESET_KEY empty and the file refuses to run anywhere except
 * localhost, exactly as before.
 */
require_once __DIR__ . '/config/config.php';

/* ---------- put your one-time key here, then blank it again ---------- */
const RESET_KEY = 'JDJbLHCuf4uzjPGPYeaDprv6ifpiz9UT';
/* --------------------------------------------------------------------- */

$localIps = ['127.0.0.1', '::1', 'localhost'];
$isLocal  = in_array($_SERVER['REMOTE_ADDR'] ?? '', $localIps, true)
         || in_array($_SERVER['SERVER_ADDR'] ?? '', [$_SERVER['REMOTE_ADDR'] ?? ''], true);

// A key unlocks the utility on a public domain. It must be long enough to be
// worth having, and it is compared in constant time so it cannot be guessed
// character by character from response timing.
$keyGiven  = (string) ($_GET['key'] ?? $_POST['key'] ?? '');
$keySet    = strlen(RESET_KEY) >= 20;
$keyOk     = $keySet && hash_equals(RESET_KEY, $keyGiven);

if (!$isLocal && !$keySet) {
    // No key configured at all. Nothing sensitive here — no admin names, no
    // form — just the one instruction needed to make the utility usable. Being
    // explicit beats a bare 404 that looks identical to a bad upload.
    http_response_code(403);
    exit('<!DOCTYPE html><meta charset="utf-8"><title>Reset utility locked</title>'
       . '<div style="font:16px/1.65 system-ui,sans-serif;max-width:560px;margin:70px auto;'
       . 'padding:28px;background:#17191d;color:#eaeee8;border:1px solid #2e3239;border-radius:14px">'
       . '<h2 style="margin:0 0 12px;color:#d9a63c">Reset utility is locked</h2>'
       . '<p style="color:#a2ab99;margin:0 0 14px">This tool will not run on a public domain '
       . 'until you give it a one-time key.</p>'
       . '<p style="color:#a2ab99;margin:0 0 14px">Open <code style="color:#8fce3f">'
       . e(basename(__FILE__)) . '</code> on the server, put a random string of 20 or more '
       . 'characters between the quotes on the <code style="color:#8fce3f">RESET_KEY</code> line '
       . 'near the top, save, then reload this page with '
       . '<code style="color:#8fce3f">?key=YOUR-STRING</code> on the end of the address.</p>'
       . '<p style="color:#757d6d;margin:0;font-size:.9rem">Delete this file once you are back in.'
       . '</p></div>');
}

if (!$isLocal && !$keyOk) {
    // A key IS configured and the one supplied does not match. Give a stranger
    // nothing to work with.
    http_response_code(404);
    exit('Not found.');
}

$done      = false;
$errors    = [];
$selfName  = basename(__FILE__);

/* ---------- self-destruct, offered after a successful reset ---------- */
if (($_POST['action'] ?? '') === 'selfdelete') {
    if (@unlink(__FILE__)) {
        http_response_code(200);
        exit('<!DOCTYPE html><meta charset="utf-8">'
           . '<div style="font:16px/1.6 system-ui,sans-serif;max-width:520px;margin:70px auto;'
           . 'padding:26px;background:#17191d;color:#eaeee8;border:1px solid #2e3239;border-radius:14px">'
           . '<h2 style="margin:0 0 10px;color:#8fce3f">File deleted</h2>'
           . '<p style="color:#a2ab99">The reset utility has removed itself. '
           . '<a href="' . e(url('admin/login.php')) . '" style="color:#8fce3f">Go to admin login</a>.</p></div>');
    }
    $errors[] = 'Could not delete the file automatically — remove '
              . $selfName . ' by hand in File Manager.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uname = strtolower(post('username'));
    $pass  = (string) ($_POST['password'] ?? '');

    if ($uname === '')        $errors[] = 'Enter the admin username.';
    if (strlen($pass) < 8)    $errors[] = 'The new password must be at least 8 characters.';

    if (!$errors) {
        $row = fetch_one('SELECT id, name FROM admins WHERE username = ?', [$uname]);
        if (!$row) {
            $errors[] = 'No admin account found with the username "' . $uname . '".';
        } else {
            update_row('admins', [
                'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
                'is_active'     => 1,
            ], 'id = ?', [$row['id']]);
            $done = $row['name'];
        }
    }
}

$admins = fetch_all('SELECT username, email, role, is_active FROM admins ORDER BY id');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reset admin password</title>
<link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body>
<div class="auth-wrap">
  <div class="card auth-card" style="max-width:520px">
    <div class="alert alert-error" data-persist="1">
      <span>&#9888;</span>
      Delete <code>reset-admin-password.php</code> as soon as you are done.
    </div>

    <h1 style="font-size:1.4rem">Reset an admin password</h1>
    <p class="text-muted" style="font-size:.9rem">
      Only needed if you cannot sign in to the control panel.
      <?= $isLocal ? 'Running on localhost.' : 'Unlocked with a reset key.' ?></p>

    <?php if ($done): ?>
      <div class="alert alert-success" data-persist="1">
        <span>&#10003;</span>
        Password updated for <strong><?= e($done) ?></strong>. You can
        <a href="<?= url('admin/login.php') ?>" style="text-decoration:underline">sign in now</a>.
      </div>

      <form method="post" style="margin-bottom:22px">
        <input type="hidden" name="action" value="selfdelete">
        <input type="hidden" name="key" value="<?= e($keyGiven) ?>">
        <button class="btn btn-block" type="submit"
                style="background:#fb5c7d;color:#0b0c0e;border:0">
          &#128465; Delete this file now
        </button>
        <p class="text-dim" style="font-size:.82rem;margin:8px 0 0;text-align:center">
          Do this before you leave the page &mdash; the utility should not stay on a live server.
        </p>
      </form>
    <?php endif; ?>
    <?php foreach ($errors as $er): ?>
      <div class="alert alert-error" data-persist="1"><span>&#9888;</span><?= e($er) ?></div>
    <?php endforeach; ?>

    <form method="post">
      <input type="hidden" name="key" value="<?= e($keyGiven) ?>">
      <div class="form-group">
        <label class="form-label" for="username">Admin username</label>
        <input class="form-control" id="username" name="username" value="admin" required>
      </div>
      <div class="form-group">
        <label class="form-label" for="password">New password</label>
        <input class="form-control" type="password" id="password" name="password"
               minlength="8" placeholder="8+ characters" required>
      </div>
      <button class="btn btn-primary btn-block" type="submit">Set Password</button>
    </form>

    <h3 style="font-size:.95rem;margin-top:26px">Existing admin accounts</h3>
    <?php foreach ($admins as $a): ?>
      <div class="kv">
        <span class="k"><?= e($a['username']) ?> <span class="text-dim">(<?= e($a['role']) ?>)</span></span>
        <span class="v"><?= $a['is_active'] ? '<span class="text-green">active</span>' : '<span class="text-dim">disabled</span>' ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<script src="<?= asset('assets/js/main.js') ?>" defer></script>
</body>
</html>
