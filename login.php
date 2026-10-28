<?php
require_once __DIR__ . '/config/config.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$page_title = 'Log In';
$page_desc  = 'Log in to ' . setting('site_name', 'Asviora Games') . ' to play skill games, quizzes and chess, and track your coins, XP and rank.';
$errors = [];
$ident  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $ident = post('identity');
        $pass  = (string) ($_POST['password'] ?? '');

        // simple brute-force throttle per session
        $_SESSION['login_fails'] = $_SESSION['login_fails'] ?? 0;
        if ($_SESSION['login_fails'] >= 8) {
            $errors[] = 'Too many failed attempts. Please wait a minute and try again.';
            if (($_SESSION['login_block'] ?? 0) < time() - 60) {
                $_SESSION['login_fails'] = 0;
                array_pop($errors);
            }
        }

        if (!$errors) {
            if ($ident === '' || $pass === '') {
                $errors[] = 'Enter your username/email and password.';
            } else {
                $user = fetch_one(
                    'SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1',
                    [strtolower($ident), strtolower($ident)]);

                if (!$user || !password_verify($pass, $user['password_hash'])) {
                    $_SESSION['login_fails']++;
                    $_SESSION['login_block'] = time();
                    $errors[] = 'Wrong username/email or password.';
                } elseif ($user['status'] !== 'active') {
                    $errors[] = 'This account is ' . $user['status'] . '. Contact support for help.';
                } else {
                    $_SESSION['login_fails'] = 0;
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int) $user['id'];

                    // daily login bonus, once per calendar day
                    $daily = (int) setting('daily_bonus', '50');
                    $last  = $user['last_login'] ? date('Y-m-d', strtotime($user['last_login'])) : '';
                    if ($daily > 0 && $last !== date('Y-m-d')) {
                        add_coins((int) $user['id'], $daily, 'daily', 'Daily login bonus');
                        flash('Daily bonus: +' . num($daily) . ' coins!');
                    }
                    q('UPDATE users SET last_login = NOW() WHERE id = ?', [$user['id']]);

                    flash('Welcome back, ' . $user['username'] . '!');
                    $back = $_SESSION['redirect_after_login'] ?? '';
                    unset($_SESSION['redirect_after_login']);
                    redirect($back !== '' ? $back : 'dashboard.php');
                }
            }
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap">
  <div class="card auth-card">
    <img src="<?= e(logo_icon(192)) ?>" class="auth-logo" alt="">
    <div class="center mb-3">
      <h1 style="font-size:1.6rem;margin-bottom:6px">Welcome back</h1>
      <p class="text-muted" style="margin:0;font-size:.92rem">Log in to keep your streak going.</p>
    </div>

    <?= render_flash() ?>
    <?php foreach ($errors as $er): ?>
      <div class="alert alert-error" data-persist="1"><span>&#9888;</span><?= e($er) ?></div>
    <?php endforeach; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>

      <div class="form-group">
        <label class="form-label" for="identity">Username or email</label>
        <input class="form-control" id="identity" name="identity" value="<?= e($ident) ?>"
               placeholder="skillking or you@email.com" required autofocus>
      </div>

      <div class="form-group">
        <label class="form-label" for="password">Password</label>
        <input class="form-control" type="password" id="password" name="password"
               placeholder="Your password" required>
      </div>

      <button class="btn btn-primary btn-block btn-lg" type="submit">Log In</button>
    </form>

    <p class="center text-muted mt-3" style="margin-bottom:0;font-size:.92rem">
      New here? <a href="<?= url('register.php') ?>">Create a free account</a>
    </p>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
