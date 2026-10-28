<?php
/**
 * ===================================================================
 * CPPS CHECK — 500 error ta kothay hocche seta ber kore
 * ===================================================================
 * Ei file ta standalone. Live site 500 dile eta upload kore khulun:
 *
 *      https://asviora.in/cppscheck.php?key=cpps-2026
 *
 * Eta korbe:
 *   1. PHP version ar server info dekhabe
 *   2. v6-er 19 ta file ache kina, koto boro — ek nojore
 *   3. protita include file ke ALADA kore load kore dekhbe kothay
 *      fatal hocche — ar sei exact error message + line dekhabe
 *   4. sesh e config/config.php pura load kore dekhbe
 *
 * Kaj sese EI FILE TA DELETE KORE DEBEN.
 * ===================================================================
 */

@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

$KEY   = 'cpps-2026';
$ROOT  = __DIR__;
$keyOk = isset($_GET['key']) && hash_equals($KEY, (string) $_GET['key']);

/* -------------------------------------------------------------------
 * v6-er file list — kon ta notun, kon ta modify
 * ----------------------------------------------------------------- */
$V6_FILES = array(
    'NEW' => array(
        'cppsmigration.php',
        'config/upgrade-v6.sql',
        'includes/visitors.php',
        'includes/govtexam.php',
        'admin/visitors.php',
        'admin/govt-exam.php',
        'govt-exam.php',
    ),
    'MODIFIED' => array(
        'includes/migrate.php',
        'includes/functions.php',
        'includes/header.php',
        'includes/footer.php',
        'includes/arena.php',
        'includes/spinaccess.php',
        'admin/index.php',
        'admin/includes/layout.php',
        'admin/spin.php',
        'admin/spin-access.php',
        'spin.php',
        'api/spin.php',
    ),
);

/* v6-e ja ja notun function ache — file ta thik version kina bojhar jonno */
$MARKERS = array(
    'includes/visitors.php'   => 'function track_visit',
    'includes/govtexam.php'   => 'function govt_exam_on',
    'includes/functions.php'  => "require_once __DIR__ . '/visitors.php'",
    'includes/header.php'     => 'track_visit(',
    'includes/footer.php'     => 'govt_exam_in_nav()',
    'includes/arena.php'      => 'function arena_count',
    'includes/spinaccess.php' => 'function spin_limits_installed',
    'includes/migrate.php'    => 'SCHEMA_VERSION = 6',
    'admin/index.php'         => 'arena_count()',
    'admin/includes/layout.php' => 'visitors.php',
    'admin/spin.php'          => "value=\"limits\"",
    'admin/spin-access.php'   => 'cooldown_minutes',
    'spin.php'                => 'function countdownOn',
    'api/spin.php'            => "'wait'",
);

function ck_state($root, $rel, $marker = null)
{
    $p = $root . '/' . $rel;
    if (!is_file($p)) {
        return array('missing', 'file ta NEI — upload korte hobe', 0);
    }
    $size = filesize($p);
    if ($marker !== null) {
        $src = (string) file_get_contents($p);
        if (strpos($src, $marker) === false) {
            return array('old', 'purono version — notun file ta upload hoyni', $size);
        }
    }
    return array('ok', 'thik ache', $size);
}

/* -------------------------------------------------------------------
 * Ekta file ke alada PHP process-e load kore dekha (fatal dhorar jonno)
 * ----------------------------------------------------------------- */
function ck_lint($root, $rel)
{
    $p = $root . '/' . $rel;
    if (!is_file($p)) {
        return array('missing', 'file nei');
    }

    // php -l pele setai sobcheye porishkar
    if (function_exists('exec') && !in_array('exec', array_map('trim',
            explode(',', (string) ini_get('disable_functions'))), true)) {
        $out = array();
        $code = 0;
        @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $out, $code);
        if ($out) {
            $txt = implode(' ', $out);
            if (stripos($txt, 'No syntax errors') !== false) {
                return array('ok', 'syntax thik');
            }
            if (stripos($txt, 'not found') === false && $txt !== '') {
                return array('fail', $txt);
            }
        }
    }

    // php CLI na thakle token_get_all diye parse kore dekhi
    $src = (string) file_get_contents($p);
    try {
        @token_get_all($src, TOKEN_PARSE);
        return array('ok', 'parse thik (token check)');
    } catch (Throwable $e) {
        return array('fail', $e->getMessage());
    }
}

/* -------------------------------------------------------------------
 * config.php load kore dekha — asol test
 * ----------------------------------------------------------------- */
$bootError = null;
$bootOk    = false;

if ($keyOk && isset($_GET['boot']) && $_GET['boot'] === '1') {
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'],
                array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
            echo '<div style="background:#2a0f14;color:#ff9b97;border:1px solid #7a2b2b;'
               . 'padding:16px 18px;border-radius:12px;margin:18px 0;'
               . 'font:13px/1.7 ui-monospace,monospace;white-space:pre-wrap">'
               . "<strong>EI TA-I 500 ER KARON:</strong>\n\n"
               . htmlspecialchars($e['message']) . "\n\n"
               . 'File : ' . htmlspecialchars($e['file']) . "\n"
               . 'Line : ' . (int) $e['line']
               . '</div></div></body></html>';
        }
    });

    ob_start();
    try {
        require_once $ROOT . '/config/config.php';
        $bootOk = true;
    } catch (Throwable $e) {
        $bootError = get_class($e) . ': ' . $e->getMessage()
                   . ' — ' . $e->getFile() . ':' . $e->getLine();
    }
    $noise = trim((string) ob_get_clean());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CPPS Check — 500 diagnosis</title>
