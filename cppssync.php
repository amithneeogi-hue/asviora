<?php
/**
 * ===================================================================
 * CPPS SYNC — live site ar local project er difference ber kore
 * ===================================================================
 * Live e 500 asche karon live server ta PURONO — v4/v5 update-er onek
 * file sekhane kokhono upload-i hoyni (jemon includes/modules.php).
 * Notun functions.php oi file gulo require kore, tai fatal.
 *
 * Ei page ta bole dey EXACTLY kon file gulo upload korte hobe:
 *
 *      https://asviora.in/cppssync.php?key=cpps-2026
 *
 * Protita file er MD5 + size local project theke neya. Live er sathe
 * miliye 3 rokom result dey:
 *
 *      MISSING   — live e file-i nei, upload korte hobe
 *      DIFFERENT — live e purono version, replace korte hobe
 *      SAME      — thik ache, hath dewar dorkar nei
 *
 * Kono kichu change kore na — sudhu pore ar report dey.
 * Kaj sese EI FILE TA DELETE KORE DEBEN.
 * ===================================================================
 */

@ini_set('display_errors', '1');
@set_time_limit(180);
error_reporting(E_ALL);

$KEY   = 'cpps-2026';
$ROOT  = __DIR__;
$keyOk = isset($_GET['key']) && hash_equals($KEY, (string) $_GET['key']);

/* -------------------------------------------------------------------
 * Manifest — local project (D:\xamp\htdocs\gamesv) er protita code file
 * ----------------------------------------------------------------- */
