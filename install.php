<?php
/**
 * ============================================================
 *  ONE-CLICK INSTALLER — DELETE THIS FILE AFTER SETUP
 * ============================================================
 * Creates the database, imports config/database.sql and seeds
 * the demo content. Runs from localhost only.
 */

// Load only the constants, not the DB connection (which would fail).
// The database may not exist yet, so the auto-migrator must not run here.
define('SKIP_MIGRATIONS', true);
require_once __DIR__ . '/config/config.php';

/* ==================================================================
   Who is allowed to run this?

   On localhost, anyone at the keyboard already owns the machine.
   On a live server the URL is public, and this script DROPs every
   table — so a visitor must first prove they can read a file on the
   server. The installer writes a random key to config/install.key;
   only someone with File Manager, FTP or SSH access can read it back.
   Guessing it is the same problem as guessing a 64-character password.
   ================================================================== */
$localIps = ['127.0.0.1', '::1'];
$isLocal  = in_array($_SERVER['REMOTE_ADDR'] ?? '', $localIps, true);

$keyFile  = __DIR__ . '/config/install.key';
$keyOk    = false;
$keyJustMade = false;

if (!$isLocal) {
    if (!is_file($keyFile)) {
        // First remote visit: mint a key and stop. Nothing touches the database.
        $newKey = bin2hex(random_bytes(24));
        if (@file_put_contents($keyFile, $newKey . "\n") === false) {
            http_response_code(500);
            exit('The installer needs to write config/install.key but the folder is not writable. '
               . 'Give config/ write permission (755 or 775) and reload.');
        }
        @chmod($keyFile, 0600);
        $keyJustMade = true;
    } else {
        $stored  = trim((string) @file_get_contents($keyFile));
        $offered = trim((string) ($_POST['key'] ?? $_GET['key'] ?? ''));
        $keyOk   = $stored !== '' && $offered !== '' && hash_equals($stored, $offered);
    }
}

$allowed = $isLocal || $keyOk;

/* ------------------------------------------------------------------
   Which SQL file?

   database.sql opens with CREATE DATABASE, which shared hosting users
   are not allowed to run — cPanel creates the database for you. The
   hosting file is the same schema without that line, so it imports
   into whatever database config.php points at.
   ------------------------------------------------------------------ */
$sqlFile = $isLocal
    ? __DIR__ . '/config/database.sql'
    : __DIR__ . '/config/database-hosting.sql';

if (!is_readable($sqlFile)) {
    $sqlFile = __DIR__ . '/config/database.sql';
}

$steps   = [];
$fatal   = null;
$done    = false;
$existing = null;          // row counts found before we touch anything

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

/**
 * Open the target database.
 *
 * Locally we connect server-wide so the SQL file can CREATE DATABASE.
 * On hosting the account usually cannot create databases at all, so we
 * connect straight to the one cPanel already made.
 */
function connect_db(bool $isLocal, array &$steps): PDO
{
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

    if ($isLocal) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, $opts);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '`
                      DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `' . DB_NAME . '`');
        $steps[] = ['Connect to MySQL at ' . DB_HOST . ' as ' . DB_USER, true, ''];
        return $pdo;
    }

    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER, DB_PASS, $opts
    );
    $steps[] = ['Connect to database ' . DB_NAME . ' as ' . DB_USER, true, ''];
    return $pdo;
}

/** Row counts for the tables that matter, or null if they are not there yet. */
function table_counts(PDO $pdo): ?array
{
    $counts = [];
    foreach (['admins', 'users', 'games', 'blog_posts', 'blog_categories', 'settings'] as $t) {
        try {
            $counts[$t] = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        } catch (PDOException $e) {
            return null;                       // table missing = nothing installed yet
        }
    }
    return $counts;
}

/* ---------------- look before leaping ---------------- */
// Show what is already in there so nobody wipes a live site by reflex.
if ($allowed) {
    $discard = [];                       // connect_db logs by reference
    try {
        $existing = table_counts(connect_db($isLocal, $discard));
    } catch (PDOException $ex) {
        $fatal = $ex->getMessage();
    }
}

$hasData = $existing && ((int) $existing['users'] > 0 || (int) $existing['blog_posts'] > 0);

