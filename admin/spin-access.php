<?php
/**
 * Admin — spin access.
 *
 * Two modes. In "everyone" mode any signed-in player gets the default
 * number of spins a day. In "assigned" mode the wheel is invitation-only:
 * nobody spins until an admin has granted them access here.
 *
 * On top of the mode, each player can be given their own allowance, a
 * handful of one-off bonus spins, and a window of dates it is valid for.
 */
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();
require_manage();

$admin_title = 'Spin Access';
$admin_sub   = 'Decide who can spin the wheel, and how often';

if (!has_table('spin_access')) {
    flash('The spin access table is not installed yet. Open any page on the site once so '
        . 'the v4 migration runs, then come back.', 'error');
    redirect('admin/index.php');
}

// -------------------------------------------------------------------
// Actions
// -------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $do = post('do');

    if ($do === 'mode') {
        set_setting('spin_access_mode',
                    post('spin_access_mode') === 'assigned' ? 'assigned' : 'all');
        set_setting('spin_default_per_day', (string) max(0, (int) post('spin_default_per_day')));
        flash('Spin access mode saved.');

    } elseif ($do === 'grant') {
        $uid = (int) post('user_id');
        if ($uid <= 0 || !fetch_one('SELECT id FROM users WHERE id = ?', [$uid])) {
            flash('That player could not be found.', 'error');
        } else {
            spin_access_set($uid, [
                'is_allowed'    => !empty($_POST['is_allowed']),
                'spins_per_day' => (int) post('spins_per_day'),
                'bonus_spins'   => (int) post('bonus_spins'),
                'valid_from'    => post('valid_from') ?: null,
                'valid_until'   => post('valid_until') ?: null,
                'note'          => post('note'),
                // blank = follow the site limit set in Admin -> Daily Spin
                'cooldown_minutes' => post('cooldown_minutes'),
                'weekly_cap'       => post('weekly_cap'),
                'monthly_cap'      => post('monthly_cap'),
            ], (int) $A['id']);
            $u = fetch_col('SELECT username FROM users WHERE id = ?', [$uid], '');
            flash('Spin access updated for ' . $u . '.');
        }

    } elseif ($do === 'quick') {
        $uid   = (int) post('user_id');
        $allow = post('state') === '1';
        $row   = spin_access_row($uid);
        spin_access_set($uid, [
            'is_allowed'    => $allow,
            'spins_per_day' => $row ? (int) $row['spins_per_day'] : spin_default_per_day(),
            'bonus_spins'   => $row ? (int) $row['bonus_spins'] : 0,
            'valid_from'    => $row['valid_from']  ?? null,
            'valid_until'   => $row['valid_until'] ?? null,
            'note'          => $row['note'] ?? '',
        ], (int) $A['id']);
        flash($allow ? 'Access granted.' : 'Access switched off.', $allow ? 'success' : 'info');

    } elseif ($do === 'bonus') {
        $uid = (int) post('user_id');
        $n   = max(1, (int) post('n'));
        $row = spin_access_row($uid);
        spin_access_set($uid, [
            'is_allowed'    => $row ? (int) $row['is_allowed'] === 1 : true,
            'spins_per_day' => $row ? (int) $row['spins_per_day'] : spin_default_per_day(),
            'bonus_spins'   => ($row ? (int) $row['bonus_spins'] : 0) + $n,
            'valid_from'    => $row['valid_from']  ?? null,
            'valid_until'   => $row['valid_until'] ?? null,
            'note'          => $row['note'] ?? '',
        ], (int) $A['id']);
        flash($n . ' bonus spins added.');

    } elseif ($do === 'revoke') {
        spin_access_clear((int) post('user_id'));
        flash('Personal rule removed — that player falls back to the site default.', 'info');

    } elseif ($do === 'grant_all') {
        $per = max(1, (int) post('spins_per_day'));
        q('INSERT INTO spin_access (user_id, is_allowed, spins_per_day, granted_by)
             SELECT id, 1, ?, ? FROM users WHERE status = "active"
           ON DUPLICATE KEY UPDATE is_allowed = 1, spins_per_day = VALUES(spins_per_day)',
          [$per, (int) $A['id']]);
        flash('Every active player now has ' . $per . ' spin(s) a day.');
    }

    redirect('admin/spin-access.php' . (get('q') !== '' ? '?q=' . urlencode(get('q')) : ''));
}

