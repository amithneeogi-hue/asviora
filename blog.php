<?php
require_once __DIR__ . '/config/config.php';

$page_title = 'Blog';
$page_desc  = 'Game guides, strategy breakdowns and platform updates from the Asviora Games team.';

$catSlug = get('cat');
$search  = get('q');
$page    = max(1, (int) get('page', '1'));
$per     = POSTS_PER_PAGE;

$cats = fetch_all(
    'SELECT c.*, COUNT(p.id) AS total
     FROM blog_categories c
     LEFT JOIN blog_posts p ON p.category_id = c.id AND p.status = "published"
     GROUP BY c.id ORDER BY c.name');

$where  = ['p.status = "published"'];
$params = [];

$activeCat = null;
if ($catSlug !== '') {
    $activeCat = fetch_one('SELECT * FROM blog_categories WHERE slug = ?', [$catSlug]);
    if ($activeCat) {
        $where[] = 'p.category_id = ?';
        $params[] = $activeCat['id'];
    }
}
if ($search !== '') {
    $where[] = '(p.title LIKE ? OR p.excerpt LIKE ? OR p.tags LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);

$total  = (int) fetch_col("SELECT COUNT(*) FROM blog_posts p WHERE $whereSql", $params, 0);
$pages  = max(1, (int) ceil($total / $per));
$page   = min($page, $pages);
$offset = ($page - 1) * $per;

$posts = fetch_all(
    "SELECT p.*, c.name AS cat_name, c.slug AS cat_slug, a.name AS author
     FROM blog_posts p
     LEFT JOIN blog_categories c ON c.id = p.category_id
     LEFT JOIN admins a ON a.id = p.author_id
     WHERE $whereSql
     ORDER BY p.is_featured DESC, p.published_at DESC, p.id DESC
     LIMIT $per OFFSET $offset", $params);

$popular = fetch_all(
    'SELECT title, slug, views, published_at FROM blog_posts
     WHERE status = "published" ORDER BY views DESC, published_at DESC LIMIT 5');

function blog_link(array $over = []): string
{
    $q = array_merge(['cat' => get('cat'), 'q' => get('q'), 'page' => get('page')], $over);
    $q = array_filter($q, function ($v) { return $v !== '' && $v !== null; });
    return url('blog.php' . ($q ? '?' . http_build_query($q) : ''));
}

require __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="container">
    <div class="crumb"><a href="<?= url('index.php') ?>">Home</a> &rsaquo; Blog</div>
    <h1><?= $activeCat ? e($activeCat['name']) : 'The <span class="gradient-text">Asviora</span> Blog' ?></h1>
    <p><?= $activeCat
        ? 'Every published article filed under ' . e($activeCat['name']) . '.'
        : 'Guides that make you measurably better, plus news on what we are shipping next.' ?></p>
  </div>
</section>

<section class="section">
  <div class="container">

    <div class="grid" style="grid-template-columns:1fr 300px;gap:32px;align-items:start">

      <!-- ---------- posts ---------- -->
      <div>
        <?php if ($search !== ''): ?>
          <div class="alert alert-info" data-persist="1">
            <span>&#128269;</span>
            <?= $total ?> result<?= $total === 1 ? '' : 's' ?> for
            &ldquo;<?= e($search) ?>&rdquo;. <a href="<?= url('blog.php') ?>"
            style="text-decoration:underline">Clear search</a>
          </div>
        <?php endif; ?>

        <?php if (!$posts): ?>
          <div class="card empty">
            <div class="em-ico">&#128221;</div>
            <h3>No articles here yet</h3>
            <p><?= $search !== '' || $activeCat
                  ? 'Nothing matched that filter. Try a broader search.'
                  : 'The first post is on its way. Check back shortly.' ?></p>
            <a href="<?= url('blog.php') ?>" class="btn btn-primary">All articles</a>
          </div>
        <?php else: ?>
          <div class="grid g-2">
            <?php foreach ($posts as $p): $cov = cover_url($p['cover_image']); ?>
              <article class="card card-hover post-card reveal">
                <a href="<?= url('post.php?slug=' . urlencode($p['slug'])) ?>" class="post-cover">
                  <?php if ($cov): ?>
                    <img src="<?= e($cov) ?>" alt="<?= e($p['title']) ?>" loading="lazy">
                  <?php else: ?>
                    <span class="pc-fallback">&#128221;</span>
                  <?php endif; ?>
                </a>
                <div class="post-body">
                  <div class="post-meta">
                    <?php if ($p['cat_name']): ?>
                      <a href="<?= url('blog.php?cat=' . urlencode($p['cat_slug'])) ?>"
                         class="badge badge-green"><?= e($p['cat_name']) ?></a>
                    <?php endif; ?>
                    <?php if ($p['is_featured']): ?>
                      <span class="badge badge-gold">Featured</span>
                    <?php endif; ?>
                    <span><?= fdate($p['published_at']) ?></span>
                    <span>&middot; <?= num($p['views']) ?> views</span>
                  </div>
                  <h3><a href="<?= url('post.php?slug=' . urlencode($p['slug'])) ?>"><?= e($p['title']) ?></a></h3>
                  <p><?= e($p['excerpt'] ?: excerpt($p['content'], 140)) ?></p>
                  <a href="<?= url('post.php?slug=' . urlencode($p['slug'])) ?>"
                     class="btn btn-ghost btn-sm">Read article &rarr;</a>
                </div>
              </article>
            <?php endforeach; ?>
          </div>

          <?php if ($pages > 1): ?>
            <nav class="pagination">
              <?php if ($page > 1): ?>
                <a href="<?= blog_link(['page' => $page - 1]) ?>">&lsaquo;</a>
              <?php endif; ?>
              <?php for ($i = 1; $i <= $pages; $i++): ?>
                <?php if ($i === $page): ?>
                  <span class="current"><?= $i ?></span>
                <?php else: ?>
                  <a href="<?= blog_link(['page' => $i]) ?>"><?= $i ?></a>
                <?php endif; ?>
              <?php endfor; ?>
              <?php if ($page < $pages): ?>
                <a href="<?= blog_link(['page' => $page + 1]) ?>">&rsaquo;</a>
              <?php endif; ?>
            </nav>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <!-- ---------- sidebar ---------- -->
      <aside>
        <div class="card mb-3">
          <h3 style="font-size:1.02rem">Search articles</h3>
          <form method="get" action="<?= url('blog.php') ?>" class="row" style="gap:8px">
            <input type="text" name="q" class="form-control" value="<?= e($search) ?>"
                   placeholder="Sudoku, cube, tips…" style="flex:1;min-width:0">
            <button class="btn btn-primary btn-sm" type="submit">Go</button>
          </form>
        </div>

        <div class="card mb-3">
          <h3 style="font-size:1.02rem">Categories</h3>
          <a href="<?= url('blog.php') ?>" class="kv" style="text-decoration:none">
            <span class="k" style="color:<?= $activeCat ? 'var(--muted)' : 'var(--green)' ?>">All articles</span>
            <span class="v"><?= (int) fetch_col('SELECT COUNT(*) FROM blog_posts WHERE status="published"', [], 0) ?></span>
          </a>
          <?php foreach ($cats as $c): ?>
            <a href="<?= url('blog.php?cat=' . urlencode($c['slug'])) ?>" class="kv" style="text-decoration:none">
              <span class="k" style="color:<?= ($activeCat && $activeCat['id'] == $c['id']) ? 'var(--green)' : 'var(--muted)' ?>">
                <?= e($c['name']) ?></span>
              <span class="v"><?= (int) $c['total'] ?></span>
            </a>
          <?php endforeach; ?>
        </div>

        <?php if ($popular): ?>
        <div class="card mb-3">
          <h3 style="font-size:1.02rem">Most read</h3>
          <?php foreach ($popular as $i => $pp): ?>
            <a href="<?= url('post.php?slug=' . urlencode($pp['slug'])) ?>"
               class="row" style="gap:11px;padding:11px 0;border-top:1px solid var(--line-soft);align-items:flex-start">
              <span style="font-weight:900;color:var(--gold);flex-shrink:0"><?= $i + 1 ?>.</span>
              <span>
                <span style="display:block;font-weight:700;color:var(--text);font-size:.9rem;line-height:1.4">
                  <?= e($pp['title']) ?></span>
                <span class="text-dim"><?= fdate($pp['published_at']) ?> &middot; <?= num($pp['views']) ?> views</span>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="card">
          <h3 style="font-size:1.02rem">Play while you read</h3>
          <p class="text-muted" style="font-size:.88rem">Try the techniques from these guides
            straight away.</p>
          <a href="<?= url('games.php') ?>" class="btn btn-primary btn-sm btn-block">Open the arena</a>
        </div>
      </aside>

    </div>
  </div>
</section>

<style>
@media (max-width: 980px) {
  section.section > .container > .grid[style*="300px"] { grid-template-columns: 1fr !important; }
}
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
