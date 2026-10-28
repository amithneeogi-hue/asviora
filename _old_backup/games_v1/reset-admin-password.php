<?php
/**
 * ============================================================
 *  ONE-TIME UTILITY — DELETE THIS FILE AFTER YOU HAVE USED IT
 * ============================================================
 * Use this only if you cannot sign in to /admin with the seeded
 * credentials. It sets a fresh password for an admin account.
 *
 * It refuses to run unless the request comes from the same machine
 * as the server (localhost), so it cannot be abused remotely.
 */
require_once __DIR__ . '/config/config.php';

$localIps = ['127.0.0.1', '::1', 'localhost'];
$isLocal  = in_array($_SERVER['REMOTE_ADDR'] ?? '', $localIps, true)
         || in_array($_SERVER['SERVER_ADDR'] ?? '', [$_SERVER['REMOTE_ADDR'] ?? ''], true);

$done = false;
$errors = [];

if (!$isLocal) {
    http_response_code(403);
    exit('This utility only runs from localhost. Delete reset-admin-password.php from the server.');
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
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
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
      Only needed if the seeded login does not work. Runs from localhost only.</p>

    <?php if ($done): ?>
      <div class="alert alert-success" data-persist="1">
        <span>&#10003;</span>
        Password updated for <strong><?= e($done) ?></strong>. You can
        <a href="<?= url('admin/login.php') ?>" style="text-decoration:underline">sign in now</a>.
      </div>
    <?php endif; ?>
    <?php foreach ($errors as $er): ?>
      <div class="alert alert-error" data-persist="1"><span>&#9888;</span><?= e($er) ?></div>
    <?php endforeach; ?>

    <form method="post">
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

<script src="<?= url('assets/js/main.js') ?>" defer></script>
</body>
</html>