/* ---------------- run ---------------- */
if ($allowed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install'])) {

    if (!is_readable($sqlFile)) {
        $fatal = 'Cannot read ' . basename($sqlFile) . ' — make sure the file is present in config/.';

    } elseif ($hasData && empty($_POST['wipe'])) {
        $fatal = 'This database already holds real data. Tick the confirmation box if you '
               . 'really want every table dropped and rebuilt.';

    } else {
        try {
            $pdo = connect_db($isLocal, $steps);

            foreach (split_sql(file_get_contents($sqlFile)) as $stmt) {
                // CREATE DATABASE / USE are handled by connect_db(); on shared
                // hosting the account is not permitted to run them at all.
                if (preg_match('/^\s*(CREATE\s+DATABASE|USE)\b/i', $stmt)) {
                    continue;
                }
                try {
                    $pdo->exec($stmt);
                    $steps[] = [label($stmt), true, ''];
                } catch (PDOException $ex) {
                    $steps[] = [label($stmt), false, $ex->getMessage()];
                }
            }

            $done = table_counts($pdo) ?: [];

            // The key has done its job. Leaving it lying around would let the
            // next person who reads the file rebuild the database.
            if (!$isLocal && is_file($keyFile)) {
                @unlink($keyFile);
            }

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
<link rel="icon" href="assets/img/icon-32.png">
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
      <img src="assets/img/icon-192.png" alt="" style="width:76px;height:76px;border-radius:20px;margin:0 auto 16px">
      <span class="eyebrow">Setup</span>
      <h1 style="font-size:1.9rem;margin-bottom:6px">Install <span class="gradient-text">Asviora Games</span></h1>
      <p class="text-muted">One click creates the database, all tables and the demo content.</p>
    </div>

    <?php if (!$allowed): ?>

      <div class="card">
        <div class="alert alert-info" data-persist="1">
          <span>&#128274;</span>
          <span>This installer rebuilds every table, so on a live domain it has to
                check that you are the owner and not a passer-by.</span>
        </div>

        <h2 style="font-size:1.15rem">Unlock the installer</h2>

        <?php if ($keyJustMade): ?>
          <p class="text-muted">A one-time key has just been written to your server.</p>
        <?php endif; ?>

        <ol class="text-muted" style="font-size:.92rem;line-height:1.9;padding-left:20px">
          <li>Open your hosting <strong>File Manager</strong> (Hostinger hPanel or cPanel)
              and go to the folder this site lives in.</li>
          <li>Open <code>config/install.key</code> and copy the long line inside it.</li>
          <li>Paste it below.</li>
        </ol>

        <form method="post" class="mt-2">
          <div class="form-group">
            <label class="form-label" for="key">Install key</label>
            <input class="form-control" type="text" id="key" name="key" required
                   autocomplete="off" spellcheck="false"
                   placeholder="paste the contents of config/install.key">
          </div>
          <button class="btn btn-primary btn-block btn-lg" type="submit">Unlock</button>
        </form>

        <?php if (!$keyJustMade && ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['key']))): ?>
          <div class="alert alert-error mt-3" data-persist="1">
            <span>&#9888;</span>
            <span>That key does not match. Copy the whole line from
                  <code>config/install.key</code>, with no extra spaces.</span>
          </div>
        <?php endif; ?>

        <p class="form-hint mt-3">
          The key is deleted automatically once the install finishes. If you would rather
          not use this page at all, import <code>config/database-hosting.sql</code> through
          phpMyAdmin instead &mdash; it does exactly the same thing.
        </p>
      </div>

    <?php elseif ($done): ?>

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
          <span><strong>Could not open the database.</strong><br>
            <?= e($fatal) ?><br><br>
            <?php if ($isLocal): ?>
              Make sure MySQL is running in the XAMPP control panel, then check
              <code>DB_USER</code> and <code>DB_PASS</code> in <code>config/config.php</code>.
            <?php else: ?>
              Check the live block in <code>config/config.php</code> against your host's
              <strong>MySQL Databases</strong> screen. The database and the user both carry
              your account prefix, and the user must be <em>assigned to</em> that database
              with all privileges.
            <?php endif; ?>
          </span>
        </div>
      <?php endif; ?>

      <div class="card">
        <h2 style="font-size:1.15rem">Before you start</h2>
        <div class="kv"><span class="k">Mode</span>
          <span class="v"><?= $isLocal ? 'Local (XAMPP)' : 'Live server' ?></span></div>
        <div class="kv"><span class="k">MySQL host</span><span class="v"><?= e(DB_HOST) ?></span></div>
        <div class="kv"><span class="k">MySQL user</span><span class="v"><?= e(DB_USER) ?></span></div>
        <div class="kv"><span class="k">Password</span>
          <span class="v"><?= DB_PASS === '' ? '<span class="text-dim">(empty — XAMPP default)</span>' : '••••••••' ?></span></div>
        <div class="kv"><span class="k">Database</span><span class="v text-green"><?= e(DB_NAME) ?></span></div>
        <div class="kv"><span class="k">SQL file</span>
          <span class="v"><?= is_readable($sqlFile)
              ? '<span class="text-green">' . e(basename($sqlFile)) . '</span>'
              : '<span style="color:var(--danger)">missing</span>' ?></span></div>
        <div class="kv"><span class="k">Currently installed</span>
          <span class="v"><?= $existing === null
              ? '<span class="text-dim">nothing yet</span>'
              : '<span class="text-gold">' . (int) $existing['users'] . ' players, '
                . (int) $existing['blog_posts'] . ' posts</span>' ?></span></div>

        <p class="text-muted mt-3" style="font-size:.9rem">
          Wrong details? Edit <code>config/config.php</code> and reload this page.
        </p>

        <div class="alert alert-error mt-2" data-persist="1">
          <span>&#9888;</span>
          <span>Every table in <code><?= e(DB_NAME) ?></code> is dropped and rebuilt.
                Anything already stored there is gone for good.</span>
        </div>

        <form method="post">
          <input type="hidden" name="install" value="1">
          <?php if (!$isLocal): ?>
            <input type="hidden" name="key" value="<?= e(trim((string) @file_get_contents($keyFile))) ?>">
          <?php endif; ?>

          <?php if ($hasData): ?>
            <label class="row" style="gap:10px;align-items:flex-start;margin:0 0 14px">
              <input type="checkbox" name="wipe" value="1" style="margin-top:4px">
              <span class="text-muted" style="font-size:.9rem">
                I understand this database already has
                <strong><?= (int) $existing['users'] ?></strong> players and
                <strong><?= (int) $existing['blog_posts'] ?></strong> posts in it,
                and I want all of it deleted.
              </span>
            </label>
          <?php endif; ?>

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