$MANIFEST = array(
    '404.php' => array('ed215b85f1037f72f66dcd864dd7b06d', 866),
    'about.php' => array('28a16bf80a349f2f1ffe3f48979a352c', 5525),
    'admin/admins.php' => array('640a796f0c0ef75e5592dc50dac4b89f', 10547),
    'admin/blog-edit.php' => array('822c240aa9157cf9991ef15e59c5d8da', 18356),
    'admin/blog.php' => array('1e78e76216304ecf38fea82287b3f2bd', 8866),
    'admin/categories.php' => array('420d4deb4b8f0fcb59bf525ff08d7d18', 5333),
    'admin/chess.php' => array('7a7aa3a287d209fa01e7776cf345a263', 9356),
    'admin/games.php' => array('df6f7cd5f995349140785990e8a02a5e', 14412),
    'admin/govt-exam.php' => array('6294e0c3a64f296320802ccd95a8b229', 10126),
    'admin/includes/auth.php' => array('98de99612465dd1fffce970ab03502d4', 1507),
    'admin/includes/layout.php' => array('abbabdc34c7c614994083cad76fc03bf', 6383),
    'admin/includes/layout_end.php' => array('595c40954096263d4eb76427750a33ba', 1565),
    'admin/index.php' => array('8fb57fd864006dc069a36df8171f0921', 15960),
    'admin/login.php' => array('b7cbbb84e2aa0c89d6a314f229f34f3c', 3750),
    'admin/logout.php' => array('3d71c43ea281b4ff0f1d4bd4370b13ef', 198),
    'admin/marks.php' => array('e3084569b88e145a4eabd4990ba42623', 14718),
    'admin/messages.php' => array('d8619607fe43bab10ebb5c0ca1cacdb6', 5190),
    'admin/modules.php' => array('588ec41a7a773a5299a0188173d8e540', 12221),
    'admin/quiz-categories.php' => array('4c58ab38706d58d0d233e65a9d02a234', 6809),
    'admin/quiz-import.php' => array('8ed6c349c1cd69c875454ecedee601d7', 28015),
    'admin/quiz-levels.php' => array('01f705806d24058ab0530d611e541b5f', 17288),
    'admin/quiz.php' => array('f626f8d11cdec0391b8b28b78e86c562', 15031),
    'admin/scores.php' => array('a06720596c0f3c06f33c8f94f60fb45c', 8836),
    'admin/settings.php' => array('09bc71bef53f955a1f0c8425c386991e', 11097),
    'admin/spin-access.php' => array('7cd9cfe374aae839da891b13d87cd5ed', 17829),
    'admin/spin.php' => array('24a0c240659762076308684558257789', 18583),
    'admin/subscribers.php' => array('ac81bb6ca35344df7abe69229d1660d0', 3610),
    'admin/users.php' => array('253cb6a0b02822a0bde028400111b5fc', 12318),
    'admin/visitors.php' => array('bc0cc630c1bdd8188158a8fb3562c3f5', 21175),
    'api/chess.php' => array('80a9eed1755188a3ecc116f0c59ad8c6', 2580),
    'api/quiz.php' => array('84dcd11b214b25dd528de414316455ff', 14364),
    'api/spin.php' => array('24611bc468e142ee96305f54fe7dd04a', 4062),
    'api/submit_score.php' => array('5a2920b1ba8e87fdaba7d77c1ee3abf5', 5111),
    'api/v1/bootstrap.php' => array('6fa265d6f1a7049e492e7b9fabb3f356', 7985),
    'api/v1/index.php' => array('7edcdb31c2311858e44afdbf0a0f95e6', 27258),
    'assets/css/admin.css' => array('bb36d2b9a5dcffbb3d5392899f49e454', 13859),
    'assets/css/chess.css' => array('cec369acadcec73b3ac7cb401ced680a', 3803),
    'assets/css/game.css' => array('a342250fbb3a56970d74979cd7100533', 5740),
    'assets/css/style.css' => array('4ac12ad0769147d4a16450ed409378ee', 60184),
    'assets/js/chess-play.js' => array('7a5397b3ffe5afebb1281d3f7911fc4b', 11405),
    'assets/js/chess.js' => array('e1eeb82f3f40835f233f4023edd7fbfa', 19908),
    'assets/js/game-sdk.js' => array('cd5dffe1b1154f0cd48da3bf3f48f4bb', 3804),
    'assets/js/main.js' => array('95bfd2f2b2f5f8f154d2085355d76066', 9780),
    'assets/js/pwa.js' => array('385b186c9567aab5b0c041f6170c83b2', 4471),
    'blog.php' => array('3c5ac6c1b7f7d02a90d17418ad275375', 8895),
    'chess-play.php' => array('0190865618a2eb2b2edbb77233930868', 6200),
    'chess.php' => array('df8a60ad314338bb1c759af17e3a6ca2', 11161),
    'config/database-hosting.sql' => array('243b1c939c40a0398a03e8a822038cc8', 27498),
    'config/database.sql' => array('f3fda46b4c7cb863274dfe21895a8741', 27368),
    'config/upgrade-quiz-proctor.sql' => array('0bfc59e31f8dd1b2d87be2a24609eaff', 2067),
    'config/upgrade-quiz-spin.sql' => array('0b65770dc8e80fe37c2663100313bbba', 12456),
    'config/upgrade-v4.sql' => array('979e3e3f1d43a2e2e81a28f66a546afa', 350),
    'config/upgrade-v5.sql' => array('f32547e8a75a60cedc17569fd5e65250', 16852),
    'config/upgrade-v6.sql' => array('8b2bcb1ae4487243212f4d2c44789e90', 8574),
    'contact.php' => array('d60fdf8db5c1b4fcec9757494a9b90d4', 11832),
    'dashboard.php' => array('e2699031131d63a1752e1e7474263996', 22678),
    'games.php' => array('ae0693df9a50474d443ecaec736d10b5', 2708),
    'games/chess/engine.js' => array('964d6a8a34e7ed1db45655a0a279fcc5', 24712),
    'govt-exam.php' => array('15e421da38276292f9b5b2e237689604', 7826),
    'includes/arena.php' => array('895401c5ac60c7391ad10ddb1081fddd', 12562),
    'includes/chess.php' => array('8842fd229f1d0d850061c6003be8baa6', 17933),
    'includes/chesslobby.php' => array('9b272dc69c955dda4c5cdb9cd3d77aa7', 17570),
    'includes/chessrules.php' => array('04890bea6952eb72ef7f6ee183990239', 15141),
    'includes/db.php' => array('3016c64154333fcea912326d26b36410', 2701),
    'includes/footer.php' => array('72036089f688413fb48091592b70425e', 10505),
    'includes/functions.php' => array('36b3a5dde56f466a4c9eabfd262ee125', 17445),
    'includes/govtexam.php' => array('c599b47753beff75211c83c2cc1a9fdb', 3569),
    'includes/header.php' => array('19b55e00240fdf608b255e0eacb12813', 11669),
    'includes/marks.php' => array('0482d7894d31806936ee7f392076fb9b', 6432),
    'includes/migrate.php' => array('1cdef4dac56c237cc63156b95a67da39', 6153),
    'includes/modules.php' => array('db43e5d9245e19dda962e8153f3e7b54', 3863),
    'includes/quiz.php' => array('3d5499b31a38db2df88f45a871dea40b', 13903),
    'includes/quizlevels.php' => array('ee0ecf96a14ee877b29f807fca46ad85', 21553),
    'includes/sheet.php' => array('e5c9abcd61d296992580de62c5669444', 8466),
    'includes/spinaccess.php' => array('23d9ebb69e1dcd10e02b81279930c115', 11091),
    'includes/visitors.php' => array('c09a83b6ed6f6aae5622224c602c6180', 27733),
    'index.php' => array('91073e4bea1d4c89bc3e178c044c0c05', 16880),
    'leaderboard.php' => array('4177eefca3a43416f2658f4862a8db04', 7673),
    'login.php' => array('e27dffc57722b04e7f63caa7b510a985', 4159),
    'logout.php' => array('450f1554dcdff20f4baaec5ac97c7af6', 371),
    'manifest.php' => array('91a7d4f3d7048b9857ae29de017947e4', 2517),
    'offline.php' => array('8e1cfbfcb8ca2cc2f2f7243d66c0bf65', 2177),
    'play.php' => array('a0d5b7fa14f50a1fc3f99ff44e9caf14', 8223),
    'post.php' => array('8764718d980da9f755e9f8f51b8e9de0', 6847),
    'quiz-play.php' => array('840e9291e0ce41f88b5cabbc17817094', 30931),
    'quiz.php' => array('11496da29e8d69701e0281fa9377f097', 15814),
    'register.php' => array('3d8604363c2076c5d04b4908cde9d6fe', 7246),
    'spin.php' => array('95bfd880de55717a2ffb8cffcd1d8de3', 17452),
    'sw.js' => array('88d3a02278e6e0df7f94754e18c3eaa5', 3695),
);

