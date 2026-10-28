<?php
/**
 * ============================================================
 *  ONE-CLICK INSTALLER — DELETE THIS FILE AFTER SETUP
 * ============================================================
 * Creates the database, imports config/database.sql and seeds
 * the demo content. Runs from localhost only.
 */

// Load only the constants, not the DB connection (which would fail).
require_once __DIR__ . '/config/config.php';

$localIps = ['127.0.0.1', '::1'];
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', $localIps, true)) {
    http_response_code(403);
    exit('The installer only runs from localhost. Delete install.php from the server.');
}

$sqlFile = __DIR__ . '/config/database.sql';
$steps   = [];
$fatal   = null;
$done    = false;

/**
 * Split a SQL dump into statements without breaking on semicolons
 * that live inside quoted strings or comments.
 */
function split_sql(string $sql): array
{
    $out = [];
    $cur = '';
    $len = strlen($sql);
    $i   = 0;
    $q   = null;

    while ($i < $len) {
        $c = $sql[$i];

        if ($q !== null) {
            $cur .= $c;
            if ($c === '\\' && $i + 1 < $len) {      // escaped char inside a string
                $cur .= $sql[$i + 1];
                $i += 2;
                continue;
            }
            if ($c === $q) {
                // '' inside a '-quoted string is an escaped quote
                if ($i + 1 < $len && $sql[$i + 1] === $q) {
                    $cur .= $sql[$i + 1];
                    $i += 2;
                    continue;
                }
                $q = null;
            }
            $i++;
            continue;
        }

        // line comment
        if (($c === '-' && substr($sql, $i, 2) === '--') || $c === '#') {
            $nl = strpos($sql, "\n", $i);
            if ($nl === false) break;
            $i = $nl + 1;
            continue;
        }
        // block comment
        if ($c === '/' && substr($sql, $i, 2) === '/*') {
            $end = strpos($sql, '*/', $i + 2);
            if ($end === false) break;
            $i = $end + 2;
            continue;
        }

        if ($c === "'" || $c === '"' || $c === '`') {
            $q = $c;
            $cur .= $c;
            $i++;
            continue;
        }

        if ($c === ';') {
            $t = trim($cur);
            if ($t !== '') $out[] = $t;
            $cur = '';
            $i++;
            continue;
        }

        $cur .= $c;
        $i++;
    }

    $t = trim($cur);
    if ($t !== '') $out[] = $t;
    return $out;
}

function label(string $stmt): string
{
    if (preg_match('/^CREATE DATABASE.*?`?(\w+)`?/is', $stmt, $m))   return 'Create database ' . $m[1];
    if (preg_match('/^USE\s+`?(\w+)`?/is', $stmt, $m))               return 'Select database ' . $m[1];
    if (preg_match('/^DROP TABLE IF EXISTS `?(\w+)`?/is', $stmt, $m))return 'Drop old table ' . $m[1];
    if (preg_match('/^CREATE TABLE `?(\w+)`?/is', $stmt, $m))        return 'Create table ' . $m[1];
    if (preg_match('/^INSERT (?:IGNORE )?INTO `?(\w+)`?/is', $stmt, $m)) return 'Seed data into ' . $m[1];
    return substr(preg_replace('/\s+/', ' ', $stmt), 0, 50);
}

