<?php
/**
 * Admin — Modules.
 *
 * One screen that decides what the public site shows. Every game in the
 * catalogue plus the three built-in modes (Quiz, Daily Spin, Chess) can be
 * put live or hidden from here, without opening each section.
 */
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();
require_manage();

$admin_title = 'Modules';
$admin_sub   = 'Everything the site can show — switch it live or hide it';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $do = post('do');

    if ($do === 'module') {
        $key = post('key');
        $allowed = [
            'quiz_enabled', 'spin_enabled', 'chess_enabled',
            'chess_ai_enabled', 'chess_pvp_enabled', 'quiz_level_mode',
        ];
        if (in_array($key, $allowed, true)) {
            set_setting($key, setting($key, '1') === '1' ? '0' : '1');
            flash('Updated.');
        }
        redirect('admin/modules.php');

    } elseif ($do === 'game') {
        q('UPDATE games SET is_active = 1 - is_active WHERE id = ?', [(int) post('id')]);
        flash('Visibility updated.');
        redirect('admin/modules.php');

    } elseif ($do === 'feature') {
        q('UPDATE games SET is_featured = 1 - is_featured WHERE id = ?', [(int) post('id')]);
        flash('Featured flag updated.');
        redirect('admin/modules.php');

    } elseif ($do === 'all') {
        q('UPDATE games SET is_active = ?', [post('state') === 'on' ? 1 : 0]);
        foreach (['quiz_enabled', 'spin_enabled', 'chess_enabled'] as $k) {
            set_setting($k, post('state') === 'on' ? '1' : '0');
        }
        flash(post('state') === 'on' ? 'Everything is live.' : 'Everything is hidden.');
        redirect('admin/modules.php');
    }
}

$games = fetch_all('SELECT * FROM games ORDER BY sort_order, id');

$modules = [
    [
        'key'   => 'quiz_enabled',
        'icon'  => '&#129504;',
        'name'  => 'Quiz Contest',
        'desc'  => 'Levels, timed questions and coin rewards.',
        'ready' => has_table('quiz_questions'),
        'stat'  => has_table('quiz_questions')
                   ? num((int) fetch_col('SELECT COUNT(*) FROM quiz_questions WHERE is_active = 1', [], 0))
                     . ' live questions' : 'not installed',
        'link'  => 'quiz.php',
    ],
    [
        'key'   => 'quiz_level_mode',
        'icon'  => '&#127937;',
        'name'  => 'Quiz level mode',
        'desc'  => 'Easy → Mid → Hard unlock chain. Off = free-play rounds.',
        'ready' => has_table('quiz_level_progress'),
        'stat'  => quiz_per_level() . ' questions per level',
        'link'  => 'quiz-levels.php',
    ],
    [
        'key'   => 'spin_enabled',
        'icon'  => '&#127920;',
        'name'  => 'Daily Spin',
        'desc'  => 'One free spin a day. Access can be handed out per player.',
        'ready' => has_table('spin_results'),
        'stat'  => setting('spin_access_mode', 'all') === 'assigned'
                   ? 'assigned players only' : 'open to everyone',
        'link'  => 'spin.php',
    ],
    [
        'key'   => 'chess_enabled',
        'icon'  => '&#9822;',
        'name'  => 'Chess',
        'desc'  => 'Practice against the engine and staked matches between players.',
        'ready' => has_table('chess_matches'),
        'stat'  => has_table('chess_matches')
                   ? num((int) fetch_col('SELECT COUNT(*) FROM chess_matches WHERE status = "active"', [], 0))
                     . ' matches in play' : 'not installed',
        'link'  => 'chess.php',
    ],
    [
        'key'   => 'chess_ai_enabled',
        'icon'  => '&#129302;',
        'name'  => 'Chess vs computer',
        'desc'  => 'The practice board. Rewards are capped at three wins a day.',
        'ready' => has_table('chess_matches'),
        'stat'  => '+' . num((int) setting('chess_ai_reward', '25')) . ' coins × level',
        'link'  => 'chess.php',
    ],
    [
        'key'   => 'chess_pvp_enabled',
        'icon'  => '&#129309;',
        'name'  => 'Chess challenges',
        'desc'  => 'Coin-staked matches with an invite link. Winner takes the pot.',
        'ready' => has_table('chess_matches'),
        'stat'  => num(chess_min_stake()) . '–' . num(chess_max_stake()) . ' coin stake',
        'link'  => 'chess.php',
    ],
];

