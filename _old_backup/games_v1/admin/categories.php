<?php
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();

$admin_title = 'Blog Categories';
$admin_sub   = 'Group your articles so readers can browse by topic';

$editId = (int) get('edit');
$edit   = $editId ? fetch_one('SELECT * FROM blog_categories WHERE id = ?', [$editId]) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $do = post('do');

    if ($do === 'save') {
        $id   = (int) post('id');
        $name = post('name');
        if (mb_strlen($name) < 2) {
            flash('Category name needs at least 2 characters.', 'error');
        } else {
            $slug = unique_slug('blog_categories',
                                post('slug') !== '' ? slugify(post('slug')) : slugify($name),
                                $id ?: null);
            if ($id) {
                update_row('blog_categories', ['name' => $name, 'slug' => $slug], 'id = ?', [$id]);
                flash('Category updated.');
            } else {
                insert_row('blog_categories', ['name' => $name, 'slug' => $slug]);
                flash('Category created.');
            }
        }
    } elseif ($do === 'delete') {
        $id = (int) post('id');
        // posts keep existing; their category_id becomes NULL via the FK
        q('DELETE FROM blog_categories WHERE id = ?', [$id]);
        flash('Category deleted. Its posts are now uncategorised.');
    }
    redirect('admin/categories.php');
}

$cats = fetch_all(
    'SELECT c.*,
            (SELECT COUNT(*) FROM blog_posts p WHERE p.category_id = c.id) AS total,
            (SELECT COUNT(*) FROM blog_posts p WHERE p.category_id = c.id AND p.status = "published") AS live
     FROM blog_categories c ORDER BY c.name');

require __DIR__ . '/includes/layout.php';
?>

<div class="adm-form-grid">

  <div class="adm-panel">
    <div class="adm-panel-head">
      <div><h2>All categories</h2><p><?= count($cats) ?> total</p></div>
    </div>
    <div class="adm-panel-body flush">
      <?php if (!$cats): ?>
        <div class="empty"><div class="em-ico">&#127991;</div>
          <h3>No categories yet</h3><p>Create your first one on the right.</p></div>
      <?php else: ?>
        <div class="table-scroll">
          <table class="tbl" style="min-width:520px">
            <thead><tr><th>Name</th><th>Slug</th><th>Posts</th>
                       <th style="text-align:right">Actions</th></tr></thead>
            <tbody>
              <?php foreach ($cats as $c): ?>
                <tr>
                  <td><strong><?= e($c['name']) ?></strong></td>
                  <td class="text-dim">/<?= e($c['slug']) ?></td>
                  <td><?= (int) $c['live'] ?> live
                    <span class="text-dim">/ <?= (int) $c['total'] ?> total</span></td>
                  <td>
                    <div class="adm-actions">
                      <a href="<?= url('blog.php?cat=' . urlencode($c['slug'])) ?>" target="_blank"
                         rel="noopener" class="btn btn-ghost btn-xs">View</a>
                      <a href="<?= admin_url('categories.php?edit=' . (int) $c['id']) ?>"
                         class="btn btn-ghost btn-xs">Edit</a>
                      <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="do" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <button class="btn btn-danger btn-xs" type="submit"
                          data-confirm="Delete &quot;<?= e($c['name']) ?>&quot;? Its <?= (int) $c['total'] ?> post(s) will become uncategorised.">
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
    <div class="adm-panel-head">
      <h2><?= $edit ? 'Edit category' : 'New category' ?></h2>
    </div>
    <div class="adm-panel-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save">
        <input type="hidden" name="id" value="<?= $edit ? (int) $edit['id'] : '' ?>">

        <div class="form-group">
          <label class="form-label" for="name">Name <span class="req">*</span></label>
          <input class="form-control" id="name" name="name"
                 value="<?= e($edit['name'] ?? '') ?>" placeholder="Strategy &amp; Tips" required>
        </div>

        <div class="form-group">
          <label class="form-label" for="slug">Slug</label>
          <input class="form-control" id="slug" name="slug"
                 value="<?= e($edit['slug'] ?? '') ?>" placeholder="auto from the name">
          <div class="form-hint">Used in the URL: /blog.php?cat=<strong>slug</strong></div>
        </div>

        <button class="btn btn-primary btn-block" type="submit">
          <?= $edit ? 'Save Category' : 'Create Category' ?></button>
        <?php if ($edit): ?>
          <a href="<?= admin_url('categories.php') ?>" class="btn btn-ghost btn-block mt-2">Cancel</a>
        <?php endif; ?>
      </form>
    </div>
  </div>

</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