<style>
 :root{--bg:#0b0c0e;--card:#13161b;--line:#242a33;--txt:#e9eefc;--dim:#8b94a4;
       --green:#8fce3f;--gold:#d9a63c;--red:#ff7a7a}
 *{box-sizing:border-box}
 body{margin:0;background:var(--bg);color:var(--txt);
      font:15px/1.65 system-ui,-apple-system,"Segoe UI",sans-serif;padding:28px 16px}
 .wrap{max-width:940px;margin:0 auto}
 h1{font-size:1.45rem;margin:0 0 4px;color:var(--green)}
 h2{font-size:1.02rem;margin:0 0 12px}
 p.sub{color:var(--dim);margin:0 0 22px}
 .card{background:var(--card);border:1px solid var(--line);border-radius:14px;
       padding:18px 20px;margin-bottom:18px}
 table{width:100%;border-collapse:collapse;font-size:13.5px}
 th,td{text-align:left;padding:7px 9px;border-bottom:1px solid var(--line);vertical-align:top}
 th{color:var(--dim);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.05em}
 code{background:#0e1116;border:1px solid var(--line);border-radius:6px;padding:1px 6px;font-size:12.5px}
 .pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;
       font-weight:800;text-transform:uppercase;letter-spacing:.04em}
 .p-ok{background:rgba(143,206,63,.18);color:var(--green)}
 .p-missing,.p-fail{background:rgba(255,122,122,.18);color:var(--red)}
 .p-old{background:rgba(217,166,60,.18);color:var(--gold)}
 .btn{display:inline-block;padding:11px 20px;border-radius:10px;text-decoration:none;
      font-weight:800;background:linear-gradient(135deg,#8fce3f,#6aa521);color:#0b0c0e}
 .bad{background:rgba(255,122,122,.1);border:1px solid rgba(255,122,122,.4);
      border-radius:12px;padding:14px 16px;color:var(--red);margin-bottom:18px}
 .good{background:rgba(143,206,63,.1);border:1px solid rgba(143,206,63,.4);
       border-radius:12px;padding:14px 16px;color:var(--green);margin-bottom:18px}
 .warn{background:rgba(217,166,60,.1);border:1px solid rgba(217,166,60,.4);
       border-radius:12px;padding:14px 16px;color:var(--gold);margin-bottom:18px}
</style>
</head>
<body>
<div class="wrap">

<h1>CPPS Check</h1>
<p class="sub">500 error ta kothay hocche seta ber korar jonno</p>

<?php if (!$keyOk): ?>
  <div class="bad"><strong>Key lagbe.</strong> URL-e
    <code>?key=<?= htmlspecialchars($KEY) ?></code> jog korun.</div>
<?php else: ?>

  <div class="card">
    <h2>Server</h2>
    <table>
      <tr><th style="width:220px">PHP version</th>
          <td><?= htmlspecialchars(PHP_VERSION) ?>
          <?php if (version_compare(PHP_VERSION, '8.0.0', '<')): ?>
            <span class="pill p-fail">PHP 8 lagbe</span>
          <?php endif; ?></td></tr>
      <tr><th>PDO MySQL</th>
          <td><?= extension_loaded('pdo_mysql') ? 'ache' : 'NEI' ?></td></tr>
      <tr><th>Site folder</th><td><code><?= htmlspecialchars($ROOT) ?></code></td></tr>
      <tr><th>error_log</th>
          <td><code><?= htmlspecialchars((string) ini_get('error_log') ?: 'default') ?></code></td></tr>
    </table>
  </div>

  <?php
  $anyBad = false;
  foreach ($V6_FILES as $group => $files) {
      foreach ($files as $rel) {
          $m = isset($MARKERS[$rel]) ? $MARKERS[$rel] : null;
          $st = ck_state($ROOT, $rel, $m);
          if ($st[0] !== 'ok') { $anyBad = true; }
      }
  }
  ?>

  <?php if ($anyBad): ?>
    <div class="bad"><strong>Kichu file missing ba purono.</strong>
      Niche <span class="pill p-missing">missing</span> ba
      <span class="pill p-old">old</span> lekha row gulo abar upload korun —
      99% khetre 500 er karon eta-i.</div>
  <?php else: ?>
    <div class="good"><strong>19 ta file-i thik ache</strong> — sob notun version.
      Tahole error ta onno kothao; niche "Bootstrap test" chalan.</div>
  <?php endif; ?>

  <?php foreach ($V6_FILES as $group => $files): ?>
    <div class="card">
      <h2><?= htmlspecialchars($group) ?> files (<?= count($files) ?>)</h2>
      <table>
        <thead><tr><th>File</th><th style="width:100px">State</th>
                   <th style="width:90px">Size</th><th>Note</th></tr></thead>
        <tbody>
        <?php foreach ($files as $rel):
              $m  = isset($MARKERS[$rel]) ? $MARKERS[$rel] : null;
              $st = ck_state($ROOT, $rel, $m); ?>
          <tr>
            <td><code><?= htmlspecialchars($rel) ?></code></td>
            <td><span class="pill p-<?= $st[0] ?>"><?= $st[0] ?></span></td>
            <td class="dim" style="color:var(--dim)">
              <?= $st[2] ? number_format($st[2]) . ' B' : '—' ?></td>
            <td style="color:var(--dim)"><?= htmlspecialchars($st[1]) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endforeach; ?>

  <div class="card">
    <h2>Syntax check</h2>
    <table>
      <thead><tr><th>File</th><th style="width:100px">Result</th><th>Details</th></tr></thead>
      <tbody>
      <?php
      $php = array_merge($V6_FILES['NEW'], $V6_FILES['MODIFIED']);
      foreach ($php as $rel) {
          if (substr($rel, -4) !== '.php') { continue; }
          $r = ck_lint($ROOT, $rel);
          echo '<tr><td><code>' . htmlspecialchars($rel) . '</code></td>'
             . '<td><span class="pill p-' . $r[0] . '">' . $r[0] . '</span></td>'
             . '<td style="color:var(--dim);font:12px/1.6 ui-monospace,monospace">'
             . htmlspecialchars($r[1]) . '</td></tr>';
      }
      ?>
      </tbody>
    </table>
  </div>

  <?php if (!isset($_GET['boot'])): ?>
    <div class="warn">
      <strong>Bootstrap test:</strong> ekhon <code>config/config.php</code> pura load
      kore dekhbo — jekhane fatal hocche sei exact file ar line ta dekhabe.
    </div>
    <p><a class="btn" href="?key=<?= urlencode($KEY) ?>&amp;boot=1">
      Bootstrap test chalao &rarr;</a></p>
  <?php else: ?>
    <div class="card">
      <h2>Bootstrap test</h2>
      <?php if ($bootOk): ?>
        <div class="good"><strong>config/config.php thik moto load holo.</strong>
          Mane include chain e kono somossa nei — error ta kono ekta page-e
          (index.php / header.php) hocche. Niche-r link gulo try korun.</div>
        <table>
          <tr><th style="width:220px">SITE_URL</th>
              <td><?= htmlspecialchars(defined('SITE_URL') ? SITE_URL : '?') ?></td></tr>
          <tr><th>Database</th>
              <td><?= htmlspecialchars(defined('DB_NAME') ? DB_NAME : '?') ?></td></tr>
          <tr><th>schema_version</th>
              <td><?php
                try { echo htmlspecialchars(setting('schema_version', '?')); }
                catch (Throwable $e) { echo 'porte parlam na: '
                                          . htmlspecialchars($e->getMessage()); }
              ?></td></tr>
          <tr><th>visits table</th>
              <td><?php
                try { echo has_table('visits') ? 'ache' : 'NEI — migration cholene'; }
                catch (Throwable $e) { echo 'check kora gelo na'; }
              ?></td></tr>
          <tr><th>exam_notify table</th>
              <td><?php
                try { echo has_table('exam_notify') ? 'ache' : 'NEI — migration cholene'; }
                catch (Throwable $e) { echo 'check kora gelo na'; }
              ?></td></tr>
          <tr><th>spin_access.cooldown</th>
              <td><?php
                try { echo spin_limits_installed() ? 'ache' : 'NEI — migration cholene'; }
                catch (Throwable $e) { echo 'check kora gelo na'; }
              ?></td></tr>
        </table>
        <?php if (!empty($noise)): ?>
          <p style="color:var(--gold);margin-top:14px">Load korar somoy ei output ta elo
            (eta-o 500 er karon hote pare — header pathanor age kichu chapa hoye jacche):</p>
          <div style="background:#0e1116;border:1px solid var(--line);border-radius:10px;
                      padding:12px;font:12px/1.6 ui-monospace,monospace;white-space:pre-wrap">
            <?= htmlspecialchars(substr($noise, 0, 3000)) ?></div>
        <?php endif; ?>
      <?php elseif ($bootError !== null): ?>
        <div class="bad"><strong>Exception:</strong><br>
          <span style="font:12.5px/1.7 ui-monospace,monospace">
            <?= htmlspecialchars($bootError) ?></span></div>
      <?php else: ?>
        <div class="bad">Fatal hoyeche — details ei box er niche dekhun.</div>
      <?php endif; ?>
    </div>

    <p>
      <a class="btn" href="index.php">Home page try korun</a>
      <a class="btn" style="background:transparent;border:1px solid var(--line);color:var(--txt)"
         href="admin/index.php">Admin try korun</a>
    </p>
  <?php endif; ?>

<?php endif; ?>

<p style="color:var(--dim);font-size:13px;margin-top:28px">
  Kaj sese <code>cppscheck.php</code> file ta delete kore deben.
</p>
</div>
</body>
</html>
