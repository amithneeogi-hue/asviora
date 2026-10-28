<?php
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();

$admin_title = 'Blog Posts';
$admin_sub   = 'Write, edit and publish articles';
$admin_actions = '<a href="' . admin_url('blog-edit.php') . '" class="btn btn-primary btn-sm">&#10133; New Post</a>';

/* ---------------- actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $id  = (int) post('id');
    $act = post('do');

    if ($id && $act === 'delete') {
        $row = fetch_one('SELECT cover_image FROM blog_posts WHERE id = ?', [$id]);
        if ($row) {
            delete_upload($row['cover_image'], 'blog');
            q('DELETE FROM blog_posts WHERE id = ?', [$id]);
            flash('Post deleted.');
        }
    } elseif ($id && $act === 'toggle') {
        $row = fetch_one('SELECT status FROM blog_posts WHERE id = ?', [$id]);
        if ($row) {
            $new = $row['status'] === 'published' ? 'draft' : 'published';
            $data = ['status' => $new];
            if ($new === 'published') $data['published_at'] = date('Y-m-d H:i:s');
            update_row('blog_posts', $data, 'id = ?', [$id]);
            flash('Post is now ' . $new . '.');
        }
    } elseif ($id && $act === 'feature') {
        q('UPDATE blog_posts SET is_featured = 1 - is_featured WHERE id = ?', [$id]);
        flash('Featured flag updated.');
    }
    redirect('admin/blog.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
}

/* ---------------- filters ---------------- */
$status = get('status');
$catId  = (int) get('cat');
$search = get('q');
$page   = max(1, (int) get('page', '1'));
$per    = 15;

$where = ['1 = 1'];
$params = [];
if (in_array($status, ['draft', 'published'], true)) { $where[] = 'p.status = ?'; $params[] = $status; }
if ($catId) { $where[] = 'p.category_id = ?'; $params[] = $catId; }
if ($search !== '') {
    $where[] = '(p.title LIKE ? OR p.tags LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$w = implode(' AND ', $where);

$total  = (int) fetch_col("SELECT COUNT(*) FROM blog_posts p WHERE $w", $params, 0);
$pages  = max(1, (int) ceil($total / $per));
$page   = min($page, $pages);
$offset = ($page - 1) * $per;

$posts = fetch_all(
    "SELECT p.*, c.name AS cat_name, a.name AS author
     FROM blog_posts p
     LEFT JOIN blog_categories c ON c.id = p.category_id
     LEFT JOIN admins a ON a.id = p.author_id
     WHERE $w ORDER BY p.id DESC LIMIT $per OFFSET $offset", $params);

$cats = fetch_all('SELECT id, name FROM blog_categories ORDER BY name');

function bl(array $over = []): string
{
    $q = array_merge(['status' => get('status'), 'cat' => get('cat'),
                      'q' => get('q'), 'page' => get('page')], $over);
    $q = array_filter($q, function ($v) { return $v !== '' && $v !== null && $v !== '0'; });
    return admin_url('blog.php' . ($q ? '?' . http_build_query($q) : ''));
}

require __DIR__ . '/includes/layout.php';
?>

<div class="adm-panel">
  <form method="get" class="adm-toolbar">
    <input type="text" name="q" class="form-control grow" value="<?= e($search) ?>"
           placeholder="Search by title or tag…">
    <select name="status" class="form-select" style="width:auto">
      <option value="">All statuses</option>
      <option value="published" <?= $status === 'published' ? 'selected' : '' ?>>Published</option>
      <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
    </select>
    <select name="cat" class="form-select" style="width:auto">
      <option value="">All categories</option>
      <?php foreach ($cats as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= $catId === (int) $c['id'] ? 'selected' : '' ?>>
          <?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary btn-sm" type="submit">Filter</button>
    <?php if ($search !== '' || $status !== '' || $catId): ?>
      <a href="<?= admin_url('blog.php') ?>" class="btn btn-ghost btn-sm">Reset</a>
    <?php endif; ?>
  </form>

  <div class="adm-panel-body flush">
    <?php if (!$posts): ?>
      <div class="empty">
        <div class="em-ico">&#128221;</div>
        <h3>No posts match</h3>
        <p>Write your first article, or clear the filters above.</p>
        <a href="<?= admin_url('blog-edit.php') ?>" class="btn btn-primary">Write a post</a>
      </div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="tbl" style="min-width:900px">
          <thead>
            <tr><th style="width:64px">Cover</th><th>Title</th><th>Category</th>
                <th>Status</th><th>Views</th><th>Date</th><th style="text-align:right">Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($posts as $p): $cov = cover_url($p['cover_image']); ?>
              <tr>
                <td>
                  <div style="width:52px;height:38px;border-radius:8px;overflow:hidden;
                              background:var(--surface-2);display:grid;place-items:center">
                    <?php if ($cov): ?>
                      <img src="<?= e($cov) ?>" alt="" style="width:100%;height:100%;object-fit:cover">
                    <?php else: ?>
                      <span style="opacity:.4">&#128221;</span>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <strong style="display:block"><?= e($p['title']) ?></strong>
                  <span class="text-dim">
                    /<?= e($p['slug']) ?>
                    <?php if ($p['is_featured']): ?>
                      &nbsp;<span class="badge badge-gold">featured</span>
                    <?php endif; ?>
                  </span>
                </td>
                <td class="text-muted"><?= e($p['cat_name'] ?: '—') ?></td>
                <td>
                  <span class="badge <?= $p['status'] === 'published' ? 'badge-green' : '' ?>">
                    <?= e($p['status']) ?></span>
                </td>
                <td><?= num($p['views']) ?></td>
                <td class="text-dim nowrap"><?= fdate($p['published_at'] ?: $p['created_at'], 'd M Y') ?></td>
                <td>
                  <div class="adm-actions">
                    <?php if ($p['status'] === 'published'): ?>
                      <a href="<?= url('post.php?slug=' . urlencode($p['slug'])) ?>" target="_blank"
                         rel="noopener" class="btn btn-ghost btn-xs">View</a>
                    <?php endif; ?>
                    <a href="<?= admin_url('blog-edit.php?id=' . (int) $p['id']) ?>"
                       class="btn btn-ghost btn-xs">Edit</a>
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                      <input type="hidden" name="do" value="toggle">
                      <button class="btn btn-ghost btn-xs" type="submit">
                        <?= $p['status'] === 'published' ? 'Unpublish' : 'Publish' ?></button>
                    </form>
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                      <input type="hidden" name="do" value="feature">
                      <button class="btn btn-ghost btn-xs" type="submit" title="Toggle featured">&#9733;</button>
                    </form>
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                      <input type="hidden" name="do" value="delete">
                      <button class="btn btn-danger btn-xs" type="submit"
                        data-confirm="Delete &quot;<?= e($p['title']) ?>&quot; permanently?">Delete</button>
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
    <?php if ($page > 1): ?><a href="<?= bl(['page' => $page - 1]) ?>">&lsaquo;</a><?php endif; ?>
    <?php for ($i = 1; $i <= $pages; $i++): ?>
      <?php if ($i === $page): ?><span class="current"><?= $i ?></span>
      <?php else: ?><a href="<?= bl(['page' => $i]) ?>"><?= $i ?></a><?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $pages): ?><a href="<?= bl(['page' => $page + 1]) ?>">&rsaquo;</a><?php endif; ?>
  </nav>
<?php endif; ?>

<p class="text-dim center mt-2"><?= num($total) ?> post<?= $total === 1 ? '' : 's' ?> total</p>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
