<?php
/**
 * Admin chrome. Include AFTER auth.php and after setting $admin_title.
 * Close with layout_end.php.
 */
$A    = require_admin();
$site = setting('site_name', 'Asviora Games');

$unread = (int) fetch_col('SELECT COUNT(*) FROM contact_messages WHERE is_read = 0', [], 0);
$drafts = (int) fetch_col('SELECT COUNT(*) FROM blog_posts WHERE status = "draft"', [], 0);

$menu = [
    ['group' => 'Overview', 'items' => [
        ['index.php',    '&#128202;', 'Dashboard', null],
    ]],
    ['group' => 'Content', 'items' => [
        ['blog.php',       '&#128221;', 'Blog Posts', $drafts ?: null],
        ['blog-edit.php',  '&#10133;',  'Write Post', null],
        ['categories.php', '&#127991;', 'Categories', null],
    ]],
    ['group' => 'Communication', 'items' => [
        ['messages.php',    '&#9993;',  'Messages',    $unread ?: null],
        ['subscribers.php', '&#128231;', 'Subscribers', null],
    ]],
];

// managers and super admins additionally see the platform + settings sections
if (admin_can_manage()) {
    $platform = [[
        'group' => 'Platform', 'items' => [
            ['games.php',  '&#127918;', 'Games',   null],
            ['users.php',  '&#128101;', 'Players', null],
            ['scores.php', '&#127942;', 'Scores',  null],
        ],
    ]];

    // only offer the contest section once the upgrade SQL has been imported
    $contest = [];
    if (has_table('quiz_questions')) {
        $liveQ = (int) fetch_col(
            'SELECT COUNT(*) FROM quiz_questions WHERE is_active = 1', [], 0);
        $contest[] = ['quiz.php',            '&#129504;', 'Quiz Questions', $liveQ ?: null];
        $contest[] = ['quiz-categories.php', '&#127991;', 'Quiz Topics',    null];
    }
    if (has_table('spin_results')) {
        $contest[] = ['spin.php', '&#127920;', 'Daily Spin', null];
    }
    if ($contest) {
        $platform[] = ['group' => 'Contests', 'items' => $contest];
    }

    array_splice($menu, 2, 0, $platform);
    $system = [['settings.php', '&#9881;', 'Site Settings', null]];
    if ($A['role'] === 'super') {
        $system[] = ['admins.php', '&#128272;', 'Admin Users', null];
    }
    $menu[] = ['group' => 'System', 'items' => $system];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($admin_title ?? 'Admin') ?> | <?= e($site) ?></title>
<link rel="icon" href="<?= e(logo_icon(32)) ?>">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
<link rel="stylesheet" href="<?= asset('assets/css/admin.css') ?>">
<?= $admin_head ?? '' ?>
</head>
<body class="admin-body">

<button class="adm-burger" id="admBurger" aria-label="Toggle sidebar" aria-expanded="false">
  <span></span><span></span><span></span>
</button>
<div class="adm-backdrop" id="admBackdrop" hidden></div>

<aside class="adm-sidebar" id="admSidebar">
  <a href="<?= url('index.php') ?>" class="adm-brand" target="_blank" rel="noopener">
    <img src="<?= e(logo_icon(192)) ?>" alt="">
    <span>
      <strong><?= e($site) ?></strong>
      <em>Control panel</em>
    </span>
  </a>

  <nav class="adm-nav">
    <?php foreach ($menu as $section): ?>
      <div class="adm-group"><?= e($section['group']) ?></div>
      <?php foreach ($section['items'] as $it): ?>
        <a href="<?= admin_url($it[0]) ?>" class="adm-link<?= admin_active($it[0]) ?>">
          <span class="ico"><?= $it[1] ?></span>
          <span class="lbl"><?= e($it[2]) ?></span>
          <?php if ($it[3]): ?><span class="pill"><?= (int) $it[3] ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>

  <div class="adm-foot">
    <a href="<?= url('index.php') ?>" target="_blank" rel="noopener" class="adm-link">
      <span class="ico">&#128279;</span><span class="lbl">View website</span></a>
    <a href="<?= admin_url('logout.php') ?>" class="adm-link danger">
      <span class="ico">&#10162;</span><span class="lbl">Sign out</span></a>
  </div>
</aside>

<div class="adm-main">
  <header class="adm-top">
    <div>
      <h1><?= e($admin_title ?? 'Dashboard') ?></h1>
      <?php if (!empty($admin_sub)): ?><p><?= e($admin_sub) ?></p><?php endif; ?>
    </div>
    <div class="adm-top-right">
      <?php if (!empty($admin_actions)): ?><?= $admin_actions ?><?php endif; ?>
      <div class="adm-me">
        <img src="<?= e(avatar_url(['username' => $A['name']])) ?>" alt="">
        <span>
          <strong><?= e($A['name']) ?></strong>
          <em><?= e(ucfirst($A['role'])) ?></em>
        </span>
      </div>
    </div>
  </header>

  <div class="adm-content">
    <?= render_flash() ?>
