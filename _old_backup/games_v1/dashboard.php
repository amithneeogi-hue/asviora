<?php
require_once __DIR__ . '/config/config.php';
require_login();

$me  = current_user();
$tab = get('tab', 'overview');
$page_title = 'My Dashboard';

/* ---------------- profile update ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'profile') {
    if (!csrf_ok()) {
        flash('Session expired, please try again.', 'error');
    } else {
        $name  = post('name');
        $bio   = mb_substr(post('bio'), 0, 255);
        $phone = post('phone');
        $err   = [];

        if (mb_strlen($name) < 2) $err[] = 'Please enter your name.';
        if ($phone !== '' && !preg_match('/^[\d\s+\-()]{7,20}$/', $phone)) {
            $err[] = 'That phone number does not look right.';
        }

        $newPass = (string) ($_POST['new_password'] ?? '');
        if ($newPass !== '') {
            if (!password_verify((string) ($_POST['old_password'] ?? ''), $me['password_hash'])) {
                $err[] = 'Your current password is wrong.';
            } elseif (strlen($newPass) < 8) {
                $err[] = 'New password must be at least 8 characters.';
            }
        }

        if ($err) {
            foreach ($err as $x) flash($x, 'error');
        } else {
            $fields = ['name' => $name, 'bio' => $bio !== '' ? $bio : null,
                       'phone' => $phone !== '' ? $phone : null];

            $av = save_upload('avatar', 'avatars');
            if ($av) {
                delete_upload($me['avatar'], 'avatars');
                $fields['avatar'] = $av;
            }
            if ($newPass !== '') {
                $fields['password_hash'] = password_hash($newPass, PASSWORD_DEFAULT);
            }
            update_row('users', $fields, 'id = ?', [$me['id']]);
            flash('Profile updated.');
        }
    }
    redirect('dashboard.php?tab=profile');
}

/* ---------------- data ---------------- */
$stats = fetch_one(
    'SELECT COUNT(*) AS rounds, COALESCE(SUM(score),0) AS points,
            COALESCE(MAX(score),0) AS best, COALESCE(SUM(coins_earned),0) AS earned,
            COALESCE(SUM(time_taken),0) AS seconds
     FROM scores WHERE user_id = ?', [$me['id']]) ?: [];

$rank = 1 + (int) fetch_col(
    'SELECT COUNT(*) FROM (
        SELECT user_id, SUM(score) AS t FROM scores GROUP BY user_id
     ) x WHERE x.t > (SELECT COALESCE(SUM(score),0) FROM scores WHERE user_id = ?)',
    [$me['id']], 0);