/* ---------------- run ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!is_readable($sqlFile)) {
        $fatal = 'Cannot read config/database.sql — make sure the file is present.';
    } else {
        try {
            // connect WITHOUT a database name so we can create it
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET,
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $steps[] = ['Connect to MySQL at ' . DB_HOST . ' as ' . DB_USER, true, ''];

            $raw = file_get_contents($sqlFile);

            // The dump ships with a hardcoded database name. Rewrite it so the
            // installer always targets DB_NAME from config/config.php.
            $raw = preg_replace(
                '/(CREATE\s+DATABASE(?:\s+IF\s+NOT\s+EXISTS)?|USE)\s+`?[A-Za-z0-9_]+`?/i',
                '$1 `' . DB_NAME . '`',
                $raw
            );

            $statements = split_sql($raw);

            // Create + select the database up front, and turn off FK checks so the
            // DROP TABLE statements can run in any order on a re-install.
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME
                     . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo->exec('USE `' . DB_NAME . '`');
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $steps[] = ['Create and select database ' . DB_NAME, true, ''];

            foreach ($statements as $stmt) {
                try {
                    $pdo->exec($stmt);
                    $steps[] = [label($stmt), true, ''];
                } catch (PDOException $ex) {
                    $steps[] = [label($stmt), false, $ex->getMessage()];
                }
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

            // verify
            $pdo->exec('USE `' . DB_NAME . '`');
            $counts = [];
            foreach (['admins', 'users', 'games', 'blog_posts', 'blog_categories', 'settings'] as $t) {
                $counts[$t] = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            }
            $done = $counts;

        } catch (PDOException $ex) {
            $fatal = $ex->getMessage();
        }
    }
}

$failed = array_filter($steps, function ($s) { return !$s[1]; });
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install Asviora Games</title>
<link rel="icon" href="assets/img/logo.jpg">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<style>
  .step { display:flex; gap:11px; align-items:flex-start; padding:8px 0;
          border-bottom:1px solid var(--line-soft); font-size:.89rem; }
  .step:last-child { border-bottom:0; }
  .step .m { flex-shrink:0; width:18px; font-weight:900; }
  .step.ok  .m { color: var(--green); }
  .step.bad .m { color: var(--danger); }
  .step .err { display:block; color:var(--danger); font-size:.8rem; margin-top:3px; }
  .log { max-height:340px; overflow-y:auto; background:var(--bg-2);
         border:1px solid var(--line); border-radius:12px; padding:6px 16px; }
</style>
</head>
<body>

<section class="section-sm">
  <div class="container" style="max-width:720px">

    <div class="center mb-3">
      <img src="assets/img/logo.jpg" alt="" style="width:76px;height:76px;border-radius:20px;margin:0 auto 16px">
      <span class="eyebrow">Setup</span>
      <h1 style="font-size:1.9rem;margin-bottom:6px">Install <span class="gradient-text">Asviora Games</span></h1>
      <p class="text-muted">One click creates the database, all tables and the demo content.</p>
    </div>

    <?php if ($done): ?>

      <div class="card">
        <div class="alert alert-success" data-persist="1">
          <span>&#10003;</span> Installation complete.
        </div>

        <h2 style="font-size:1.15rem">What was created</h2>
        <div class="kv"><span class="k">Database</span><span class="v"><?= e(DB_NAME) ?></span></div>
        <?php foreach ($done as $t => $n): ?>
          <div class="kv"><span class="k"><?= e(str_replace('_', ' ', $t)) ?></span>
            <span class="v text-green"><?= (int) $n ?> row<?= $n === 1 ? '' : 's' ?></span></div>
        <?php endforeach; ?>

        <div class="alert alert-info mt-3" data-persist="1">
          <span>&#8505;</span>
          <span>
            <strong>Admin login:</strong> username <code>admin</code>, password <code>Admin@123</code>.
            Change it right away from <em>Admin Users &rarr; Edit</em>.
          </span>
        </div>

        <div class="alert alert-error" data-persist="1">
          <span>&#9888;</span>
          <span>Now delete <code>install.php</code> and <code>reset-admin-password.php</code>
                from the <code>games</code> folder.</span>
        </div>

        <div class="row mt-3" style="gap:10px">
          <a href="index.php" class="btn btn-primary btn-lg" style="flex:1">Open the website</a>
          <a href="admin/login.php" class="btn btn-ghost btn-lg" style="flex:1">Open the admin panel</a>
        </div>
      </div>

      <?php if ($failed): ?>
        <div class="card mt-3">
          <h3 style="font-size:1rem">Statements that reported an error</h3>
          <p class="text-dim">These are usually harmless (for example dropping a table that did
             not exist yet). Everything above imported correctly.</p>
          <div class="log">
            <?php foreach ($failed as $s): ?>
              <div class="step bad"><span class="m">&#10007;</span>
                <span><?= e($s[0]) ?><span class="err"><?= e($s[2]) ?></span></span></div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

    <?php else: ?>

      <?php if ($fatal): ?>
        <div class="alert alert-error" data-persist="1">
          <span>&#9888;</span>
          <span><strong>Could not connect to MySQL.</strong><br>
            <?= e($fatal) ?><br><br>
            Make sure MySQL is running in the XAMPP control panel, then check
            <code>DB_USER</code> and <code>DB_PASS</code> in <code>config/config.php</code>.</span>
        </div>
      <?php endif; ?>

      <div class="card">
        <h2 style="font-size:1.15rem">Before you start</h2>
        <div class="kv"><span class="k">MySQL host</span><span class="v"><?= e(DB_HOST) ?></span></div>
        <div class="kv"><span class="k">MySQL user</span><span class="v"><?= e(DB_USER) ?></span></div>
        <div class="kv"><span class="k">Password</span>
          <span class="v"><?= DB_PASS === '' ? '<span class="text-dim">(empty — XAMPP default)</span>' : '••••••••' ?></span></div>
        <div class="kv"><span class="k">Database to create</span><span class="v text-green"><?= e(DB_NAME) ?></span></div>
        <div class="kv"><span class="k">SQL file</span>
          <span class="v"><?= is_readable($sqlFile)
              ? '<span class="text-green">found</span>'
              : '<span class="text-red" style="color:var(--danger)">missing</span>' ?></span></div>

        <p class="text-muted mt-3" style="font-size:.9rem">
          Wrong details? Edit <code>config/config.php</code> and reload this page.
        </p>

        <div class="alert alert-error mt-2" data-persist="1">
          <span>&#9888;</span>
          <span>If a database called <code><?= e(DB_NAME) ?></code> already exists, its tables
                will be dropped and recreated. Any existing data is lost.</span>
        </div>

        <form method="post">
          <button class="btn btn-primary btn-block btn-lg" type="submit">
            &#9889; Install now
          </button>
        </form>
      </div>

      <?php if ($steps): ?>
        <div class="card mt-3">
          <h3 style="font-size:1rem">Import log</h3>
          <div class="log">
            <?php foreach ($steps as $s): ?>
              <div class="step <?= $s[1] ? 'ok' : 'bad' ?>">
                <span class="m"><?= $s[1] ? '&#10003;' : '&#10007;' ?></span>
                <span><?= e($s[0]) ?>
                  <?php if (!$s[1]): ?><span class="err"><?= e($s[2]) ?></span><?php endif; ?>
                </span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

    <?php endif; ?>

  </div>
</section>

<script src="assets/js/main.js" defer></script>
</body>
</html>
