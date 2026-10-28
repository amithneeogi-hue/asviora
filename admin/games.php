<?php
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();
require_manage();

$admin_title = 'Games';
$admin_sub   = 'Control which titles appear on the site and how they reward players';

$editId = (int) get('edit');
$edit   = $editId ? fetch_one('SELECT * FROM games WHERE id = ?', [$editId]) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $do = post('do');
    $id = (int) post('id');

    if ($do === 'save') {
        $title = post('title');
        $path  = trim(post('path'), '/');
        $err   = [];

        if (mb_strlen($title) < 2) $err[] = 'Enter a game title.';
        if ($path === '')          $err[] = 'Enter the folder path of the game.';
        if (!preg_match('#^[\w\-/]+$#', $path)) $err[] = 'The path may only contain letters, numbers, dashes and slashes.';

        if ($err) {
            foreach ($err as $x) flash($x, 'error');
        } else {
            $slug = unique_slug('games',
                                post('slug') !== '' ? slugify(post('slug')) : slugify($title),
                                $id ?: null);
            $data = [
                'slug'         => $slug,
                'title'        => $title,
                'tagline'      => post('tagline') !== '' ? mb_substr(post('tagline'), 0, 180) : null,
                'description'  => post('description') !== '' ? post('description') : null,
                'category'     => post('category') !== '' ? post('category') : 'Puzzle',
                'path'         => $path,
                'icon'         => post('icon') !== '' ? mb_substr(post('icon'), 0, 20) : null,
                'entry_coins'  => max(0, (int) post('entry_coins')),
                'reward_coins' => max(0, (int) post('reward_coins')),
                'score_order'  => post('score_order') === 'asc' ? 'asc' : 'desc',
                'is_active'    => !empty($_POST['is_active']) ? 1 : 0,
                'is_featured'  => !empty($_POST['is_featured']) ? 1 : 0,
                'sort_order'   => (int) post('sort_order'),
            ];

            $thumb = save_upload('thumbnail', 'games');
            if ($thumb) {
                if ($edit) delete_upload($edit['thumbnail'], 'games');
                $data['thumbnail'] = $thumb;
            } elseif ($edit && post('remove_thumb') === '1') {
                delete_upload($edit['thumbnail'], 'games');
                $data['thumbnail'] = null;
            }

            if ($id) {
                update_row('games', $data, 'id = ?', [$id]);
                flash('Game updated.');
            } else {
                insert_row('games', $data);
                flash('Game added.');
            }
            redirect('admin/games.php');
        }
    } elseif ($do === 'toggle') {
        q('UPDATE games SET is_active = 1 - is_active WHERE id = ?', [$id]);
        flash('Visibility updated.');
        redirect('admin/games.php');
    } elseif ($do === 'feature') {
        q('UPDATE games SET is_featured = 1 - is_featured WHERE id = ?', [$id]);
        flash('Featured flag updated.');
        redirect('admin/games.php');
    } elseif ($do === 'delete') {
        $row = fetch_one('SELECT thumbnail FROM games WHERE id = ?', [$id]);
        if ($row) {
            delete_upload($row['thumbnail'], 'games');
            q('DELETE FROM games WHERE id = ?', [$id]);
            flash('Game removed along with its score history.');
        }
        redirect('admin/games.php');
    } elseif ($do === 'reset_plays') {
        q('UPDATE games SET plays = 0 WHERE id = ?', [$id]);
        flash('Play counter reset.');
        redirect('admin/games.php');
    }
}

