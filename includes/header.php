<?php
/**
 * Public site header. Set $page_title / $page_desc / $extra_head before including.
 */
if (!defined('ROOT_PATH')) {
    require_once __DIR__ . '/../config/config.php';
}

$site   = setting('site_name', 'Asviora Games');
$slogan = setting('site_tagline', 'Where Skill Wins');
$u      = current_user();

// v7 — title, description, canonical, robots, Open Graph and structured
// data all come from the SEO manager (includes/seo.php). Pages keep
// setting $page_title / $page_desc as before; $seo_key, $seo_image,
// $seo_type, $seo_schema, $seo_crumbs and $seo_noindex are optional extras.
$seo = seo_resolve([
    'key'     => $seo_key ?? null,
    'title'   => $page_title ?? '',
    'desc'    => $page_desc ?? '',
    'image'   => $seo_image ?? '',
    'type'    => $seo_type ?? null,
    'schema'  => $seo_schema ?? [],
    'crumbs'  => $seo_crumbs ?? null,
    'noindex' => !empty($seo_noindex),
]);
$logo = logo_url();

// contact + social, all editable from Admin > Site Settings
$cEmail = setting('contact_email');
$cPhone = setting('contact_phone');
$sFb    = setting('social_facebook');
$sIg    = setting('social_instagram');
$sYt    = setting('social_youtube');
$sWa    = setting('social_whatsapp');
$sTw    = setting('social_twitter');
$hasTop = $cEmail || $cPhone || $sFb || $sIg || $sYt || $sWa || $sTw;

$navQuiz = quiz_on();
$navSpin = spin_on();

// Pages that carry a small "soon" flag next to their label.
$navSoon = [];

$nav = ['index.php' => 'Home', 'games.php' => 'Games'];
if ($navQuiz) { $nav['quiz.php'] = 'Quiz'; }
if ($navSpin) { $nav['spin.php'] = 'Spin'; }
if (chess_on()) { $nav['chess.php'] = 'Chess'; }

// Government competitive exam — announced ahead of launch. The label,
// the on/off switch and whether it still says "soon" all come from
// Admin -> Govt Exam.
if (govt_exam_in_nav()) {
    $nav['govt-exam.php'] = setting('govt_exam_label', 'Govt Exam');
    if (govt_exam_status() === 'soon') {
        $navSoon['govt-exam.php'] = true;
    }
}

$nav += [
    'leaderboard.php' => 'Leaderboard',
    'blog.php'        => 'Blog',
    'about.php'       => 'About',
    'contact.php'     => 'Contact',
];

