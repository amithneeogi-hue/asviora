<?php
/**
 * ============================================================
 *  MIGRATION STATUS — safe to delete once you have seen it green
 * ============================================================
 * The site applies pending migrations automatically on the first
 * request after an update (see includes/migrate.php), so by the time
 * you open this page the work is normally already done. This page
 * just shows you the result, and lets you force a re-run if a
 * migration was interrupted.
 *
 * Runs from localhost only.
 */

require_once __DIR__ . '/config/config.php';

$localIps = ['127.0.0.1', '::1'];
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', $localIps, true)) {
    http_response_code(403);
    exit('This page only works from localhost. Delete migrate.php from the server.');
}

$forced = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // rewind the marker so every migration is attempted again; they are all
    // idempotent, so nothing already in place is touched
    set_setting('schema_version', '0');
    $forced = run_pending_migrations();
}

$version = (int) setting('schema_version', '0');
$upToDate = $version >= SCHEMA_VERSION;

$expect = [
    'quiz_categories' => 'Quiz topics',
    'quiz_questions'  => 'Quiz question bank',
    'quiz_sessions'   => 'Quiz round history',
    'spin_results'    => 'Daily spin results',
];

$tables = [];
$counts = [];
foreach ($expect as $t => $label) {
    $tables[$t] = has_table($t);
    if ($tables[$t]) {
        try {
            $counts[$t] = (int) fetch_col("SELECT COUNT(*) FROM `$t`", [], 0);
        } catch (Throwable $e) {
            $counts[$t] = 0;
        }
    }
}

$allThere = !in_array(false, $tables, true);

// spot-check the coin ledger accepts the two new sources
$ledgerOk = false;
try {
    $col = fetch_one(
        'SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = "coin_transactions" AND COLUMN_NAME = "type"');
    $ledgerOk = $col && strpos($col['t'], "'quiz'") !== false
                     && strpos($col['t'], "'spin'") !== false;
} catch (Throwable $e) {
    $ledgerOk = false;
}

$healthy = $upToDate && $allThere && $ledgerOk;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Migration status</title>
<link rel="icon" href="<?= e(logo_icon(32)) ?>">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<style>
  :root { --logo-url: url("<?= e(logo_url()) ?>"); }
  .mig { max-width: 720px; margin: 52px auto; padding: 0 20px; }
</style>
</head>
<body>

<div class="mig">
  <div class="card">
    <div class="center mb-3">
      <img src="<?= e(logo_url()) ?>" alt=""
           style="width:72px;height:72px;border-radius:18px;margin:0 auto 16px;object-fit:cover">
      <h1 style="font-size:1.55rem;margin-bottom:6px">Migration status</h1>
      <p class="text-muted" style="margin:0;font-size:.93rem">
        Database <code><?= e(DB_NAME) ?></code>
      </p>
    </div>

    <?php if ($forced): ?>
      <div class="alert alert-success" data-persist="1"><span>&#10003;</span>
        Re-applied: <?= e(implode(', ', $forced)) ?></div>
    <?php endif; ?>

    <?php if ($healthy): ?>
      <div class="alert alert-success" data-persist="1"><span>&#10003;</span>
        Everything is applied. Quiz and Daily Spin are live.</div>
    <?php else: ?>
      <div class="alert alert-error" data-persist="1"><span>&#9888;</span>
        Something is still missing — use the button below to re-run.</div>
    <?php endif; ?>

    <div class="kv">
      <span class="k">Schema version</span>
      <span class="v <?= $upToDate ? 'text-green' : 'text-dim' ?>">
        <?= $version ?> / <?= SCHEMA_VERSION ?></span>
    </div>

    <?php foreach ($expect as $t => $label): ?>
      <div class="kv">
        <span class="k"><?= e($label) ?> <code style="font-size:.8em"><?= e($t) ?></code></span>
        <span class="v <?= $tables[$t] ? 'text-green' : '' ?>"
              style="<?= $tables[$t] ? '' : 'color:var(--danger)' ?>">
          <?= $tables[$t]
              ? '&#10003; ' . num($counts[$t]) . ' rows'
              : '&#10007; missing' ?>
        </span>
      </div>
    <?php endforeach; ?>

    <div class="kv">
      <span class="k">Coin ledger accepts quiz + spin</span>
      <span class="v <?= $ledgerOk ? 'text-green' : '' ?>"
            style="<?= $ledgerOk ? '' : 'color:var(--danger)' ?>">
        <?= $ledgerOk ? '&#10003; yes' : '&#10007; no' ?></span>
    </div>

    <?php if ($healthy): ?>
      <div class="row mt-4" style="gap:10px">
        <a class="btn btn-primary" href="quiz.php">Open the quiz</a>
        <a class="btn btn-gold" href="spin.php">Open the spin wheel</a>
        <a class="btn btn-ghost" href="admin/quiz.php">Admin &rarr; questions</a>
      </div>
      <div class="alert alert-info mt-3" data-persist="1"><span>&#8505;</span>
        You can delete <code>migrate.php</code> now — migrations run on their own.
      </div>
    <?php endif; ?>

    <form method="post" class="mt-3">
      <button class="btn <?= $healthy ? 'btn-ghost btn-sm' : 'btn-primary btn-lg' ?> btn-block"
              type="submit">
        <?= $healthy ? 'Force a re-run anyway (safe)' : 'Run the migration now' ?>
      </button>
    </form>
    <p class="form-hint center mt-2">
      Every statement is idempotent — existing tables and data are never touched.
    </p>
  </div>
</div>

<script src="assets/js/main.js" defer></script>
</body>
</html>