// -------------------------------------------------------------------
// Data
// -------------------------------------------------------------------
$mode   = spin_access_mode();
$search = trim(get('q'));
$filter = get('show', 'granted');

$where  = [];
$params = [];

if ($search !== '') {
    $where[]  = '(u.username LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($filter === 'granted') {
    $where[] = 'a.id IS NOT NULL';
} elseif ($filter === 'blocked') {
    $where[] = 'a.id IS NOT NULL AND a.is_allowed = 0';
}

// The v6 limit columns only exist once the migration has run.
$hasLimits = spin_limits_installed();
$limCols   = $hasLimits
    ? ' a.cooldown_minutes, a.weekly_cap, a.monthly_cap,'
    : ' NULL AS cooldown_minutes, NULL AS weekly_cap, NULL AS monthly_cap,';

$sql = 'SELECT u.id, u.username, u.name, u.email, u.status,
               a.is_allowed, a.spins_per_day, a.bonus_spins,
               a.valid_from, a.valid_until, a.note,' . $limCols . '
               (SELECT COUNT(*) FROM spin_results s
                 WHERE s.user_id = u.id AND s.spin_date = CURDATE()) AS used_today,
               (SELECT COUNT(*) FROM spin_results s WHERE s.user_id = u.id) AS spins_all
          FROM users u
          LEFT JOIN spin_access a ON a.user_id = u.id'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY (a.id IS NOT NULL) DESC, u.username LIMIT 100';

$rows = fetch_all($sql, $params);

$stats = [
    'granted' => (int) fetch_col('SELECT COUNT(*) FROM spin_access WHERE is_allowed = 1', [], 0),
    'blocked' => (int) fetch_col('SELECT COUNT(*) FROM spin_access WHERE is_allowed = 0', [], 0),
    'today'   => (int) fetch_col('SELECT COUNT(*) FROM spin_results WHERE spin_date = CURDATE()', [], 0),
    'players' => (int) fetch_col('SELECT COUNT(*) FROM users WHERE status = "active"', [], 0),
];

require __DIR__ . '/includes/layout.php';
?>

<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:22px">
  <div class="card stat-card"><div class="sc-num"><?= num($stats['granted']) ?></div>
    <div class="sc-lbl">Players granted access</div></div>
  <div class="card stat-card"><div class="sc-num"><?= num($stats['blocked']) ?></div>
    <div class="sc-lbl">Access switched off</div></div>
  <div class="card stat-card"><div class="sc-num"><?= num($stats['today']) ?></div>
    <div class="sc-lbl">Spins today</div></div>
  <div class="card stat-card"><div class="sc-num"><?= $mode === 'assigned' ? 'Invite' : 'Open' ?></div>
    <div class="sc-lbl">Current mode</div></div>
</div>

