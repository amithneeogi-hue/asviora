<?php
require_once __DIR__ . '/includes/auth.php';

if (admin_logged_in() && current_admin()) {
    redirect('admin/index.php');
}

$errors = [];
$user   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $errors[] = 'Session expired. Please try again.';
    } else {
        $user = post('username');
        $pass = (string) ($_POST['password'] ?? '');

        $_SESSION['admin_fails'] = $_SESSION['admin_fails'] ?? 0;
        if ($_SESSION['admin_fails'] >= 6 && (time() - (int) ($_SESSION['admin_block'] ?? 0)) < 120) {
            $errors[] = 'Too many failed attempts. Try again in two minutes.';
        } elseif ($user === '' || $pass === '') {
            $errors[] = 'Enter your username and password.';
        } else {
            $row = fetch_one('SELECT * FROM admins WHERE username = ? OR email = ? LIMIT 1',
                             [strtolower($user), strtolower($user)]);
            if (!$row || !password_verify($pass, $row['password_hash'])) {
                $_SESSION['admin_fails']++;
                $_SESSION['admin_block'] = time();
                $errors[] = 'Invalid credentials.';
            } elseif (!$row['is_active']) {
                $errors[] = 'This admin account is disabled.';
            } else {
                $_SESSION['admin_fails'] = 0;
                session_regenerate_id(true);
                $_SESSION['admin_id'] = (int) $row['id'];
                q('UPDATE admins SET last_login = NOW() WHERE id = ?', [$row['id']]);
                flash('Signed in as ' . $row['name'] . '.');
                $back = $_SESSION['admin_redirect'] ?? '';
                unset($_SESSION['admin_redirect']);
                redirect($back !== '' ? $back : 'admin/index.php');
            }
        }
    }
}

$site = setting('site_name', 'Asviora Games');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Login | <?= e($site) ?></title>
<link rel="icon" href="<?= e(logo_icon(32)) ?>">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body>

<div class="auth-wrap">
  <div class="card auth-card">
    <img src="<?= e(logo_icon(192)) ?>" class="auth-logo" alt="">
    <div class="center mb-3">
      <span class="eyebrow">Control panel</span>
      <h1 style="font-size:1.5rem;margin-bottom:6px">Admin sign in</h1>
      <p class="text-muted" style="margin:0;font-size:.92rem"><?= e($site) ?> administration</p>
    </div>

    <?= render_flash() ?>
    <?php foreach ($errors as $er): ?>
      <div class="alert alert-error" data-persist="1"><span>&#9888;</span><?= e($er) ?></div>
    <?php endforeach; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <div class="form-group">
        <label class="form-label" for="username">Username or email</label>
        <input class="form-control" id="username" name="username" value="<?= e($user) ?>"
               required autofocus autocomplete="username">
      </div>
      <div class="form-group">
        <label class="form-label" for="password">Password</label>
        <input class="form-control" type="password" id="password" name="password"
               required autocomplete="current-password">
      </div>
      <button class="btn btn-primary btn-block btn-lg" type="submit">Sign In</button>
    </form>

    <p class="center text-dim mt-3" style="margin-bottom:0">
      <a href="<?= url('index.php') ?>">&larr; Back to the website</a>
    </p>
  </div>
</div>

<script src="<?= asset('assets/js/main.js') ?>" defer></script>
</body>
</html>