// Log the page view. Never throws, never blocks — see includes/visitors.php.
track_visit($page_title ?? null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?= seo_head($seo) ?>
<meta name="theme-color" content="#0b0c0e">
<link rel="icon" type="image/png" sizes="32x32" href="<?= e(logo_icon(32)) ?>">
<link rel="icon" type="image/png" sizes="192x192" href="<?= e(logo_icon(192)) ?>">
<link rel="apple-touch-icon" href="<?= e(logo_icon(180)) ?>">
<!-- installable web app -->
<link rel="manifest" href="<?= url('manifest.php') ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= e($site) ?>">
<meta name="mobile-web-app-capable" content="yes">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
<!-- the logo drives the page background watermark -->
<style>:root{--logo-url:url("<?= e(logo_wide()) ?>")}
/* "soon" flag on a navigation item that is announced but not open yet */
.nav-soon,
.nav-links a .nav-soon,
.mobile-nav a .nav-soon{
  display:inline-block;margin-left:6px;padding:1px 6px;border-radius:999px;
  font-size:9px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;
  line-height:1.6;vertical-align:middle;
  color:#0b0c0e;background:linear-gradient(135deg,#f0c860,#d9a63c);
  box-shadow:0 0 0 1px rgba(217,166,60,.35);
}
.nav-links a.is-soon,.mobile-nav a.is-soon{opacity:.92}
</style>
<?= $extra_head ?? '' ?>
</head>
<?php
// v7 — pages opened inside the Android app drop the site chrome
$bodyClass = implode(' ', array_unique(array_filter(explode(' ', ($body_class ?? '')
    . (str_contains((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 'AsvioraApp') ? ' in-app' : '')))));
?>
<body<?= $bodyClass !== '' ? ' class="' . e($bodyClass) . '"' : '' ?>>
<?= seo_body_open() ?>

<?php if ($hasTop): ?>
<div class="topbar">
  <div class="container topbar-in">
    <div class="tb-left">
      <?php if ($cEmail): ?>
        <a href="mailto:<?= e($cEmail) ?>" class="tb-item">
          <span aria-hidden="true">&#9993;</span><span><?= e($cEmail) ?></span>
        </a>
      <?php endif; ?>
      <?php if ($cPhone): ?>
        <a href="tel:<?= e(preg_replace('/\s+/', '', $cPhone)) ?>" class="tb-item tb-phone">
          <span aria-hidden="true">&#9742;</span><span><?= e($cPhone) ?></span>
        </a>
      <?php endif; ?>
    </div>

    <div class="tb-social">
      <?php if ($sFb): ?>
        <a href="<?= e($sFb) ?>" target="_blank" rel="noopener" class="fb" aria-label="Facebook" title="Facebook">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5 3.66 9.15 8.44 9.94v-7.03H7.9v-2.91h2.54V9.85c0-2.51 1.49-3.9 3.77-3.9 1.09 0 2.23.2 2.23.2v2.46h-1.26c-1.24 0-1.63.78-1.63 1.57v1.88h2.78l-.45 2.91h-2.33V22c4.78-.79 8.45-4.94 8.45-9.94z"/></svg>
        </a>
      <?php endif; ?>
      <?php if ($sIg): ?>
        <a href="<?= e($sIg) ?>" target="_blank" rel="noopener" class="ig" aria-label="Instagram" title="Instagram">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.96.24 2.66.51.72.28 1.33.66 1.94 1.27.61.61.99 1.22 1.27 1.94.27.7.46 1.49.51 2.66.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.24 1.96-.51 2.66a5.4 5.4 0 0 1-1.27 1.94 5.4 5.4 0 0 1-1.94 1.27c-.7.27-1.49.46-2.66.51-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.96-.24-2.66-.51a5.4 5.4 0 0 1-1.94-1.27 5.4 5.4 0 0 1-1.27-1.94c-.27-.7-.46-1.49-.51-2.66C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.24-1.96.51-2.66.28-.72.66-1.33 1.27-1.94A5.4 5.4 0 0 1 5.95 1.28c.7-.27 1.49-.46 2.66-.51C9.88 2.17 10.26 2.16 12 2.16zm0 4.71a5.13 5.13 0 1 1 0 10.26 5.13 5.13 0 0 1 0-10.26zm0 8.46a3.33 3.33 0 1 0 0-6.66 3.33 3.33 0 0 0 0 6.66zm6.54-8.68a1.2 1.2 0 1 1-2.4 0 1.2 1.2 0 0 1 2.4 0z"/></svg>
        </a>
      <?php endif; ?>
      <?php if ($sYt): ?>
        <a href="<?= e($sYt) ?>" target="_blank" rel="noopener" class="yt" aria-label="YouTube" title="YouTube">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M23.5 6.2a3 3 0 0 0-2.1-2.1C19.5 3.6 12 3.6 12 3.6s-7.5 0-9.4.5A3 3 0 0 0 .5 6.2C0 8.1 0 12 0 12s0 3.9.5 5.8a3 3 0 0 0 2.1 2.1c1.9.5 9.4.5 9.4.5s7.5 0 9.4-.5a3 3 0 0 0 2.1-2.1c.5-1.9.5-5.8.5-5.8s0-3.9-.5-5.8zM9.6 15.6V8.4L15.8 12l-6.2 3.6z"/></svg>
        </a>
      <?php endif; ?>
      <?php if ($sWa): ?>
        <a href="<?= e($sWa) ?>" target="_blank" rel="noopener" class="wa" aria-label="WhatsApp" title="WhatsApp">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M17.5 14.4c-.3-.2-1.8-.9-2.1-1-.3-.1-.5-.2-.7.1-.2.3-.8 1-.9 1.2-.2.2-.3.2-.6.1-.3-.2-1.2-.5-2.3-1.4-.8-.8-1.4-1.7-1.5-2-.2-.3 0-.5.1-.6.1-.2.4-.5.6-.7.1-.2.2-.3.3-.5 0-.2 0-.4-.1-.5-.1-.2-.7-1.7-1-2.3-.2-.6-.5-.5-.7-.5h-.6c-.2 0-.5.1-.8.4-.3.3-1 1-1 2.4s1 2.8 1.2 3c.1.2 1.9 3 4.6 4.1 2.3.9 2.8.8 3.3.7.5-.1 1.6-.6 1.8-1.3.2-.6.2-1.2.2-1.3-.1-.2-.2-.2-.5-.4zM12 22a9.9 9.9 0 0 1-5-1.4L2 22l1.4-4.9A9.9 9.9 0 0 1 2 12 10 10 0 1 1 12 22z"/></svg>
        </a>
      <?php endif; ?>
      <?php if ($sTw): ?>
        <a href="<?= e($sTw) ?>" target="_blank" rel="noopener" class="tw" aria-label="X" title="X / Twitter">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M18.9 2H22l-6.8 7.8L23 22h-6.4l-5-6.6L5.8 22H2.7l7.3-8.3L1.5 2H8l4.7 6.2L18.9 2zm-1.1 18h1.7L6.4 3.8H4.6L17.8 20z"/></svg>
        </a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<header class="site-header">
  <div class="container nav-wrap">
    <a href="<?= url('index.php') ?>" class="brand">
      <img src="<?= e(logo_icon(192)) ?>" alt="<?= e($site) ?> logo">
      <span class="brand-txt">
        <span class="brand-name"><?= e(strtoupper($site)) ?></span>
        <span class="brand-sub"><?= e($slogan) ?></span>
      </span>
    </a>

    <nav class="nav-links">
      <?php foreach ($nav as $file => $label): ?>
        <a href="<?= url($file) ?>"
           class="<?= trim(nav_active($file) . (isset($navSoon[$file]) ? ' is-soon' : '')) ?>"><?= e($label) ?><?php
          if (isset($navSoon[$file])): ?><span class="nav-soon">soon</span><?php endif; ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="nav-cta">
      <?php if ($u): ?>
        <span class="hello-user">Hi, <b><?= e($u['username']) ?></b></span>
        <span class="coin-pill nowrap">&#129689; <?= num($u['coins']) ?></span>
        <div class="user-menu">
          <button class="user-btn" aria-expanded="false"
                  aria-label="Account menu for <?= e($u['username']) ?>">
            <img src="<?= e(avatar_url($u)) ?>" alt="">
            <span class="nowrap u-handle"><?= e($u['username']) ?></span>
          </button>
          <div class="user-drop">
            <div class="user-drop-head">
              <div style="font-weight:800"><?= e($u['name']) ?></div>
              <div style="color:var(--cyan);font-weight:700;font-size:.86rem">@<?= e($u['username']) ?></div>
              <div class="text-dim">Level <?= (int) $u['level'] ?> &middot; <?= num($u['xp']) ?> XP</div>
            </div>
            <a href="<?= url('dashboard.php') ?>">&#128202; My Dashboard</a>
            <?php if ($navQuiz): ?>
              <a href="<?= url('quiz.php') ?>">&#129504; Quiz Contest</a>
            <?php endif; ?>
            <?php if ($navSpin): ?>
              <a href="<?= url('spin.php') ?>">&#127920; Daily Spin</a>
            <?php endif; ?>
            <a href="<?= url('dashboard.php?tab=history') ?>">&#128220; Match History</a>
            <a href="<?= url('dashboard.php?tab=wallet') ?>">&#129689; Coin Wallet</a>
            <a href="<?= url('dashboard.php?tab=profile') ?>">&#9881;&#65039; Edit Profile</a>
            <a href="<?= url('logout.php') ?>" class="danger">&#10162; Log Out</a>
          </div>
        </div>
      <?php else: ?>
        <a href="<?= url('login.php') ?>" class="btn btn-ghost btn-sm">Log In</a>
        <a href="<?= url('register.php') ?>" class="btn btn-primary btn-sm">Play Free</a>
      <?php endif; ?>

      <button class="burger" aria-label="Toggle menu" aria-expanded="false">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>
</header>

<nav class="mobile-nav">
  <?php if ($u): ?>
    <div class="drawer-user">
      <img src="<?= e(avatar_url($u)) ?>" alt="">
      <div style="min-width:0">
        <div class="du-name"><?= e($u['name']) ?></div>
        <div class="du-sub">@<?= e($u['username']) ?> &middot; Level <?= (int) $u['level'] ?></div>
      </div>
      <span class="coin-pill nowrap" style="margin-left:auto">&#129689; <?= num($u['coins']) ?></span>
    </div>
  <?php endif; ?>

  <?php foreach ($nav as $file => $label): ?>
    <a href="<?= url($file) ?>"
       class="<?= trim(nav_active($file) . (isset($navSoon[$file]) ? ' is-soon' : '')) ?>"><?= e($label) ?><?php
      if (isset($navSoon[$file])): ?><span class="nav-soon">soon</span><?php endif; ?></a>
  <?php endforeach; ?>
  <?php if ($u): ?>
    <a href="<?= url('dashboard.php') ?>">My Dashboard</a>
    <a href="<?= url('logout.php') ?>">Log Out</a>
  <?php else: ?>
    <a href="<?= url('login.php') ?>" class="btn btn-ghost btn-block mt-3">Log In</a>
    <a href="<?= url('register.php') ?>" class="btn btn-primary btn-block">Create Free Account</a>
  <?php endif; ?>
</nav>

<main>
