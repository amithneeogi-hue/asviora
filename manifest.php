<?php
/**
 * Web app manifest — served from PHP so the name, colours and icons follow
 * whatever is set in Admin > Site Settings.
 *
 * Linked from includes/header.php. Together with sw.js this is what makes the
 * "Install app" button in the footer appear on Android/Chrome and lets iOS
 * add the site to the home screen as a standalone app.
 */
require_once __DIR__ . '/config/config.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$name  = setting('site_name', 'Asviora Games');
$short = mb_substr($name, 0, 12);

$icon = function (int $size) {
    $rel = 'assets/img/icon-' . $size . '.png';
    return is_file(ROOT_PATH . '/' . $rel) ? url($rel) : logo_url();
};

$icons = [];
foreach ([192, 512] as $s) {
    $icons[] = ['src' => $icon($s), 'sizes' => $s . 'x' . $s, 'type' => 'image/png', 'purpose' => 'any'];
}
// maskable copy so Android can apply its own icon mask without clipping the mark
$icons[] = ['src' => $icon(512), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'];

$shortcuts = [
    ['name' => 'All Games',   'url' => url('games.php'),       'icons' => [['src' => $icon(192), 'sizes' => '192x192']]],
    ['name' => 'Leaderboard', 'url' => url('leaderboard.php'), 'icons' => [['src' => $icon(192), 'sizes' => '192x192']]],
];
if (quiz_on()) {
    array_splice($shortcuts, 1, 0,
        [['name' => 'Quiz Contest', 'url' => url('quiz.php'), 'icons' => [['src' => $icon(192), 'sizes' => '192x192']]]]);
}
if (spin_on()) {
    $shortcuts[] = ['name' => 'Daily Spin', 'url' => url('spin.php'), 'icons' => [['src' => $icon(192), 'sizes' => '192x192']]];
}

echo json_encode([
    'id'                => rtrim(SITE_URL, '/') . '/',
    'name'              => $name,
    'short_name'        => $short,
    'description'       => setting('site_description', 'Skill-based browser games, quizzes and daily rewards.'),
    'start_url'         => url('index.php?src=pwa'),
    'scope'             => rtrim(SITE_URL, '/') . '/',
    'display'           => 'standalone',
    'display_override'  => ['standalone', 'minimal-ui', 'browser'],
    'orientation'       => 'any',
    'background_color'  => '#0b0c0e',
    'theme_color'       => '#0b0c0e',
    'lang'              => 'en',
    'dir'               => 'ltr',
    'categories'        => ['games', 'entertainment'],
    'icons'             => $icons,
    'shortcuts'         => array_slice($shortcuts, 0, 4),
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
