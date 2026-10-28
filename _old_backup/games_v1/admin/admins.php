<?php
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();

if ($A['role'] !== 'super') {
    flash('Only a super admin can manage admin accounts.', 'error');
    redirect('admin/index.php');
}

$admin_title = 'Admin Users';
$admin_sub   = 'Who can sign in to this control panel';

$editId = (int) get('edit');
$edit   = $editId ? fetch_one('SELECT * FROM admins WHERE id = ?', [$editId]) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $do = post('do');
    $id = (int) post('id');

    if ($do === 'save') {
        $name  = post('name');
        $uname = strtolower(post('username'));
        $email = strtolower(post('email'));
        $role  = in_array(post('role'), ['super', 'manager', 'editor'], true) ? post('role') : 'editor';
        $pass  = (string) ($_POST['password'] ?? '');
        $err   = [];

        if (mb_strlen($name) < 2) $err[] = 'Enter a name.';
        if (!preg_match('/^[a-z0-9_.]{3,30}$/', $uname)) {
            $err[] = 'Username must be 3–30 characters: lowercase letters, numbers, dot or underscore.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err[] = 'Enter a valid email address.';
        if (!$id && strlen($pass) < 8) $err[] = 'Set a password of at least 8 characters.';
        if ($pass !== '' && strlen($pass) < 8) $err[] = 'The new password must be at least 8 characters.';

        if (!$err) {
            $clash = fetch_one(
                'SELECT id FROM admins WHERE (username = ? OR email = ?) AND id <> ?',
                [$uname, $email, $id ?: 0]);
            if ($clash) $err[] = 'Another admin already uses that username or email.';
        }

        if ($err) {
            foreach ($err as $x) flash($x, 'error');
        } else {
            $data = [
                'name'      => $name,
                'username'  => $uname,
                'email'     => $email,
                'role'      => $role,
                'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            ];
            if ($pass !== '') {
                $data['password_hash'] = password_hash($pass, PASSWORD_DEFAULT);
            }
            if ($id) {
                // never let the last super admin demote or disable themselves out of access
                $supers = (int) fetch_col('SELECT COUNT(*) FROM admins WHERE role = "super" AND is_active = 1', [], 0);
                $target = fetch_one('SELECT role, is_active FROM admins WHERE id = ?', [$id]);
                if ($target && $target['role'] === 'super' && $supers <= 1
                    && ($role !== 'super' || !$data['is_active'])) {
                    flash('You cannot remove the last active super admin.', 'error');
                    redirect('admin/admins.php');
                }
                update_row('admins', $data, 'id = ?', [$id]);
                flash('Admin updated.');
            } else {
                $data['password_hash'] = $data['password_hash'] ?? password_hash($pass, PASSWORD_DEFAULT);
                insert_row('admins', $data);
                flash('Admin created.');
            }
            redirect('admin/admins.php');
        }
    } elseif ($do === 'delete') {
        if ($id === (int) $A['id']) {
            flash('You cannot delete your own account.', 'error');
        } else {
            $supers = (int) fetch_col('SELECT COUNT(*) FROM admins WHERE role = "super"', [], 0);
            $t = fetch_one('SELECT role, name FROM admins WHERE id = ?', [$id]);
            if ($t && $t['role'] === 'super' && $supers <= 1) {
                flash('You cannot delete the last super admin.', 'error');
            } elseif ($t) {
                q('DELETE FROM admins WHERE id = ?', [$id]);
                flash($t['name'] . ' removed.');
            }
        }
        redirect('admin/admins.php');
    }
}

$admins = fetch_all(
    'SELECT a.*, (SELECT COUNT(*) FROM blog_posts p WHERE p.author_id = a.id) AS posts
     FROM admins a ORDER BY a.id');

$roleInfo = [
    'super'   => 'Full access, including admin user management',
    'manager' => 'Everything except admin user management',
    'editor'  => 'Blog, categories, messages and subscribers only',
];

require __DIR__ . '/includes/layout.php';
?>

<div class="adm-form-grid">

  <div class="adm-panel">
    <div class="adm-panel-head">
      <div><h2>Admin accounts</h2><p><?= count($admins) ?> total</p></div>
    </div>
    <div class="adm-panel-body flush">
      <div class="table-scroll">
        <table class="tbl" style="min-width:640px">
          <thead><tr><th>Name</th><th>Role</th><th>Posts</th><th>Last login</th>
                     <th>Status</th><th style="text-align:right"></th></tr></thead>
          <tbody>
            <?php foreach ($admins as $ad): ?>
              <tr>
                <td>
                  <strong style="display:block"><?= e($ad['name']) ?>
                    <?php if ((int) $ad['id'] === (int) $A['id']): ?>
                      <span class="badge badge-green">you</span>
                    <?php endif; ?>
                  </strong>
                  <span class="text-dim">@<?= e($ad['username']) ?> &middot; <?= e($ad['email']) ?></span>
                </td>
                <td><span class="badge <?= $ad['role'] === 'super' ? 'badge-gold' : 'badge-green' ?>">
                  <?= e($ad['role']) ?></span></td>
                <td><?= num($ad['posts']) ?></td>
                <td class="text-dim nowrap"><?= fdate($ad['last_login'], 'd M y, H:i') ?></td>
                <td><span class="badge <?= $ad['is_active'] ? 'badge-green' : 'badge-red' ?>">
                  <?= $ad['is_active'] ? 'active' : 'disabled' ?></span></td>
                <td>
                  <div class="adm-actions">
                    <a href="<?= admin_url('admins.php?edit=' . (int) $ad['id']) ?>"
                       class="btn btn-ghost btn-xs">Edit</a>
                    <?php if ((int) $ad['id'] !== (int) $A['id']): ?>
                      <form method="post" style="display:inline">
                        <?= csrf_field() ?><input type="hidden" name="do" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $ad['id'] ?>">
                        <button class="btn btn-danger btn-xs" type="submit"
                          data-confirm="Delete admin <?= e($ad['name']) ?>?">Delete</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div>
    <div class="adm-panel">
      <div class="adm-panel-head">
        <h2><?= $edit ? 'Edit admin' : 'New admin' ?></h2>
        <?php if ($edit): ?>
          <a href="<?= admin_url('admins.php') ?>" class="btn btn-ghost btn-xs">Cancel</a>
        <?php endif; ?>
      </div>
      <div class="adm-panel-body">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="save">
          <input type="hidden" name="id" value="<?= $edit ? (int) $edit['id'] : '' ?>">

          <div class="form-group">
            <label class="form-label" for="name">Full name <span class="req">*</span></label>
            <input class="form-control" id="name" name="name"
                   value="<?= e($edit['name'] ?? '') ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="username">Username <span class="req">*</span></label>
            <input class="form-control" id="username" name="username"
                   value="<?= e($edit['username'] ?? '') ?>" pattern="[a-z0-9_.]{3,30}" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="email">Email <span class="req">*</span></label>
            <input class="form-control" type="email" id="email" name="email"
                   value="<?= e($edit['email'] ?? '') ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="role">Role</label>
            <select class="form-select" id="role" name="role">
              <?php foreach ($roleInfo as $r => $desc): ?>
                <option value="<?= $r ?>" <?= ($edit['role'] ?? 'editor') === $r ? 'selected' : '' ?>>
                  <?= ucfirst($r) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-hint" id="roleHint"><?= e($roleInfo[$edit['role'] ?? 'editor']) ?></div>
          </div>
          <div class="form-group">
            <label class="form-label" for="password">
              Password <?= $edit ? '' : '<span class="req">*</span>' ?></label>
            <input class="form-control" type="password" id="password" name="password"
                   minlength="8" placeholder="<?= $edit ? 'Leave blank to keep unchanged' : '8+ characters' ?>"
                   <?= $edit ? '' : 'required' ?>>
          </div>
          <div class="form-group">
            <label class="adm-switch">
              <input type="checkbox" name="is_active" value="1"
                     <?= (!$edit || $edit['is_active']) ? 'checked' : '' ?>>
              <span class="track"></span><span>Account is active</span>
            </label>
          </div>

          <button class="btn btn-primary btn-block" type="submit">
            <?= $edit ? 'Save Admin' : 'Create Admin' ?></button>
        </form>
      </div>
    </div>

    <div class="adm-panel">
      <div class="adm-panel-head"><h2>Role reference</h2></div>
      <div class="adm-panel-body">
        <?php foreach ($roleInfo as $r => $desc): ?>
          <div class="kv" style="align-items:flex-start">
            <span class="k" style="flex-shrink:0"><strong><?= ucfirst($r) ?></strong></span>
            <span class="v" style="text-align:right;font-weight:400;color:var(--muted)"><?= e($desc) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

</div>

<?php
$admin_foot = <<<'HTML'
<script>
(function () {
  var hints = {
    super:   'Full access, including admin user management',
    manager: 'Everything except admin user management',
    editor:  'Blog, categories, messages and subscribers only'
  };
  var sel = document.getElementById('role');
  var out = document.getElementById('roleHint');
  if (sel && out) sel.addEventListener('change', function () { out.textContent = hints[sel.value] || ''; });
})();
</script>
HTML;
require __DIR__ . '/includes/layout_end.php';
