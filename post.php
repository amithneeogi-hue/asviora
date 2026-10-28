<?php
require_once __DIR__ . '/config/config.php';

$slug = get('slug');
$post = fetch_one(
    'SELECT p.*, c.name AS cat_name, c.slug AS cat_slug, a.name AS author, a.avatar AS author_avatar
     FROM blog_posts p
     LEFT JOIN blog_categories c ON c.id = p.category_id
     LEFT JOIN admins a ON a.id = p.author_id
     WHERE p.slug = ? AND p.status = "published"', [$slug]);

if (!$post) {
    http_response_code(404);
    $page_title = 'Article not found';
    require __DIR__ . '/includes/header.php';
    echo '<section class="section"><div class="container"><div class="card empty">'
       . '<div class="em-ico">&#128533;</div><h3>That article is not here</h3>'
       . '<p>It may have been unpublished or the link is wrong.</p>'
       . '<a href="' . url('blog.php') . '" class="btn btn-primary">Back to the blog</a>'
       . '</div></div></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

// count a view once per session per post
$seen = $_SESSION['seen_posts'] ?? [];
if (!in_array((int) $post['id'], $seen, true)) {
    q('UPDATE blog_posts SET views = views + 1 WHERE id = ?', [$post['id']]);
    $seen[] = (int) $post['id'];
    $_SESSION['seen_posts'] = $seen;
    $post['views']++;
}

$page_title = $post['meta_title'] !== null && $post['meta_title'] !== ''
            ? $post['meta_title'] : $post['title'];
$page_desc  = $post['meta_desc'] !== null && $post['meta_desc'] !== ''
            ? $post['meta_desc'] : ($post['excerpt'] ?: excerpt($post['content'], 155));

$cover = cover_url($post['cover_image']);

// v7 SEO — the post's own key, cover as the share image, and Article data
$seo_key    = 'post:' . $post['slug'];
$seo_type   = 'article';
$seo_image  = $cover ?: '';
$seo_crumbs = ['Blog' => url('blog.php'), $post['title'] => url('post.php?slug=' . urlencode($post['slug']))];
$seo_schema = [[
    '@context'      => 'https://schema.org',
    '@type'         => 'BlogPosting',
    'headline'      => mb_substr($post['title'], 0, 110),
    'description'   => html_entity_decode(strip_tags((string) $page_desc), ENT_QUOTES, 'UTF-8'),
    'image'         => $cover ?: logo_wide(),
    'datePublished' => date('c', strtotime($post['published_at'] ?: $post['created_at'])),
    'dateModified'  => date('c', strtotime($post['updated_at'] ?? ($post['published_at'] ?: $post['created_at']))),
    'author'        => ['@type' => 'Person', 'name' => $post['author'] ?: setting('site_name', 'Asviora Games')],
    'publisher'     => ['@type' => 'Organization', 'name' => setting('site_name', 'Asviora Games'),
                        'logo' => ['@type' => 'ImageObject', 'url' => logo_icon(512)]],
    'mainEntityOfPage' => url('post.php?slug=' . urlencode($post['slug'])),
]];

$related = fetch_all(
    'SELECT title, slug, cover_image, published_at, excerpt FROM blog_posts
     WHERE status = "published" AND id <> ?
       AND (category_id = ? OR ? IS NULL)
     ORDER BY published_at DESC LIMIT 3',
    [$post['id'], $post['category_id'], $post['category_id']]);

if (count($related) < 3) {
    $related = fetch_all(
        'SELECT title, slug, cover_image, published_at, excerpt FROM blog_posts
         WHERE status = "published" AND id <> ? ORDER BY published_at DESC LIMIT 3',
        [$post['id']]);
}

$readMins = max(1, (int) round(str_word_count(strip_tags((string) $post['content'])) / 210));
$shareUrl = urlencode(url('post.php?slug=' . $post['slug']));
$shareTxt = urlencode($post['title']);

require __DIR__ . '/includes/header.php';
?>

<article class="section-sm">
  <div class="container">

    <div class="article">
      <div class="crumb">
        <a href="<?= url('index.php') ?>">Home</a> &rsaquo;
        <a href="<?= url('blog.php') ?>">Blog</a>
        <?php if ($post['cat_name']): ?>
          &rsaquo; <a href="<?= url('blog.php?cat=' . urlencode($post['cat_slug'])) ?>"><?= e($post['cat_name']) ?></a>
        <?php endif; ?>
      </div>

      <div class="post-meta" style="margin-bottom:14px">
        <?php if ($post['cat_name']): ?>
          <a href="<?= url('blog.php?cat=' . urlencode($post['cat_slug'])) ?>"
             class="badge badge-green"><?= e($post['cat_name']) ?></a>
        <?php endif; ?>
        <span><?= fdate($post['published_at'], 'd F Y') ?></span>
        <span>&middot; <?= $readMins ?> min read</span>
        <span>&middot; <?= num($post['views']) ?> views</span>
      </div>

      <h1 style="margin-bottom:18px"><?= e($post['title']) ?></h1>

      <?php if ($post['excerpt']): ?>
        <p class="text-muted" style="font-size:1.1rem;margin-bottom:26px"><?= e($post['excerpt']) ?></p>
      <?php endif; ?>

      <div class="row between" style="padding:16px 0;border-top:1px solid var(--line-soft);
                                     border-bottom:1px solid var(--line-soft);margin-bottom:30px">
        <div class="u-cell">
          <img src="<?= e(avatar_url(['username' => $post['author'] ?: 'Asviora'])) ?>" alt="">
          <div>
            <div class="u-name"><?= e($post['author'] ?: 'Asviora Team') ?></div>
            <div class="u-sub">Author</div>
          </div>
        </div>
        <div class="row" style="gap:7px">
          <a class="btn btn-ghost btn-xs" target="_blank" rel="noopener"
             href="https://www.facebook.com/sharer/sharer.php?u=<?= $shareUrl ?>">Share</a>
          <a class="btn btn-ghost btn-xs" target="_blank" rel="noopener"
             href="https://twitter.com/intent/tweet?url=<?= $shareUrl ?>&text=<?= $shareTxt ?>">Post</a>
          <a class="btn btn-ghost btn-xs" target="_blank" rel="noopener"
             href="https://api.whatsapp.com/send?text=<?= $shareTxt ?>%20<?= $shareUrl ?>">WhatsApp</a>
        </div>
      </div>

      <?php if ($cover): ?>
        <div class="article-hero">
          <img src="<?= e($cover) ?>" alt="<?= e($post['title']) ?>">
        </div>
      <?php endif; ?>

      <div class="article-content">
        <?= safe_html((string) $post['content']) ?>
      </div>

      <?php if ($post['tags']): ?>
        <div class="row mt-4" style="gap:7px">
          <?php foreach (array_filter(array_map('trim', explode(',', $post['tags']))) as $tag): ?>
            <a href="<?= url('blog.php?q=' . urlencode($tag)) ?>" class="badge">#<?= e($tag) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="cta-band mt-4" style="border-radius:var(--radius);padding:34px 26px">
        <h3 style="margin-bottom:6px">Put it into practice</h3>
        <p class="text-muted" style="margin:0 0 18px">
          Open the arena and see whether the theory holds up on the clock.</p>
        <a href="<?= url('games.php') ?>" class="btn btn-primary">Play a game</a>
      </div>
    </div>

    <?php if ($related): ?>
      <div class="mt-4" style="max-width:1000px;margin-inline:auto">
        <h2 style="font-size:1.4rem;margin-bottom:22px">Keep reading</h2>
        <div class="grid g-3">
          <?php foreach ($related as $r): $rc = cover_url($r['cover_image']); ?>
            <article class="card card-hover post-card reveal">
              <a href="<?= url('post.php?slug=' . urlencode($r['slug'])) ?>" class="post-cover">
                <?php if ($rc): ?>
                  <img src="<?= e($rc) ?>" alt="<?= e($r['title']) ?>" loading="lazy">
                <?php else: ?>
                  <span class="pc-fallback">&#128221;</span>
                <?php endif; ?>
              </a>
              <div class="post-body">
                <div class="post-meta"><span><?= fdate($r['published_at']) ?></span></div>
                <h3><a href="<?= url('post.php?slug=' . urlencode($r['slug'])) ?>"><?= e($r['title']) ?></a></h3>
                <p><?= e($r['excerpt'] ?: '') ?></p>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

  </div>
</article>

<?php require __DIR__ . '/includes/footer.php'; ?>