<div class="adm-form-grid">
  <!-- ==================== MODE ==================== -->
  <div class="adm-panel">
    <div class="adm-panel-head"><h2>Who can spin</h2></div>
    <div class="adm-panel-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="mode">

        <div class="form-group">
          <label class="check">
            <input type="radio" name="spin_access_mode" value="all"
                   <?= $mode === 'all' ? 'checked' : '' ?>>
            <span><strong>Everyone</strong> — any signed-in player can spin</span>
          </label>
          <label class="check">
            <input type="radio" name="spin_access_mode" value="assigned"
                   <?= $mode === 'assigned' ? 'checked' : '' ?>>
            <span><strong>Assigned only</strong> — nobody spins until you grant them access below</span>
          </label>
        </div>

        <div class="form-group">
          <label class="form-label">Default spins per day</label>
          <input class="form-control" type="number" min="0" max="50" name="spin_default_per_day"
                 value="<?= spin_default_per_day() ?>" style="max-width:140px">
          <p class="form-hint">Used for players with no personal rule of their own.</p>
        </div>

        <button class="btn btn-primary" type="submit">Save mode</button>
      </form>

      <hr style="border:0;border-top:1px solid var(--line);margin:18px 0">

      <form method="post" class="row" style="gap:10px;align-items:flex-end">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="grant_all">
        <div class="form-group" style="margin:0">
          <label class="form-label">Grant everyone</label>
          <input class="form-control" type="number" min="1" max="50" name="spins_per_day"
                 value="1" style="width:110px">
        </div>
        <button class="btn btn-ghost" type="submit"
                data-confirm="Give every active player this many spins a day?">
          Apply to all players</button>
      </form>
    </div>
  </div>

  <!-- ==================== ONE PLAYER ==================== -->
  <div class="adm-panel">
    <div class="adm-panel-head">
      <div><h2>Grant or edit one player</h2>
        <p>Search below, then use Edit on their row — or type an id here.</p></div>
    </div>
    <div class="adm-panel-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="grant">

        <div class="form-group">
          <label class="form-label">Player id</label>
          <input class="form-control" type="number" name="user_id" required
                 value="<?= (int) get('edit') ?>">
        </div>

        <div class="row" style="gap:12px">
          <div class="form-group" style="flex:1">
            <label class="form-label">Spins per day</label>
            <input class="form-control" type="number" min="0" max="50"
                   name="spins_per_day" value="<?= spin_default_per_day() ?>">
          </div>
          <div class="form-group" style="flex:1">
            <label class="form-label">One-off bonus spins</label>
            <input class="form-control" type="number" min="0" max="500" name="bonus_spins" value="0">
          </div>
        </div>

        <div class="row" style="gap:12px">
          <div class="form-group" style="flex:1">
            <label class="form-label">Valid from</label>
            <input class="form-control" type="date" name="valid_from">
          </div>
          <div class="form-group" style="flex:1">
            <label class="form-label">Valid until</label>
            <input class="form-control" type="date" name="valid_until">
          </div>
        </div>

        <div class="row" style="gap:12px">
          <div class="form-group" style="flex:1">
            <label class="form-label">Wait between spins (min)</label>
            <input class="form-control" type="number" min="0" max="1440"
                   name="cooldown_minutes" placeholder="site: <?= spin_default_cooldown() ?>">
          </div>
          <div class="form-group" style="flex:1">
            <label class="form-label">Weekly cap</label>
            <input class="form-control" type="number" min="0" max="1000"
                   name="weekly_cap" placeholder="site: <?= spin_default_weekly_cap() ?>">
          </div>
          <div class="form-group" style="flex:1">
            <label class="form-label">Monthly cap</label>
            <input class="form-control" type="number" min="0" max="5000"
                   name="monthly_cap" placeholder="site: <?= spin_default_monthly_cap() ?>">
          </div>
        </div>
        <p class="form-hint" style="margin-top:-6px">
          Leave these three blank to follow the site limits from
          <a href="<?= admin_url('spin.php') ?>">Daily Spin</a>. 0 means "no limit for this player".
        </p>

        <div class="form-group">
          <label class="form-label">Note (only you see this)</label>
          <input class="form-control" type="text" name="note" maxlength="190"
                 placeholder="e.g. tournament winner, October promo">
        </div>

        <label class="check">
          <input type="checkbox" name="is_allowed" value="1" checked>
          <span>Access is switched on</span>
        </label>

        <div class="row mt-3"><button class="btn btn-primary" type="submit">Save access</button></div>
      </form>
    </div>
  </div>
</div>

