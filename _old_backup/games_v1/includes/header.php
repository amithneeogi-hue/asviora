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

$pageTitle = isset($page_title) && $page_title !== ''
    ? $page_title . ' | ' . $site
    : $site . ' &mdash; ' . $slogan;
$pageDesc  = $page_desc ?? setting('site_description');

$nav = [
    'index.php'       => 'Home',
    'games.php'       => 'Games',
    'leaderboard.php' => 'Leaderboard',
    'blog.php'        => 'Blog',
    'about.php'       => 'About',
    'contact.php'     => 'Contact',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $pageTitle ?></title>
<meta name="description" content="<?= e($pageDesc) ?>">
<meta name="theme-color" content="#0d1014">
<meta property="og:site_name" content="<?= e($site) ?>">
<meta property="og:title" content="<?= $pageTitle ?>">
<meta property="og:description" content="<?= e($pageDesc) ?>">
<meta property="og:type" content="website">
<meta property="og:image" content="<?= url('assets/img/logo.jpg') ?>">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="<?= url('assets/img/logo.jpg') ?>">
<link rel="apple-touch-icon" href="<?= url('assets/img/logo.jpg') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
<?= $extra_head ?? '' ?>
</head>
<body>

<header class="site-header">
  <div class="container nav-wrap">
    <a href="<?= url('index.php') ?>" class="brand">
      <img src="<?= url('assets/img/logo.jpg') ?>" alt="<?= e($site) ?> logo">
      <span class="brand-txt">
        <span class="brand-name"><?= e(strtoupper($site)) ?></span>
        <span class="brand-sub"><?= e($slogan) ?></span>
      </span>
    </a>

    <nav class="nav-links">
      <?php foreach ($nav as $file => $label): ?>
        <a href="<?= url($file) ?>" class="<?= trim(nav_active($file)) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="nav-cta">
      <?php if ($u): ?>
        <span class="coin-pill nowrap">&#129689; <?= num($u['coins']) ?></span>
        <div class="user-menu">
          <button class="user-btn" aria-expanded="false" aria-label="Account menu">
            <img src="<?= e(avatar_url($u)) ?>" alt="">
            <span class="nowrap"><?= e($u['username']) ?></span>
          </button>
          <div class="user-drop">
            <div class="user-drop-head">
              <div style="font-weight:800"><?= e($u['name']) ?></div>
              <div class="text-dim">Level <?= (int) $u['level'] ?> &middot; <?= num($u['xp']) ?> XP</div>
            </div>
            <a href="<?= url('dashboard.php') ?>">&#128202; My Dashboard</a>
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
  <?php foreach ($nav as $file => $label): ?>
    <a href="<?= url($file) ?>" class="<?= trim(nav_active($file)) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
  <?php if ($u): ?>
    <a href="<?= url('dashboard.php') ?>">My Dashboard</a>
    <a href="<?= url('logout.php') ?>">Log Out</a>
    <div class="mt-3"><span class="coin-pill">&#129689; <?= num($u['coins']) ?> coins</span></div>
  <?php else: ?>
    <a href="<?= url('login.php') ?>" class="btn btn-ghost btn-block mt-3">Log In</a>
    <a href="<?= url('register.php') ?>" class="btn btn-primary btn-block">Create Free Account</a>
  <?php endif; ?>
</nav>

<main>
