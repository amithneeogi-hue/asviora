<?php
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();
require_manage();

$admin_title = 'Players';
$admin_sub   = 'Accounts, coin balances and moderation';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $id = (int) post('id');
    $do = post('do');
    $u  = $id ? fetch_one('SELECT * FROM users WHERE id = ?', [$id]) : null;

    if ($u) {
        if ($do === 'status') {
            $new = in_array(post('status'), ['active', 'suspended', 'banned'], true)
                 ? post('status') : 'active';
            update_row('users', ['status' => $new], 'id = ?', [$id]);
            flash($u['username'] . ' is now ' . $new . '.');
        } elseif ($do === 'coins') {
            $amt = (int) post('amount');
            if ($amt !== 0) {
                add_coins($id, $amt, 'admin_adjust',
                          'Adjusted by ' . $A['username'] . (post('note') !== '' ? ': ' . post('note') : ''));
                flash(($amt > 0 ? 'Added ' : 'Removed ') . num(abs($amt)) . ' coins for ' . $u['username'] . '.');
            }
        } elseif ($do === 'spin_access') {
            $new = in_array(post('spin_access'), ['default', 'allowed', 'blocked'], true)
                 ? post('spin_access') : 'default';
            update_row('users', ['spin_access' => $new], 'id = ?', [$id]);
            flash('Spin access for ' . $u['username'] . ': ' . $new . '.');
        } elseif ($do === 'reset_pass') {
            $tmp = 'Asv' . bin2hex(random_bytes(4));
            update_row('users', ['password_hash' => password_hash($tmp, PASSWORD_DEFAULT)], 'id = ?', [$id]);
            flash('Temporary password for ' . $u['username'] . ': ' . $tmp . ' — share it securely.', 'info');
        } elseif ($do === 'delete') {
            delete_upload($u['avatar'], 'avatars');
            q('DELETE FROM users WHERE id = ?', [$id]);
            flash('Player ' . $u['username'] . ' deleted along with their scores.');
        }
    }
    redirect('admin/users.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
}

/* ---------------- filters ---------------- */
$search = get('q');
$status = get('status');
$sort   = get('sort', 'new');
$page   = max(1, (int) get('page', '1'));
$per    = 20;

