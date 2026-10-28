<?php
/**
 * Admin — Modules.
 *
 * One screen that takes anything on the site live or hides it: every game
 * in the catalogue, the quiz, the spin wheel, chess (offline and online
 * separately), leaderboards, the blog and the mobile API.
 *
 * Hiding is instant and reversible — nothing is deleted, no scores are
 * touched, and a hidden module simply stops appearing in the navigation
 * and turns its own pages away.
 */
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();
require_manage();

$admin_title = 'Modules';
$admin_sub   = 'Take any game or feature live, or hide it — instantly';

if (!has_table('modules')) {
    flash('The modules table is not installed yet. Open any page on the site once so the '
        . 'v4 migration can run, then come back.', 'error');
    redirect('admin/index.php');
}

// -------------------------------------------------------------------
// Actions
// -------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $do = post('do');

    if ($do === 'toggle_module') {
        $key  = post('key');
        $live = post('state') === '1';
        module_set($key, $live, (int) $A['id']);
        flash(module_label($key) . ' is now ' . ($live ? 'LIVE' : 'HIDDEN') . '.',
              $live ? 'success' : 'info');

    } elseif ($do === 'toggle_nav') {
        $key = post('key');
        module_set_nav($key, post('state') === '1', (int) $A['id']);
        flash('Navigation updated.');

    } elseif ($do === 'toggle_game') {
        $id   = (int) post('id');
        $live = post('state') === '1';
        q('UPDATE games SET is_active = ? WHERE id = ?', [$live ? 1 : 0, $id]);
        $t = fetch_col('SELECT title FROM games WHERE id = ?', [$id], 'Game');
        flash($t . ' is now ' . ($live ? 'LIVE' : 'HIDDEN') . '.', $live ? 'success' : 'info');

    } elseif ($do === 'toggle_feature_game') {
        $id = (int) post('id');
        q('UPDATE games SET is_featured = 1 - is_featured WHERE id = ?', [$id]);
        flash('Featured flag updated.');

    } elseif ($do === 'bulk') {
        $live = post('state') === '1';
        $what = post('what');

        if ($what === 'games') {
            q('UPDATE games SET is_active = ?', [$live ? 1 : 0]);
            flash('Every game is now ' . ($live ? 'live' : 'hidden') . '.',
                  $live ? 'success' : 'info');
        } elseif ($what === 'features') {
            q('UPDATE modules SET is_active = ?, updated_by = ? WHERE kind = "feature"',
              [$live ? 1 : 0, (int) $A['id']]);
            modules_all(true);
            flash('Every feature is now ' . ($live ? 'live' : 'hidden') . '.',
                  $live ? 'success' : 'info');
        }
    }

    redirect('admin/modules.php');
}