/* config/config.php alada rakha holo: otate DB password ache, tai
   difference thakleo andhabhabe replace kora uchit noy. */
$SENSITIVE = array('config/config.php');

/* -------------------------------------------------------------------
 * Compare
 * ----------------------------------------------------------------- */
$missing = array();
$diff    = array();
$same    = array();

if ($keyOk) {
    foreach ($MANIFEST as $rel => $want) {
        $p = $ROOT . '/' . $rel;
        if (!is_file($p)) {
            $missing[$rel] = array(0, $want[1]);
            continue;
        }
        $have = md5_file($p);
        if ($have === $want[0]) {
            $same[$rel] = filesize($p);
        } else {
            $diff[$rel] = array(filesize($p), $want[1]);
        }
    }
    ksort($missing);
    ksort($diff);
    ksort($same);
}

$needed = count($missing) + count($diff);

function cs_group($list)
{
    $out = array();
    foreach ($list as $rel => $v) {
        $dir = strpos($rel, '/') === false ? '(root)' : dirname($rel);
        $out[$dir][$rel] = $v;
    }
    ksort($out);
    return $out;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CPPS Sync — live vs local</title>
<style>
 :root{--bg:#0b0c0e;--card:#13161b;--line:#242a33;--txt:#e9eefc;--dim:#8b94a4;
       --green:#8fce3f;--gold:#d9a63c;--red:#ff7a7a}
 *{box-sizing:border-box}
 body{margin:0;background:var(--bg);color:var(--txt);
      font:15px/1.65 system-ui,-apple-system,"Segoe UI",sans-serif;padding:28px 16px}
 .wrap{max-width:900px;margin:0 auto}
 h1{font-size:1.45rem;margin:0 0 4px;color:var(--green)}
 h2{font-size:1.02rem;margin:0 0 12px}
 h3{font-size:.82rem;margin:16px 0 6px;color:var(--dim);text-transform:uppercase;
    letter-spacing:.06em}
 p.sub{color:var(--dim);margin:0 0 22px}
 .card{background:var(--card);border:1px solid var(--line);border-radius:14px;
       padding:18px 20px;margin-bottom:18px}
 ul.files{list-style:none;margin:0;padding:0}
 ul.files li{padding:5px 0;border-bottom:1px solid var(--line);
             font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;
             display:flex;gap:10px;align-items:baseline}
 ul.files li:last-child{border-bottom:0}
 .sz{margin-left:auto;color:var(--dim);font-size:11.5px;white-space:nowrap}
 code{background:#0e1116;border:1px solid var(--line);border-radius:6px;
      padding:1px 6px;font-size:12.5px}
 .pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;
       font-weight:800;text-transform:uppercase;letter-spacing:.04em}
 .p-missing{background:rgba(255,122,122,.18);color:var(--red)}
 .p-diff{background:rgba(217,166,60,.18);color:var(--gold)}
 .p-same{background:rgba(143,206,63,.18);color:var(--green)}
 .bad{background:rgba(255,122,122,.1);border:1px solid rgba(255,122,122,.4);
      border-radius:12px;padding:14px 16px;color:var(--red);margin-bottom:18px}
 .good{background:rgba(143,206,63,.1);border:1px solid rgba(143,206,63,.4);
       border-radius:12px;padding:14px 16px;color:var(--green);margin-bottom:18px}
 .warn{background:rgba(217,166,60,.1);border:1px solid rgba(217,166,60,.4);
       border-radius:12px;padding:14px 16px;color:var(--gold);margin-bottom:18px}
 textarea{width:100%;min-height:190px;background:#0e1116;color:var(--txt);
          border:1px solid var(--line);border-radius:10px;padding:12px;
          font:12.5px/1.6 ui-monospace,monospace;resize:vertical}
 .btn{display:inline-block;padding:11px 20px;border-radius:10px;text-decoration:none;
      font-weight:800;background:linear-gradient(135deg,#8fce3f,#6aa521);color:#0b0c0e}
</style>
</head>
<body>
<div class="wrap">

<h1>CPPS Sync</h1>
<p class="sub">Live site e kon file gulo missing ba purono</p>

<?php if (!$keyOk): ?>
  <div class="bad"><strong>Key lagbe.</strong> URL-e
    <code>?key=<?= htmlspecialchars($KEY) ?></code> jog korun.</div>
<?php else: ?>

  <div class="card">
    <h2 style="margin-top:0">Summary</h2>
    <ul class="files">
      <li><span class="pill p-missing">missing</span>
        <span>live e nei — upload korte hobe</span>
        <span class="sz"><?= count($missing) ?> file</span></li>
      <li><span class="pill p-diff">different</span>
        <span>live e purono version — replace korte hobe</span>
        <span class="sz"><?= count($diff) ?> file</span></li>
      <li><span class="pill p-same">same</span>
        <span>thik ache</span>
        <span class="sz"><?= count($same) ?> file</span></li>
    </ul>
    <p style="color:var(--dim);margin:14px 0 0">
      Site folder: <code><?= htmlspecialchars($ROOT) ?></code><br>
      PHP <?= htmlspecialchars(PHP_VERSION) ?>
    </p>
  </div>

  <?php if ($needed === 0): ?>
    <div class="good"><strong>Sob file mile gche.</strong> Live ekhon local project er
      sathe puropuri sync. Ekhon <code>cppsmigration.php?key=cpps-2026</code> chalan.</div>
  <?php else: ?>
    <div class="bad">
      <strong><?= $needed ?> ta file upload korte hobe.</strong>
      Egulo na thakle site 500 deye — kar<?= '' ?>on notun <code>functions.php</code>
      oi file gulo require kore. Niche-r list ta dhore FTP / File Manager diye
      upload korun, folder structure thik rekhe.
    </div>
  <?php endif; ?>

  <?php if ($missing): ?>
    <div class="card">
      <h2 style="margin-top:0">
        <span class="pill p-missing">missing</span>
        &nbsp;Live e ei file gulo NEI (<?= count($missing) ?>)</h2>
      <?php foreach (cs_group($missing) as $dir => $items): ?>
        <h3><?= htmlspecialchars($dir) ?></h3>
        <ul class="files">
          <?php foreach ($items as $rel => $v): ?>
            <li><span><?= htmlspecialchars($rel) ?></span>
                <span class="sz"><?= number_format($v[1]) ?> B lagbe</span></li>
          <?php endforeach; ?>
        </ul>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($diff): ?>
    <div class="card">
      <h2 style="margin-top:0">
        <span class="pill p-diff">different</span>
        &nbsp;Live e purono version (<?= count($diff) ?>)</h2>
      <?php foreach (cs_group($diff) as $dir => $items): ?>
        <h3><?= htmlspecialchars($dir) ?></h3>
        <ul class="files">
          <?php foreach ($items as $rel => $v): ?>
            <li><span><?= htmlspecialchars($rel) ?></span>
                <span class="sz">live <?= number_format($v[0]) ?> B
                  &rarr; lagbe <?= number_format($v[1]) ?> B</span></li>
          <?php endforeach; ?>
        </ul>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($needed): ?>
    <div class="card">
      <h2 style="margin-top:0">Upload list (copy kore nin)</h2>
      <p style="color:var(--dim);margin-top:0">Ei list ta local folder-e
        <code>D:\xamp\htdocs\gamesv</code> theke miliye upload korun.</p>
      <textarea readonly onclick="this.select()"><?php
        foreach (array_keys($missing) as $r) { echo $r, "\n"; }
        foreach (array_keys($diff) as $r)    { echo $r, "\n"; }
      ?></textarea>
    </div>
  <?php endif; ?>

  <div class="warn">
    <strong>Kheyal rakhben:</strong> <code>config/config.php</code> ei list-e nei —
    otate DB password ache ar live er ta thik-i ache, tai hath dieben na.
    <code>uploads/</code> ar <code>storage/</code> folder-o baad — segulo live er
    nijer data.
  </div>

  <?php if ($needed === 0): ?>
    <p><a class="btn" href="cppsmigration.php?key=<?= urlencode($KEY) ?>">
      Ekhon migration chalao &rarr;</a></p>
  <?php endif; ?>

<?php endif; ?>

<p style="color:var(--dim);font-size:13px;margin-top:28px">
  Kaj sese <code>cppssync.php</code> file ta delete kore deben.
</p>
</div>
</body>
</html>