$games = fetch_all(
    'SELECT g.*, COUNT(s.id) AS rounds, COALESCE(MAX(s.score),0) AS best
     FROM games g LEFT JOIN scores s ON s.game_id = g.id
     GROUP BY g.id ORDER BY g.sort_order, g.id');

$thumbUrl = null;
if ($edit && $edit['thumbnail'] && file_exists(UPLOAD_PATH . '/games/' . $edit['thumbnail'])) {
    $thumbUrl = UPLOAD_URL . '/games/' . rawurlencode($edit['thumbnail']);
}

require __DIR__ . '/includes/layout.php';
?>

<div class="adm-panel">
  <div class="adm-panel-head">
    <div><h2>Game catalogue</h2><p><?= count($games) ?> title<?= count($games) === 1 ? '' : 's' ?> installed</p></div>
    <a href="#gameForm" class="btn btn-primary btn-sm">&#10133; Add a game</a>
  </div>
  <div class="adm-panel-body flush">
    <div class="table-scroll">
      <table class="tbl" style="min-width:900px">
        <thead>
          <tr><th style="width:52px">#</th><th>Game</th><th>Category</th><th>Reward</th>
              <th>Rounds</th><th>Best</th><th>Status</th><th style="text-align:right">Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($games as $g): ?>
            <tr>
              <td class="text-dim"><?= (int) $g['sort_order'] ?></td>
              <td>
                <strong style="display:block"><?= $g['icon'] ?> <?= e($g['title']) ?></strong>
                <span class="text-dim"><?= e($g['path']) ?>/index.html</span>
              </td>
              <td class="text-muted"><?= e($g['category']) ?></td>
              <td class="text-gold">+<?= num($g['reward_coins']) ?></td>
              <td><?= num($g['rounds']) ?></td>
              <td class="text-green"><?= $g['best'] ? num($g['best']) : '&mdash;' ?></td>
              <td>
                <span class="badge <?= $g['is_active'] ? 'badge-green' : 'badge-red' ?>">
                  <?= $g['is_active'] ? 'live' : 'hidden' ?></span>
                <?php if ($g['is_featured']): ?>
                  <span class="badge badge-gold">featured</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="adm-actions">
                  <a href="<?= url('play.php?game=' . urlencode($g['slug'])) ?>" target="_blank"
                     rel="noopener" class="btn btn-ghost btn-xs">Play</a>
                  <a href="<?= admin_url('games.php?edit=' . (int) $g['id']) ?>#gameForm"
                     class="btn btn-ghost btn-xs">Edit</a>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?><input type="hidden" name="do" value="toggle">
                    <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                    <button class="btn btn-ghost btn-xs" type="submit">
                      <?= $g['is_active'] ? 'Hide' : 'Show' ?></button>
                  </form>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?><input type="hidden" name="do" value="feature">
                    <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                    <button class="btn btn-ghost btn-xs" type="submit" title="Toggle featured">&#9733;</button>
                  </form>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?><input type="hidden" name="do" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                    <button class="btn btn-danger btn-xs" type="submit"
                      data-confirm="Delete &quot;<?= e($g['title']) ?>&quot;? All <?= (int) $g['rounds'] ?> score record(s) go with it.">
                      Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$games): ?>
            <tr><td colspan="8" class="text-muted center">No games yet. Add one below.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="adm-panel" id="gameForm">
  <div class="adm-panel-head">
    <h2><?= $edit ? 'Edit: ' . e($edit['title']) : 'Add a new game' ?></h2>
    <?php if ($edit): ?>
      <a href="<?= admin_url('games.php') ?>" class="btn btn-ghost btn-xs">Cancel edit</a>
    <?php endif; ?>
  </div>
  <div class="adm-panel-body">
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save">
      <input type="hidden" name="id" value="<?= $edit ? (int) $edit['id'] : '' ?>">

      <div class="adm-form-grid">
        <div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="title">Title <span class="req">*</span></label>
              <input class="form-control" id="title" name="title"
                     value="<?= e($edit['title'] ?? '') ?>" placeholder="Sudoku" required>
            </div>
            <div class="form-group">
              <label class="form-label" for="slug">Slug</label>
              <input class="form-control" id="slug" name="slug"
                     value="<?= e($edit['slug'] ?? '') ?>" placeholder="auto from the title">
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="tagline">Tagline</label>
            <input class="form-control" id="tagline" name="tagline" maxlength="180"
                   value="<?= e($edit['tagline'] ?? '') ?>" placeholder="Pure logic, zero luck">
          </div>

          <div class="form-group">
            <label class="form-label" for="description">Description</label>
            <textarea class="form-control" id="description" name="description"
                      placeholder="Shown on the game page."><?= e($edit['description'] ?? '') ?></textarea>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="path">Folder path <span class="req">*</span></label>
              <input class="form-control" id="path" name="path"
                     value="<?= e($edit['path'] ?? '') ?>" placeholder="games/sudoku" required>
              <div class="form-hint">Relative to the site root. Must contain <code>index.html</code>.</div>
            </div>
            <div class="form-group">
              <label class="form-label" for="category">Category</label>
              <input class="form-control" id="category" name="category"
                     value="<?= e($edit['category'] ?? '') ?>" placeholder="Logic" list="catList">
              <datalist id="catList">
                <?php foreach (fetch_all('SELECT DISTINCT category FROM games') as $c): ?>
                  <option value="<?= e($c['category']) ?>"></option>
                <?php endforeach; ?>
              </datalist>
            </div>
          </div>
        </div>

        <div>
          <div class="form-group">
            <label class="form-label">Thumbnail</label>
            <div class="adm-thumb" id="thumbBox">
              <?php if ($thumbUrl): ?>
                <img src="<?= e($thumbUrl) ?>" alt="">
              <?php else: ?>
                <span class="ph">Falls back to the icon</span>
              <?php endif; ?>
            </div>
            <input class="form-control" type="file" name="thumbnail" id="thumbInput" accept="image/*">
            <?php if ($thumbUrl): ?>
              <label class="check mt-2"><input type="checkbox" name="remove_thumb" value="1">
                <span>Remove on save</span></label>
            <?php endif; ?>
          </div>

          <div class="form-group">
            <label class="form-label" for="icon">Icon / emoji</label>
            <input class="form-control" id="icon" name="icon" maxlength="20"
                   value="<?= e($edit['icon'] ?? '') ?>" placeholder="&#129513;">
            <div class="form-hint">Used when no thumbnail is set. Emoji or an HTML entity.</div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="entry_coins">Entry cost</label>
              <input class="form-control" type="number" id="entry_coins" name="entry_coins" min="0"
                     value="<?= (int) ($edit['entry_coins'] ?? 0) ?>">
            </div>
            <div class="form-group">
              <label class="form-label" for="reward_coins">Win reward</label>
              <input class="form-control" type="number" id="reward_coins" name="reward_coins" min="0"
                     value="<?= (int) ($edit['reward_coins'] ?? 50) ?>">
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="score_order">Ranking</label>
              <select class="form-select" id="score_order" name="score_order">
                <option value="desc" <?= ($edit['score_order'] ?? 'desc') === 'desc' ? 'selected' : '' ?>>
                  Higher score is better</option>
                <option value="asc" <?= ($edit['score_order'] ?? '') === 'asc' ? 'selected' : '' ?>>
                  Lower score is better</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" for="sort_order">Sort order</label>
              <input class="form-control" type="number" id="sort_order" name="sort_order"
                     value="<?= (int) ($edit['sort_order'] ?? 0) ?>">
            </div>
          </div>

          <div class="form-group">
            <label class="adm-switch">
              <input type="checkbox" name="is_active" value="1"
                     <?= (!$edit || $edit['is_active']) ? 'checked' : '' ?>>
              <span class="track"></span><span>Visible on the website</span>
            </label>
          </div>
          <div class="form-group">
            <label class="adm-switch">
              <input type="checkbox" name="is_featured" value="1"
                     <?= (!empty($edit['is_featured'])) ? 'checked' : '' ?>>
              <span class="track"></span><span>Feature on the homepage</span>
            </label>
          </div>

          <button class="btn btn-primary btn-block btn-lg" type="submit">
            <?= $edit ? 'Save Game' : 'Add Game' ?></button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php
$admin_foot = <<<'HTML'
<script>
(function () {
  var ti = document.getElementById('thumbInput');
  if (ti) ti.addEventListener('change', function () {
    var f = ti.files && ti.files[0];
    if (f) document.getElementById('thumbBox').innerHTML =
      '<img src="' + URL.createObjectURL(f) + '" alt="">';
  });
})();
</script>
HTML;
require __DIR__ . '/includes/layout_end.php';