$where  = ['1 = 1'];
$params = [];
if ($search !== '') {
    $where[] = '(u.username LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
if (in_array($status, ['active', 'suspended', 'banned'], true)) {
    $where[] = 'u.status = ?';
    $params[] = $status;
}
$w = implode(' AND ', $where);

$orderMap = [
    'new'    => 'u.id DESC',
    'old'    => 'u.id ASC',
    'coins'  => 'u.coins DESC',
    'xp'     => 'u.xp DESC',
    'name'   => 'u.username ASC',
    'active' => 'u.last_login DESC',
];
$order = $orderMap[$sort] ?? $orderMap['new'];

$total  = (int) fetch_col("SELECT COUNT(*) FROM users u WHERE $w", $params, 0);
$pages  = max(1, (int) ceil($total / $per));
$page   = min($page, $pages);
$offset = ($page - 1) * $per;

$users = fetch_all(
    "SELECT u.*,
            (SELECT COUNT(*) FROM scores s WHERE s.user_id = u.id) AS rounds,
            (SELECT COALESCE(SUM(s.score),0) FROM scores s WHERE s.user_id = u.id) AS points
     FROM users u WHERE $w ORDER BY $order LIMIT $per OFFSET $offset", $params);

function ul(array $over = []): string
{
    $q = array_merge(['q' => get('q'), 'status' => get('status'),
                      'sort' => get('sort'), 'page' => get('page')], $over);
    $q = array_filter($q, function ($v) { return $v !== '' && $v !== null; });
    return admin_url('users.php' . ($q ? '?' . http_build_query($q) : ''));
}

$counts = [
    'all'       => (int) fetch_col('SELECT COUNT(*) FROM users', [], 0),
    'active'    => (int) fetch_col('SELECT COUNT(*) FROM users WHERE status = "active"', [], 0),
    'suspended' => (int) fetch_col('SELECT COUNT(*) FROM users WHERE status = "suspended"', [], 0),
    'banned'    => (int) fetch_col('SELECT COUNT(*) FROM users WHERE status = "banned"', [], 0),
];

require __DIR__ . '/includes/layout.php';
?>

<div class="adm-stats">
  <div class="adm-stat"><span class="as-ico">&#128101;</span>
    <div class="as-num"><?= num($counts['all']) ?></div><div class="as-lbl">Total players</div></div>
  <div class="adm-stat green"><span class="as-ico">&#10003;</span>
    <div class="as-num"><?= num($counts['active']) ?></div><div class="as-lbl">Active</div></div>
  <div class="adm-stat gold"><span class="as-ico">&#9888;</span>
    <div class="as-num"><?= num($counts['suspended']) ?></div><div class="as-lbl">Suspended</div></div>
  <div class="adm-stat red"><span class="as-ico">&#128683;</span>
    <div class="as-num"><?= num($counts['banned']) ?></div><div class="as-lbl">Banned</div></div>
</div>

<div class="adm-panel">
  <form method="get" class="adm-toolbar">
    <input type="text" name="q" class="form-control grow" value="<?= e($search) ?>"
           placeholder="Search username, name, email or phone…">
    <select name="status" class="form-select" style="width:auto">
      <option value="">Any status</option>
      <?php foreach (['active', 'suspended', 'banned'] as $st): ?>
        <option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="sort" class="form-select" style="width:auto">
      <option value="new"    <?= $sort === 'new' ? 'selected' : '' ?>>Newest first</option>
      <option value="old"    <?= $sort === 'old' ? 'selected' : '' ?>>Oldest first</option>
      <option value="coins"  <?= $sort === 'coins' ? 'selected' : '' ?>>Most coins</option>
      <option value="xp"     <?= $sort === 'xp' ? 'selected' : '' ?>>Most XP</option>
      <option value="active" <?= $sort === 'active' ? 'selected' : '' ?>>Recently active</option>
      <option value="name"   <?= $sort === 'name' ? 'selected' : '' ?>>Username A&ndash;Z</option>
    </select>
    <button class="btn btn-primary btn-sm" type="submit">Apply</button>
    <?php if ($search !== '' || $status !== '' || $sort !== 'new'): ?>
      <a href="<?= admin_url('users.php') ?>" class="btn btn-ghost btn-sm">Reset</a>
    <?php endif; ?>
  </form>

  <div class="adm-panel-body flush">
    <?php if (!$users): ?>
      <div class="empty"><div class="em-ico">&#128101;</div>
        <h3>No players match</h3><p>Try a different search or clear the filters.</p></div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="tbl" style="min-width:1000px">
          <thead>
            <tr><th>Player</th><th>Contact</th><th>Level</th><th>Coins</th>
                <th>Rounds</th><th>Points</th><th>Status</th><th>Spin</th><th>Joined</th>
                <th style="text-align:right">Manage</th></tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): ?>
              <tr>
                <td>
                  <div class="u-cell">
                    <img src="<?= e(avatar_url($u)) ?>" alt="">
                    <div>
                      <div class="u-name"><?= e($u['username']) ?></div>
                      <div class="u-sub"><?= e($u['name']) ?></div>
                    </div>
                  </div>
                </td>
                <td class="text-muted" style="font-size:.82rem">
                  <?= e($u['email']) ?><?php if ($u['phone']): ?><br><?= e($u['phone']) ?><?php endif; ?>
                </td>
                <td><span class="badge badge-green">Lv <?= (int) $u['level'] ?></span></td>
                <td class="text-gold"><strong><?= num($u['coins']) ?></strong></td>
                <td><?= num($u['rounds']) ?></td>
                <td class="text-green"><?= num($u['points']) ?></td>
                <td>
                  <form method="post" style="display:flex;gap:5px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="status">
                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <select name="status" class="form-select" style="padding:5px 26px 5px 9px;font-size:.76rem"
                            onchange="this.form.submit()">
                      <?php foreach (['active', 'suspended', 'banned'] as $st): ?>
                        <option value="<?= $st ?>" <?= $u['status'] === $st ? 'selected' : '' ?>>
                          <?= ucfirst($st) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                </td>
                <td>
                  <?php /* Spin access: follow the site rule, always allow, or never. */ ?>
                  <form method="post" style="display:flex;gap:5px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="spin_access">
                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <select name="spin_access" class="form-select"
                            style="padding:5px 26px 5px 9px;font-size:.76rem"
                            onchange="this.form.submit()">
                      <?php foreach (['default' => 'Site rule', 'allowed' => 'Allowed',
                                      'blocked' => 'Blocked'] as $k => $lbl): ?>
                        <option value="<?= $k ?>"
                          <?= ($u['spin_access'] ?? 'default') === $k ? 'selected' : '' ?>>
                          <?= $lbl ?></option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                </td>
                <td class="text-dim nowrap"><?= fdate($u['created_at'], 'd M y') ?></td>
                <td>
                  <div class="adm-actions">
                    <form method="post" style="display:flex;gap:4px">
                      <?= csrf_field() ?>
                      <input type="hidden" name="do" value="coins">
                      <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                      <input type="number" name="amount" class="form-control"
                             style="width:78px;padding:5px 8px;font-size:.78rem" placeholder="±coins">
                      <button class="btn btn-ghost btn-xs" type="submit">Set</button>
                    </form>
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="do" value="reset_pass">
                      <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                      <button class="btn btn-ghost btn-xs" type="submit"
                        data-confirm="Generate a new temporary password for <?= e($u['username']) ?>?">
                        Reset PW</button>
                    </form>
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="do" value="delete">
                      <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                      <button class="btn btn-danger btn-xs" type="submit"
                        data-confirm="Permanently delete <?= e($u['username']) ?> and all their scores?">
                        Delete</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($pages > 1): ?>
  <nav class="pagination">
    <?php if ($page > 1): ?><a href="<?= ul(['page' => $page - 1]) ?>">&lsaquo;</a><?php endif; ?>
    <?php
    $start = max(1, $page - 3);
    $end   = min($pages, $page + 3);
    for ($i = $start; $i <= $end; $i++): ?>
      <?php if ($i === $page): ?><span class="current"><?= $i ?></span>
      <?php else: ?><a href="<?= ul(['page' => $i]) ?>"><?= $i ?></a><?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $pages): ?><a href="<?= ul(['page' => $page + 1]) ?>">&rsaquo;</a><?php endif; ?>
  </nav>
<?php endif; ?>

<p class="text-dim center mt-2"><?= num($total) ?> player<?= $total === 1 ? '' : 's' ?> matching</p>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
