<?php
/**
 * v7 MIGRATION — payments, game plans, subscriptions, SEO manager
 * ------------------------------------------------------------------
 *   https://asviora.in/v7migration.php?key=cpps-2026
 *
 * The site applies config/upgrade-v7.sql by itself on the first page view
 * after the upload (includes/migrate.php, SCHEMA_VERSION 7). This page is
 * the manual + diagnostic way: it shows which tables / settings exist,
 * runs the file statement by statement, and reports each one.
 *
 * Safe to run more than once. DELETE THIS FILE from the server after use.
 */
define('SKIP_MIGRATIONS', true);           // we run it ourselves, visibly
require_once __DIR__ . '/config/config.php';
require_once ROOT_PATH . '/includes/migrate.php';

const V7_KEY = 'cpps-2026';

if (!hash_equals(V7_KEY, (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit('Add ?key=... to the URL.');
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');

$pdo     = db();
$file    = ROOT_PATH . '/config/upgrade-v7.sql';
$run     = isset($_GET['run']);
$report  = [];
$errors  = 0;

$tables = ['payments', 'game_plans', 'subscriptions', 'seo_pages'];
$check  = static function () use ($tables): array {
    $out = [];
    foreach ($tables as $t) {
        $out[$t] = (bool) fetch_col('SELECT 1 FROM information_schema.TABLES
                                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t], 0);
    }
    return $out;
};

$before = $check();
$version = (int) setting('schema_version', '0');

if ($run) {
    if (!is_readable($file)) {
        $report[] = ['fail', 'config/upgrade-v7.sql is missing — upload it first.'];
        $errors++;
    } else {
        foreach (sql_statements((string) file_get_contents($file)) as $stmt) {
            if (preg_match('/^USE\s/i', $stmt)) {
                continue;
            }
            $label = substr(preg_replace('/\s+/', ' ', $stmt), 0, 90);
            try {
                $pdo->exec($stmt);
                $report[] = ['ok', $label];
            } catch (PDOException $e) {
                if (sql_error_is_benign($e)) {
                    $report[] = ['skip', $label . ' — already there'];
                } else {
                    $report[] = ['fail', $label . ' — ' . $e->getMessage()];
                    $errors++;
                }
            }
        }
        if ($errors === 0 && $version < 7) {
            set_setting('schema_version', '7');
            $report[] = ['ok', 'schema_version set to 7'];
        }
    }
}
$after = $check();
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>v7 migration</title>
<style>
body{font:15px/1.6 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#0b0c0e;color:#eaeee8;margin:0;padding:30px 18px}
.box{max-width:860px;margin:0 auto;background:#17191d;border:1px solid #2e3239;border-radius:16px;padding:26px}
h1{margin:0 0 6px;font-size:1.4rem;color:#8fce3f}
table{width:100%;border-collapse:collapse;margin:14px 0}td,th{padding:7px 9px;border-bottom:1px solid #23262c;text-align:left;font-size:.9rem}
.ok{color:#8fce3f}.skip{color:#a2ab99}.fail{color:#fb5c7d}
.btn{display:inline-block;padding:11px 22px;border-radius:999px;background:linear-gradient(135deg,#b6e857,#5c9418);color:#0b0c0e;font-weight:800;text-decoration:none}
code{background:#202329;padding:2px 6px;border-radius:6px}
</style></head><body><div class="box">
<h1>Asviora — v7 migration</h1>
<p>Payments · Game plans · Subscriptions · Registration fee · SEO manager</p>
<p>Database: <code><?= htmlspecialchars(DB_NAME) ?></code> · schema version now:
  <strong><?= (int) setting('schema_version', '0') ?></strong> (needs 7)</p>

<table><tr><th>Table</th><th>Before</th><th>Now</th></tr>
<?php foreach ($tables as $t): ?>
  <tr><td><code><?= $t ?></code></td>
    <td class="<?= $before[$t] ? 'ok' : 'fail' ?>"><?= $before[$t] ? 'exists' : 'missing' ?></td>
    <td class="<?= $after[$t] ? 'ok' : 'fail' ?>"><?= $after[$t] ? 'exists' : 'missing' ?></td></tr>
<?php endforeach; ?>
</table>

<?php if ($report): ?>
  <table><tr><th></th><th>Statement</th></tr>
  <?php foreach ($report as [$st, $msg]): ?>
    <tr><td class="<?= $st ?>"><?= $st ?></td><td><?= htmlspecialchars($msg) ?></td></tr>
  <?php endforeach; ?></table>
  <p class="<?= $errors ? 'fail' : 'ok' ?>"><strong><?= $errors ? $errors . ' statement(s) failed — send this page to support.'
      : 'Done. Now DELETE v7migration.php from the server.' ?></strong></p>
<?php else: ?>
  <p><a class="btn" href="?key=<?= urlencode(V7_KEY) ?>&amp;run=1">Migration chalao &rarr;</a></p>
<?php endif; ?>
</div></body></html>