$perGame = fetch_all(
    'SELECT g.title, g.slug, g.icon, COUNT(s.id) AS rounds,
            COALESCE(MAX(s.score),0) AS best, COALESCE(SUM(s.score),0) AS total
     FROM games g LEFT JOIN scores s ON s.game_id = g.id AND s.user_id = ?
     WHERE g.is_active = 1 GROUP BY g.id ORDER BY g.sort_order', [$me['id']]);

$history = fetch_all(
    'SELECT s.*, g.title, g.icon, g.slug FROM scores s JOIN games g ON g.id = s.game_id
     WHERE s.user_id = ? ORDER BY s.played_at DESC LIMIT 60', [$me['id']]);

$ledger = fetch_all(
    'SELECT * FROM coin_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 60', [$me['id']]);

$refCount = (int) fetch_col('SELECT COUNT(*) FROM users WHERE referred_by = ?', [$me['id']], 0);

$tabs = ['overview' => 'Overview', 'history' => 'Match History',
         'wallet' => 'Coin Wallet', 'profile' => 'Profile'];

require __DIR__ . '/includes/header.php';
?>

<section class="section-sm">
  <div class="container">

    <div class="card reveal in mb-3" style="display:flex;flex-wrap:wrap;gap:22px;align-items:center">
      <img src="<?= e(avatar_url($me)) ?>" alt=""
           style="width:82px;height:82px;border-radius:22px;object-fit:cover;border:1px solid var(--line)">
      <div style="flex:1 1 220px">
        <h1 style="font-size:1.55rem;margin-bottom:4px"><?= e($me['name']) ?></h1>
        <div class="text-muted" style="font-size:.93rem">
          @<?= e($me['username']) ?> &middot; joined <?= fdate($me['created_at'], 'M Y') ?>
        </div>
        <?php if ($me['bio']): ?>
          <div class="text-dim mt-1"><?= e($me['bio']) ?></div>
        <?php endif; ?>
      </div>
      <div class="row" style="gap:9px">
        <span class="coin-pill">&#129689; <?= num($me['coins']) ?> coins</span>
        <span class="badge badge-green">Level <?= (int) $me['level'] ?></span>
        <span class="badge badge-gold">Rank #<?= $rank ?></span>
      </div>
    </div>

    <?= render_flash() ?>

    <div class="tabs">
      <?php foreach ($tabs as $k => $label): ?>
        <a href="<?= url('dashboard.php?tab=' . $k) ?>"
           class="<?= $tab === $k ? 'active' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>

    <?php /* ==================== OVERVIEW ==================== */
    if ($tab === 'overview'): ?>

      <div class="grid g-4 mb-3">
        <?php
        $cards = [
          ['Rounds played', num($stats['rounds'] ?? 0)],
          ['Total points',  num($stats['points'] ?? 0)],
          ['Best single score', num($stats['best'] ?? 0)],
          ['Coins earned', num($stats['earned'] ?? 0)],
        ];
        foreach ($cards as $c): ?>
          <div class="card stat-card reveal">
            <div class="sc-num"><?= $c[1] ?></div>
            <div class="sc-lbl"><?= e($c[0]) ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="grid g-2">
        <div class="card">
          <h3 style="font-size:1.08rem">Level progress</h3>
          <div class="kv"><span class="k">Current level</span>
            <span class="v">Lv <?= (int) $me['level'] ?></span></div>
          <div class="kv"><span class="k">Total XP</span>
            <span class="v"><?= num($me['xp']) ?></span></div>
          <div class="kv"><span class="k">Time played</span>
            <span class="v"><?= floor(($stats['seconds'] ?? 0) / 60) ?> min</span></div>
          <div class="mt-2">
            <div class="bar"><i style="width:<?= max(3, ($me['xp'] % 250) / 250 * 100) ?>%"></i></div>
            <div class="text-dim mt-1">
              <?= 250 - ($me['xp'] % 250) ?> XP to reach level <?= (int) $me['level'] + 1 ?>
            </div>
          </div>
        </div>

        <div class="card">
          <h3 style="font-size:1.08rem">Refer a friend</h3>
          <p class="text-muted" style="font-size:.9rem">
            Share your code &mdash; you both get
            <?= num((int) setting('referral_bonus', '100')) ?> coins when they sign up.</p>
          <div class="row" style="gap:8px;align-items:center">
            <code style="flex:1;padding:12px 15px;background:var(--bg-2);border:1px dashed var(--line);
                         border-radius:10px;font-weight:900;letter-spacing:.1em;color:var(--gold)">
              <?= e($me['referral_code'] ?: '—') ?>
            </code>
            <button class="btn btn-ghost btn-sm" type="button"
              onclick="navigator.clipboard.writeText('<?= url('register.php?ref=' . urlencode((string) $me['referral_code'])) ?>').then(function(){asvioraToast('Referral link copied!')})">
              Copy link
            </button>
          </div>
          <div class="kv mt-2"><span class="k">Friends joined</span>
            <span class="v text-green"><?= $refCount ?></span></div>
        </div>
      </div>

      <h3 class="mt-4 mb-2" style="font-size:1.15rem">Per-game breakdown</h3>
      <div class="table-wrap">
        <div class="table-scroll">
          <table class="tbl">
            <thead><tr><th>Game</th><th>Rounds</th><th>Best score</th>
                       <th>Total points</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($perGame as $g): ?>
                <tr>
                  <td><strong><?= $g['icon'] ?> <?= e($g['title']) ?></strong></td>
                  <td><?= num($g['rounds']) ?></td>
                  <td class="text-green"><strong><?= $g['best'] ? num($g['best']) : '&mdash;' ?></strong></td>
                  <td><?= num($g['total']) ?></td>
                  <td style="text-align:right">
                    <a href="<?= url('play.php?game=' . urlencode($g['slug'])) ?>"
                       class="btn btn-primary btn-xs">Play</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

    <?php /* ==================== HISTORY ==================== */
    elseif ($tab === 'history'): ?>

      <?php if (!$history): ?>
        <div class="card empty">
          <div class="em-ico">&#127918;</div>
          <h3>No rounds yet</h3>
          <p>Play your first game and it will show up here.</p>
          <a href="<?= url('games.php') ?>" class="btn btn-primary">Browse games</a>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <div class="table-scroll">
            <table class="tbl">
              <thead><tr><th>Game</th><th>Score</th><th>Time</th><th>Moves</th>
                         <th>Level</th><th>Coins</th><th>Result</th><th>When</th></tr></thead>
              <tbody>
                <?php foreach ($history as $h): ?>
                  <tr>
                    <td><strong><?= $h['icon'] ?> <?= e($h['title']) ?></strong></td>
                    <td><strong class="text-green"><?= num($h['score']) ?></strong></td>
                    <td><?= ftime((int) $h['time_taken']) ?></td>
                    <td><?= $h['moves'] ? num($h['moves']) : '&mdash;' ?></td>
                    <td><?= $h['difficulty'] ? e(ucfirst($h['difficulty'])) : '&mdash;' ?></td>
                    <td class="text-gold"><?= $h['coins_earned'] ? '+' . num($h['coins_earned']) : '&mdash;' ?></td>
                    <td><span class="badge <?= $h['result'] === 'win' ? 'badge-green' : 'badge-red' ?>">
                        <?= e($h['result']) ?></span></td>
                    <td class="text-dim nowrap"><?= fdate($h['played_at'], 'd M, H:i') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <p class="text-dim center mt-2">Showing your 60 most recent rounds.</p>
      <?php endif; ?>

    <?php /* ==================== WALLET ==================== */
    elseif ($tab === 'wallet'): ?>

      <div class="alert alert-info" data-persist="1">
        <span>&#8505;</span>
        Coins are a virtual in-game currency for entry fees, unlocks and bragging rights.
        They have no cash value and cannot be deposited or withdrawn.
      </div>

      <div class="grid g-3 mb-3">
        <div class="card stat-card"><div class="sc-num"><?= num($me['coins']) ?></div>
          <div class="sc-lbl">Current balance</div></div>
        <div class="card stat-card"><div class="sc-num"><?= num($stats['earned'] ?? 0) ?></div>
          <div class="sc-lbl">Earned from play</div></div>
        <div class="card stat-card"><div class="sc-num"><?= count($ledger) ?></div>
          <div class="sc-lbl">Recent transactions</div></div>
      </div>

      <?php if (!$ledger): ?>
        <div class="card empty"><div class="em-ico">&#129689;</div>
          <h3>No transactions yet</h3><p>Your coin activity will appear here.</p></div>
      <?php else: ?>
        <div class="table-wrap">
          <div class="table-scroll">
            <table class="tbl">
              <thead><tr><th>Type</th><th>Note</th><th style="text-align:right">Amount</th>
                         <th style="text-align:right">Balance</th><th>When</th></tr></thead>
              <tbody>
                <?php foreach ($ledger as $t): ?>
                  <tr>
                    <td><span class="badge"><?= e(str_replace('_', ' ', $t['type'])) ?></span></td>
                    <td class="text-muted"><?= e($t['note'] ?: '—') ?></td>
                    <td style="text-align:right;font-weight:800;color:<?=
                        $t['amount'] >= 0 ? 'var(--green)' : 'var(--danger)' ?>">
                      <?= $t['amount'] >= 0 ? '+' : '' ?><?= num($t['amount']) ?></td>
                    <td style="text-align:right"><?= num($t['balance']) ?></td>
                    <td class="text-dim nowrap"><?= fdate($t['created_at'], 'd M, H:i') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>

    <?php /* ==================== PROFILE ==================== */
    else: ?>

      <div class="grid g-2" style="align-items:start">
        <form method="post" enctype="multipart/form-data" class="card">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="profile">
          <h3 style="font-size:1.08rem">Edit profile</h3>

          <div class="form-group">
            <label class="form-label" for="name">Full name</label>
            <input class="form-control" id="name" name="name" value="<?= e($me['name']) ?>" required>
          </div>

          <div class="form-group">
            <label class="form-label">Username</label>
            <input class="form-control" value="<?= e($me['username']) ?>" disabled>
            <div class="form-hint">Usernames are permanent to keep the leaderboard honest.</div>
          </div>

          <div class="form-group">
            <label class="form-label" for="phone">Phone</label>
            <input class="form-control" id="phone" name="phone" value="<?= e($me['phone']) ?>"
                   placeholder="+91 98765 43210">
          </div>

          <div class="form-group">
            <label class="form-label" for="bio">Short bio</label>
            <textarea class="form-control" id="bio" name="bio" maxlength="255"
                      style="min-height:80px" placeholder="Sudoku speedrunner, cube enthusiast…"><?= e($me['bio']) ?></textarea>
          </div>

          <div class="form-group">
            <label class="form-label" for="avatar">Avatar</label>
            <input class="form-control" type="file" id="avatar" name="avatar" accept="image/*">
            <div class="form-hint">JPG, PNG, GIF or WebP. Max 5&nbsp;MB.</div>
          </div>

          <hr style="border:0;border-top:1px solid var(--line-soft);margin:22px 0">
          <h3 style="font-size:1rem">Change password</h3>
          <div class="form-group">
            <label class="form-label" for="old_password">Current password</label>
            <input class="form-control" type="password" id="old_password" name="old_password"
                   placeholder="Leave blank to keep the same">
          </div>
          <div class="form-group">
            <label class="form-label" for="new_password">New password</label>
            <input class="form-control" type="password" id="new_password" name="new_password"
                   placeholder="8+ characters" minlength="8">
          </div>

          <button class="btn btn-primary btn-block" type="submit">Save Changes</button>
        </form>

        <div class="card">
          <h3 style="font-size:1.08rem">Account details</h3>
          <div class="kv"><span class="k">Email</span><span class="v"><?= e($me['email']) ?></span></div>
          <div class="kv"><span class="k">Member since</span><span class="v"><?= fdate($me['created_at']) ?></span></div>
          <div class="kv"><span class="k">Last login</span><span class="v"><?= fdate($me['last_login'], 'd M Y, H:i') ?></span></div>
          <div class="kv"><span class="k">Referral code</span><span class="v text-gold"><?= e($me['referral_code']) ?></span></div>
          <div class="kv"><span class="k">Status</span>
            <span class="v"><span class="badge badge-green"><?= e($me['status']) ?></span></span></div>

          <div class="mt-3">
            <p class="text-dim">Need help with your account? Reach us at
              <a href="mailto:<?= e(setting('contact_email')) ?>"><?= e(setting('contact_email')) ?></a>
              or through our social channels.</p>
            <div class="row" style="gap:8px">
              <?php if (setting('social_facebook')): ?>
                <a href="<?= e(setting('social_facebook')) ?>" target="_blank" rel="noopener"
                   class="btn btn-ghost btn-sm">Facebook</a>
              <?php endif; ?>
              <?php if (setting('social_instagram')): ?>
                <a href="<?= e(setting('social_instagram')) ?>" target="_blank" rel="noopener"
                   class="btn btn-ghost btn-sm">Instagram</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

    <?php endif; ?>

  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
