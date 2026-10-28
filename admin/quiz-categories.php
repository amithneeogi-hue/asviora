<?php
/**
 * Admin — quiz topics.
 */
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();
require_manage();

$admin_title = 'Quiz Topics';
$admin_sub   = 'Group questions so players can pick a subject';

if (!has_table('quiz_categories')) {
    flash('The quiz tables are not installed. Import config/upgrade-quiz-spin.sql first.', 'error');
    redirect('admin/index.php');
}

$editId = (int) get('edit');
$edit   = $editId ? fetch_one('SELECT * FROM quiz_categories WHERE id = ?', [$editId]) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $do = post('do');

    if ($do === 'save') {
        $id   = (int) post('id');
        $name = post('name');
        if (mb_strlen($name) < 2) {
            flash('Topic name needs at least 2 characters.', 'error');
        } else {
            $data = [
                'name'       => $name,
                'slug'       => unique_slug('quiz_categories',
                                  post('slug') !== '' ? slugify(post('slug')) : slugify($name),
                                  $id ?: null),
                'icon'       => post('icon') !== '' ? post('icon') : null,
                'sort_order' => (int) post('sort_order'),
                'is_active'  => empty($_POST['is_active']) ? 0 : 1,
            ];
            if ($id) {
                update_row('quiz_categories', $data, 'id = ?', [$id]);
                flash('Topic updated.');
            } else {
                insert_row('quiz_categories', $data);
                flash('Topic created.');
            }
        }
    } elseif ($do === 'delete') {
        // questions survive; their category_id becomes NULL via the FK
        q('DELETE FROM quiz_categories WHERE id = ?', [(int) post('id')]);
        flash('Topic deleted. Its questions are now uncategorised.');
    }

    redirect('admin/quiz-categories.php');
}

$cats = fetch_all(
    'SELECT c.*,
            (SELECT COUNT(*) FROM quiz_questions q WHERE q.category_id = c.id) AS total,
            (SELECT COUNT(*) FROM quiz_questions q
              WHERE q.category_id = c.id AND q.is_active = 1) AS live
       FROM quiz_categories c ORDER BY c.sort_order, c.name');

require __DIR__ . '/includes/layout.php';
?>

<div class="adm-form-grid">

  <div class="adm-panel">
    <div class="adm-panel-head">
      <div><h2>All topics</h2><p><?= count($cats) ?> total</p></div>
      <a href="<?= admin_url('quiz.php') ?>" class="btn btn-ghost btn-sm">Question bank &rarr;</a>
    </div>
    <div class="adm-panel-body flush">
      <?php if (!$cats): ?>
        <div class="empty"><div class="em-ico">&#127991;</div>
          <h3>No topics yet</h3><p>Create the first one on the right.</p></div>
      <?php else: ?>
        <div class="table-scroll">
          <table class="tbl" style="min-width:540px">
            <thead><tr><th>Topic</th><th>Slug</th><th>Questions</th>
                       <th style="text-align:right">Actions</th></tr></thead>
            <tbody>
              <?php foreach ($cats as $c): ?>
                <tr style="<?= $c['is_active'] ? '' : 'opacity:.5' ?>">
                  <td><strong><?= $c['icon'] ?> <?= e($c['name']) ?></strong></td>
                  <td class="text-dim">/<?= e($c['slug']) ?></td>
                  <td><?= (int) $c['live'] ?> live
                      <span class="text-dim">/ <?= (int) $c['total'] ?></span></td>
                  <td>
                    <div class="adm-actions">
                      <a href="<?= admin_url('quiz.php?cat=' . (int) $c['id']) ?>"
                         class="btn btn-ghost btn-xs">Questions</a>
                      <a href="<?= admin_url('quiz-categories.php?edit=' . (int) $c['id']) ?>"
                         class="btn btn-ghost btn-xs">Edit</a>
                      <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="do" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <button class="btn btn-danger btn-xs" type="submit"
                          data-confirm="Delete &quot;<?= e($c['name']) ?>&quot;? Its <?= (int) $c['total'] ?> question(s) stay, but become uncategorised.">
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

  <div class="adm-panel">
    <div class="adm-panel-head"><h2><?= $edit ? 'Edit topic' : 'New topic' ?></h2></div>
    <div class="adm-panel-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save">
        <input type="hidden" name="id" value="<?= $edit ? (int) $edit['id'] : '' ?>">

        <div class="form-group">
          <label class="form-label" for="name">Name <span class="req">*</span></label>
          <input class="form-control" id="name" name="name"
                 value="<?= e($edit['name'] ?? '') ?>" placeholder="Gaming &amp; Esports" required>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="icon">Icon</label>
            <input class="form-control" id="icon" name="icon"
                   value="<?= e($edit['icon'] ?? '') ?>" placeholder="&#127918;">
            <div class="form-hint">An emoji, or an HTML entity like <code>&amp;#127918;</code></div>
          </div>
          <div class="form-group">
            <label class="form-label" for="sort_order">Order</label>
            <input class="form-control" type="number" id="sort_order" name="sort_order"
                   value="<?= (int) ($edit['sort_order'] ?? 0) ?>">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="slug">Slug</label>
          <input class="form-control" id="slug" name="slug"
                 value="<?= e($edit['slug'] ?? '') ?>" placeholder="auto from the name">
        </div>

        <div class="form-group">
          <label class="check">
            <input type="checkbox" name="is_active" value="1"
                   <?= (!$edit || $edit['is_active']) ? 'checked' : '' ?>>
            <span>Show this topic in the quiz lobby</span>
          </label>
        </div>

        <button class="btn btn-primary btn-block" type="submit">
          <?= $edit ? 'Save Topic' : 'Create Topic' ?></button>
        <?php if ($edit): ?>
          <a href="<?= admin_url('quiz-categories.php') ?>" class="btn btn-ghost btn-block mt-2">Cancel</a>
        <?php endif; ?>
      </form>
    </div>
  </div>

</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