// -------------------------------------------------------------------
// Data
// -------------------------------------------------------------------
$modules = modules_all(true);
$games   = fetch_all(
    'SELECT g.*,
            (SELECT COUNT(*) FROM scores s WHERE s.game_id = g.id) AS rounds
       FROM games g ORDER BY g.sort_order, g.title');

$quizCount = has_table('quiz_questions')
    ? (int) fetch_col('SELECT COUNT(*) FROM quiz_questions WHERE is_active = 1', [], 0) : 0;
$spinCount = has_table('spin_results')
    ? (int) fetch_col('SELECT COUNT(*) FROM spin_results WHERE spin_date = CURDATE()', [], 0) : 0;

/** Extra line of context under a module name. */
function module_note(string $key, int $quizCount, int $spinCount): string
{
    switch ($key) {
        case 'quiz':         return $quizCount . ' live questions';
        case 'spin':         return $spinCount . ' spins today';
        case 'chess_online': return chess_online_on() ? 'Accepting online games' : 'Offline modes only';
        case 'mobile_api':   return 'Android app talks to /api/v1';
        default:             return '';
    }
}

$liveModules = count(array_filter($modules, static fn($m) => (int) $m['is_active'] === 1));
$liveGames   = count(array_filter($games,   static fn($g) => (int) $g['is_active'] === 1));

require __DIR__ . '/includes/layout.php';
?>

<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:22px">
  <div class="card stat-card"><div class="sc-num"><?= $liveGames ?>/<?= count($games) ?></div>
    <div class="sc-lbl">Games live</div></div>
  <div class="card stat-card"><div class="sc-num"><?= $liveModules ?>/<?= count($modules) ?></div>
    <div class="sc-lbl">Features live</div></div>
  <div class="card stat-card"><div class="sc-num"><?= num($quizCount) ?></div>
    <div class="sc-lbl">Quiz questions</div></div>
  <div class="card stat-card"><div class="sc-num"><?= chess_online_on() ? 'On' : 'Off' ?></div>
    <div class="sc-lbl">Online chess</div></div>
</div>

<!-- ============================ FEATURES ============================ -->
<div class="adm-panel">
  <div class="adm-panel-head">
    <div>
      <h2>Site features</h2>
      <p>Hiding a feature removes it from the menu and turns its pages away.
         Nothing is deleted — switch it back on and it returns exactly as it was.</p>
    </div>
    <div class="row" style="gap:8px;margin:0">
      <form method="post" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="bulk">
        <input type="hidden" name="what" value="features">
        <input type="hidden" name="state" value="1">
        <button class="btn btn-ghost btn-sm" type="submit">All live</button>
      </form>
      <form method="post" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="bulk">
        <input type="hidden" name="what" value="features">
        <input type="hidden" name="state" value="0">
        <button class="btn btn-ghost btn-sm" type="submit"
                data-confirm="Hide every feature on the site?">All hidden</button>
      </form>
    </div>
  </div>

  <div class="adm-panel-body flush">
    <div class="table-scroll">
      <table class="tbl" style="min-width:720px">
        <thead>
          <tr><th style="width:44px"></th><th>Feature</th><th>Status</th>
              <th>In menu</th><th style="width:190px">Action</th></tr>
        </thead>
        <tbody>
        <?php foreach ($modules as $key => $m):
              $live = (int) $m['is_active'] === 1;
              $note = module_note($key, $quizCount, $spinCount); ?>
          <tr>
            <td style="font-size:22px"><?= $m['icon'] ?></td>
            <td>
              <strong><?= e($m['label']) ?></strong>
              <div class="text-dim"><?= e($m['blurb']) ?></div>
              <?php if ($note): ?>
                <div class="text-dim" style="font-size:12px"><?= e($note) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge badge-<?= $live ? 'green' : 'muted' ?>">
                <?= $live ? 'LIVE' : 'HIDDEN' ?></span>
            </td>
            <td>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="toggle_nav">
                <input type="hidden" name="key" value="<?= e($key) ?>">
                <input type="hidden" name="state" value="<?= (int) $m['nav_visible'] ? '0' : '1' ?>">
                <button class="btn btn-ghost btn-sm" type="submit" <?= $live ? '' : 'disabled' ?>>
                  <?= (int) $m['nav_visible'] ? 'Shown' : 'Hidden' ?>
                </button>
              </form>
            </td>
            <td>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="toggle_module">
                <input type="hidden" name="key" value="<?= e($key) ?>">
                <input type="hidden" name="state" value="<?= $live ? '0' : '1' ?>">
                <button class="btn btn-<?= $live ? 'ghost' : 'primary' ?> btn-sm" type="submit"
                        <?= $live ? 'data-confirm="Hide ' . e($m['label']) . ' from the whole site?"' : '' ?>>
                  <?= $live ? 'Hide it' : 'Take it live' ?>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ============================= GAMES ============================== -->
<div class="adm-panel mt-4">
  <div class="adm-panel-head">
    <div>
      <h2>Games</h2>
      <p>Every game in the catalogue, including the quiz and chess.
         A hidden game disappears from the arcade and its play page redirects.</p>
    </div>
    <div class="row" style="gap:8px;margin:0">
      <a class="btn btn-ghost btn-sm" href="<?= admin_url('games.php') ?>">Edit game details</a>
      <form method="post" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="bulk">
        <input type="hidden" name="what" value="games">
        <input type="hidden" name="state" value="1">
        <button class="btn btn-ghost btn-sm" type="submit">All live</button>
      </form>
      <form method="post" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="bulk">
        <input type="hidden" name="what" value="games">
        <input type="hidden" name="state" value="0">
        <button class="btn btn-ghost btn-sm" type="submit"
                data-confirm="Hide every game?">All hidden</button>
      </form>
    </div>
  </div>

  <div class="adm-panel-body flush">
    <?php if (!module_on('games')): ?>
      <div class="alert alert-info" style="margin:16px">
        <span>&#8505;</span>
        The <strong>Games arcade</strong> feature is switched off above, so none of these
        are reachable right now whatever their own state says.
      </div>
    <?php endif; ?>

    <div class="table-scroll">
      <table class="tbl" style="min-width:780px">
        <thead>
          <tr><th style="width:44px"></th><th>Game</th><th>Category</th>
              <th>Rounds</th><th>Status</th><th>Featured</th><th style="width:170px">Action</th></tr>
        </thead>
        <tbody>
        <?php foreach ($games as $g): $live = (int) $g['is_active'] === 1; ?>
          <tr>
            <td style="font-size:22px"><?= $g['icon'] ?></td>
            <td>
              <strong><?= e($g['title']) ?></strong>
              <div class="text-dim"><?= e($g['tagline']) ?></div>
            </td>
            <td class="text-dim"><?= e($g['category']) ?></td>
            <td class="text-dim"><?= num((int) $g['rounds']) ?></td>
            <td>
              <span class="badge badge-<?= $live ? 'green' : 'muted' ?>">
                <?= $live ? 'LIVE' : 'HIDDEN' ?></span>
            </td>
            <td>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="toggle_feature_game">
                <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit">
                  <?= (int) $g['is_featured'] ? '&#11088; Yes' : 'No' ?>
                </button>
              </form>
            </td>
            <td>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="toggle_game">
                <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                <input type="hidden" name="state" value="<?= $live ? '0' : '1' ?>">
                <button class="btn btn-<?= $live ? 'ghost' : 'primary' ?> btn-sm" type="submit">
                  <?= $live ? 'Hide it' : 'Take it live' ?>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<p class="form-hint mt-3">
  Hidden modules are enforced on the server, not just in the menu — a player who
  bookmarked the page still gets turned away, and the mobile API stops serving it too.
</p>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
