<?php
require_once __DIR__ . '/config/config.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$page_title = 'Create Account';
$errors = [];
$old    = ['name' => '', 'username' => '', 'email' => '', 'phone' => '', 'ref' => get('ref')];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $errors[] = 'Your session expired. Please submit the form again.';
    } else {
        $old['name']     = post('name');
        $old['username'] = strtolower(post('username'));
        $old['email']    = strtolower(post('email'));
        $old['phone']    = post('phone');
        $old['ref']      = strtoupper(post('ref'));
        $pass            = (string) ($_POST['password'] ?? '');
        $pass2           = (string) ($_POST['password2'] ?? '');

        if (mb_strlen($old['name']) < 2)  $errors[] = 'Please enter your full name.';
        if (!preg_match('/^[a-z0-9_]{3,20}$/', $old['username'])) {
            $errors[] = 'Username must be 3–20 characters: lowercase letters, numbers or underscore.';
        }
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
        if ($old['phone'] !== '' && !preg_match('/^[\d\s+\-()]{7,20}$/', $old['phone'])) {
            $errors[] = 'That phone number does not look right.';
        }
        if (strlen($pass) < 8)      $errors[] = 'Password must be at least 8 characters.';
        if ($pass !== $pass2)       $errors[] = 'The two passwords do not match.';
        if (empty($_POST['terms'])) $errors[] = 'Please accept the fair-play terms.';

        if (!$errors) {
            if (fetch_one('SELECT id FROM users WHERE username = ?', [$old['username']])) {
                $errors[] = 'That username is already taken.';
            }
            if (fetch_one('SELECT id FROM users WHERE email = ?', [$old['email']])) {
                $errors[] = 'An account already exists with that email.';
            }
        }

        if (!$errors) {
            $bonus    = (int) setting('signup_bonus', '500');
            $refBonus = (int) setting('referral_bonus', '100');

            $referrer = null;
            if ($old['ref'] !== '') {
                $referrer = fetch_one('SELECT id FROM users WHERE referral_code = ?', [$old['ref']]);
            }

            $code = strtoupper(substr($old['username'], 0, 4) . bin2hex(random_bytes(3)));

            $uid = insert_row('users', [
                'name'          => $old['name'],
                'username'      => $old['username'],
                'email'         => $old['email'],
                'phone'         => $old['phone'] !== '' ? $old['phone'] : null,
                'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
                'coins'         => 0,
                'referral_code' => $code,
                'referred_by'   => $referrer['id'] ?? null,
            ]);

            add_coins($uid, $bonus, 'signup', 'Welcome bonus');
            if ($referrer) {
                add_coins((int) $referrer['id'], $refBonus, 'referral',
                          'Referred ' . $old['username']);
                add_coins($uid, $refBonus, 'referral', 'Joined via referral');
            }

            $_SESSION['user_id'] = $uid;
            q('UPDATE users SET last_login = NOW() WHERE id = ?', [$uid]);

            flash('Welcome to Asviora Games! ' . num($bonus) . ' coins added to your wallet.');
            // Someone who hit the login gate on their way to a game should land
            // on that game, not on a generic list of all of them.
            $back = $_SESSION['redirect_after_login'] ?? '';
            unset($_SESSION['redirect_after_login']);
            redirect($back !== '' ? $back : 'games.php');
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<div class="auth-wrap">
  <div class="card auth-card">
    <img src="<?= e(logo_icon(192)) ?>" class="auth-logo" alt="">
    <div class="center mb-3">
      <h1 style="font-size:1.6rem;margin-bottom:6px">Create your free account</h1>
      <p class="text-muted" style="margin:0;font-size:.92rem">
        Claim <?= num((int) setting('signup_bonus', '500')) ?> welcome coins and start competing.
      </p>
    </div>

    <?php foreach ($errors as $er): ?>
      <div class="alert alert-error" data-persist="1"><span>&#9888;</span><?= e($er) ?></div>
    <?php endforeach; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>

      <div class="form-group">
        <label class="form-label" for="name">Full name <span class="req">*</span></label>
        <input class="form-control" id="name" name="name" value="<?= e($old['name']) ?>"
               placeholder="Sujit Babu" required>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label" for="username">Username <span class="req">*</span></label>
          <input class="form-control" id="username" name="username" value="<?= e($old['username']) ?>"
                 placeholder="skillking" pattern="[a-z0-9_]{3,20}" required>
          <div class="form-hint">Shown on the leaderboard</div>
        </div>
        <div class="form-group">
          <label class="form-label" for="phone">Phone <span class="text-dim">(optional)</span></label>
          <input class="form-control" id="phone" name="phone" value="<?= e($old['phone']) ?>"
                 placeholder="+91 98765 43210">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="email">Email <span class="req">*</span></label>
        <input class="form-control" type="email" id="email" name="email"
               value="<?= e($old['email']) ?>" placeholder="you@email.com" required>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label" for="password">Password <span class="req">*</span></label>
          <input class="form-control" type="password" id="password" name="password"
                 placeholder="8+ characters" minlength="8" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="password2">Confirm <span class="req">*</span></label>
          <input class="form-control" type="password" id="password2" name="password2"
                 placeholder="Repeat password" minlength="8" required>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="ref">Referral code <span class="text-dim">(optional)</span></label>
        <input class="form-control" id="ref" name="ref" value="<?= e($old['ref']) ?>"
               placeholder="Got a code from a friend?" style="text-transform:uppercase">
        <div class="form-hint">Both of you get <?= num((int) setting('referral_bonus', '100')) ?> bonus coins</div>
      </div>

      <div class="form-group">
        <label class="check">
          <input type="checkbox" name="terms" value="1" required>
          <span>I agree to play fair and understand this platform uses
                <strong>virtual coins only</strong> &mdash; there is no real-money deposit,
                withdrawal or wagering.</span>
        </label>
      </div>

      <button class="btn btn-primary btn-block btn-lg" type="submit">Create Account</button>
    </form>

    <p class="center text-muted mt-3" style="margin-bottom:0;font-size:.92rem">
      Already registered? <a href="<?= url('login.php') ?>">Log in instead</a>
    </p>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