<!-- ==================== PLAYER LIST ==================== -->
<div class="adm-panel mt-4">
  <div class="adm-panel-head">
    <div><h2>Players</h2></div>
    <form method="get" class="row" style="gap:8px;margin:0">
      <input class="form-control" type="search" name="q" value="<?= e($search) ?>"
             placeholder="Search name, username or email" style="width:230px">
      <select class="form-control" name="show" style="width:150px">
        <option value="granted" <?= $filter === 'granted' ? 'selected' : '' ?>>With a rule</option>
        <option value="all"     <?= $filter === 'all'     ? 'selected' : '' ?>>All players</option>
        <option value="blocked" <?= $filter === 'blocked' ? 'selected' : '' ?>>Switched off</option>
      </select>
      <button class="btn btn-ghost btn-sm" type="submit">Search</button>
    </form>
  </div>

  <div class="adm-panel-body flush">
    <?php if (!$rows): ?>
      <div class="empty"><div class="em-ico">&#127920;</div>
        <h3>Nothing to show</h3>
        <p>Switch the filter to “All players” to grant access to someone new.</p></div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="tbl" style="min-width:900px">
          <thead>
            <tr><th>Player</th><th>Access</th><th>Per day</th><th>Bonus</th>
                <th>Limits</th><th>Today</th><th>Window</th><th>Note</th>
                <th style="width:230px">Actions</th></tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r):
                $has   = $r['is_allowed'] !== null;
                $allow = $has ? (int) $r['is_allowed'] === 1 : ($mode === 'all'); ?>
            <tr>
              <td>
                <strong><?= e($r['username']) ?></strong>
                <div class="text-dim">#<?= (int) $r['id'] ?> &middot; <?= e($r['name']) ?></div>
              </td>
              <td>
                <?php if (!$has): ?>
                  <span class="badge badge-muted"><?= $mode === 'all' ? 'default' : 'no access' ?></span>
                <?php else: ?>
                  <span class="badge badge-<?= $allow ? 'green' : 'muted' ?>">
                    <?= $allow ? 'granted' : 'off' ?></span>
                <?php endif; ?>
              </td>
              <td class="text-dim"><?= $has ? (int) $r['spins_per_day'] : spin_default_per_day() ?></td>
              <td class="text-gold"><?= $has && (int) $r['bonus_spins'] > 0
                                        ? '+' . (int) $r['bonus_spins'] : '—' ?></td>
              <td class="text-dim" style="font-size:12px">
                <?php
                $bits = [];
                if ($r['cooldown_minutes'] !== null) { $bits[] = (int) $r['cooldown_minutes'] . 'm gap'; }
                if ($r['weekly_cap']       !== null) { $bits[] = (int) $r['weekly_cap'] . '/wk'; }
                if ($r['monthly_cap']      !== null) { $bits[] = (int) $r['monthly_cap'] . '/mo'; }
                echo $bits ? e(implode(' · ', $bits))
                           : '<span class="badge badge-muted">site</span>';
                ?>
              </td>
              <td class="text-dim"><?= (int) $r['used_today'] ?></td>
              <td class="text-dim" style="font-size:12px">
                <?= $r['valid_from']  ? fdate($r['valid_from'])  : '—' ?> →
                <?= $r['valid_until'] ? fdate($r['valid_until']) : '—' ?>
              </td>
              <td class="text-dim"><?= e(excerpt($r['note'] ?? '', 40)) ?></td>
              <td>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="quick">
                  <input type="hidden" name="user_id" value="<?= (int) $r['id'] ?>">
                  <input type="hidden" name="state" value="<?= $allow ? '0' : '1' ?>">
                  <button class="btn btn-<?= $allow ? 'ghost' : 'primary' ?> btn-sm" type="submit">
                    <?= $allow ? 'Switch off' : 'Grant' ?></button>
                </form>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="bonus">
                  <input type="hidden" name="user_id" value="<?= (int) $r['id'] ?>">
                  <input type="hidden" name="n" value="1">
                  <button class="btn btn-ghost btn-sm" type="submit">+1 spin</button>
                </form>
                <?php if ($has): ?>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="revoke">
                    <input type="hidden" name="user_id" value="<?= (int) $r['id'] ?>">
                    <button class="btn btn-ghost btn-sm" type="submit"
                            data-confirm="Remove this player's personal rule?">Reset</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