require __DIR__ . '/includes/layout.php';
?>

<div class="adm-panel">
  <div class="adm-panel-head">
    <div>
      <h2>Built-in modes</h2>
      <p>Switching one off hides it from the menu, the games grid and its own page.</p>
    </div>
    <div class="adm-actions">
      <form method="post" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="do" value="all">
        <input type="hidden" name="state" value="on">
        <button class="btn btn-ghost btn-sm" type="submit">Everything live</button>
      </form>
      <form method="post" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="do" value="all">
        <input type="hidden" name="state" value="off">
        <button class="btn btn-ghost btn-sm" type="submit"
                data-confirm="Hide every game and every mode from the site?">Hide everything</button>
      </form>
    </div>
  </div>
  <div class="adm-panel-body flush">
    <div class="table-scroll">
      <table class="tbl" style="min-width:760px">
        <thead><tr><th>Mode</th><th>Detail</th><th>Status</th><th style="text-align:right">Action</th></tr></thead>
        <tbody>
        <?php foreach ($modules as $m):
          $on = setting($m['key'], '1') === '1'; ?>
          <tr>
            <td>
              <strong style="display:block"><?= $m['icon'] ?> <?= e($m['name']) ?></strong>
              <span class="text-dim"><?= e($m['desc']) ?></span>
            </td>
            <td class="text-muted"><?= $m['stat'] ?></td>
            <td>
              <?php if (!$m['ready']): ?>
                <span class="badge badge-red">not installed</span>
              <?php else: ?>
                <span class="badge <?= $on ? 'badge-green' : 'badge-red' ?>">
                  <?= $on ? 'live' : 'hidden' ?></span>
              <?php endif; ?>
            </td>
            <td>
              <div class="adm-actions">
                <a class="btn btn-ghost btn-xs" href="<?= admin_url($m['link']) ?>">Settings</a>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?><input type="hidden" name="do" value="module">
                  <input type="hidden" name="key" value="<?= e($m['key']) ?>">
                  <button class="btn <?= $on ? 'btn-ghost' : 'btn-primary' ?> btn-xs" type="submit"
                          <?= $m['ready'] ? '' : 'disabled' ?>>
                    <?= $on ? 'Hide' : 'Go live' ?></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="adm-panel">
  <div class="adm-panel-head">
    <div><h2>Games</h2><p><?= count($games) ?> in the catalogue</p></div>
    <a class="btn btn-ghost btn-sm" href="<?= admin_url('games.php') ?>">Manage games</a>
  </div>
  <div class="adm-panel-body flush">
    <div class="table-scroll">
      <table class="tbl" style="min-width:760px">
        <thead><tr><th>Game</th><th>Category</th><th>Plays</th><th>Status</th>
                   <th style="text-align:right">Action</th></tr></thead>
        <tbody>
        <?php foreach ($games as $g): ?>
          <tr>
            <td><strong><?= $g['icon'] ?> <?= e($g['title']) ?></strong>
              <span class="text-dim" style="display:block"><?= e($g['path']) ?></span></td>
            <td class="text-muted"><?= e($g['category']) ?></td>
            <td><?= num($g['plays']) ?></td>
            <td>
              <span class="badge <?= $g['is_active'] ? 'badge-green' : 'badge-red' ?>">
                <?= $g['is_active'] ? 'live' : 'hidden' ?></span>
              <?php if ($g['is_featured']): ?><span class="badge badge-gold">featured</span><?php endif; ?>
            </td>
            <td>
              <div class="adm-actions">
                <form method="post" style="display:inline">
                  <?= csrf_field() ?><input type="hidden" name="do" value="feature">
                  <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                  <button class="btn btn-ghost btn-xs" type="submit" title="Toggle featured">&#9733;</button>
                </form>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?><input type="hidden" name="do" value="game">
                  <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                  <button class="btn <?= $g['is_active'] ? 'btn-ghost' : 'btn-primary' ?> btn-xs" type="submit">
                    <?= $g['is_active'] ? 'Hide' : 'Go live' ?></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$games): ?>
          <tr><td colspan="5" class="text-muted center">No games in the catalogue yet.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
