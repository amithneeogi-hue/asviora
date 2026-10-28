<?php
/**
 * Admin — quiz levels.
 *
 * The bank is cut into levels by size: with 10 questions a level, a bank
 * of 300 is 30 levels, and adding another 50 questions makes it 35 without
 * anyone touching anything. This screen sets that size, the pass mark, the
 * clear bonus and the no-repeat window — globally, or per topic.
 */
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();
require_manage();

$admin_title = 'Quiz Levels';
$admin_sub   = 'Level size, pass mark, unlock rules and the no-repeat window';

if (!quiz_levels_ready()) {
    flash('The level tables are not installed yet. Open any page on the site once so the '
        . 'v4 migration runs, then come back.', 'error');
    redirect('admin/index.php');
}

// -------------------------------------------------------------------
// Save
// -------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $do = post('do');

    if ($do === 'global') {
        set_setting('quiz_questions_per_level', (string) max(1, (int) post('questions_per_level')));
        set_setting('quiz_pass_percent',        (string) max(1, min(100, (int) post('pass_percent'))));
        set_setting('quiz_repeat_block_days',   (string) max(0, (int) post('repeat_days')));
        set_setting('quiz_level_clear_bonus',   (string) max(0, (int) post('clear_bonus')));
        set_setting('quiz_unlock_next_difficulty', empty($_POST['ladder']) ? '0' : '1');
        set_setting('quiz_level_retry_free',    empty($_POST['retry_free']) ? '0' : '1');
        flash('Level rules saved.');

    } elseif ($do === 'rules' && post('drop_id') !== '') {
        // the Remove button in a row submits the same form with its own name
        q('DELETE FROM quiz_level_config WHERE id = ? AND category_id IS NOT NULL',
          [(int) post('drop_id')]);
        flash('Topic rule removed — it falls back to the global rule.', 'info');

    } elseif ($do === 'rules') {
        $ids  = $_POST['cfg_id']   ?? [];
        $per  = $_POST['cfg_per']  ?? [];
        $pass = $_POST['cfg_pass'] ?? [];
        $maxl = $_POST['cfg_max']  ?? [];
        $secs = $_POST['cfg_sec']  ?? [];
        $rew  = $_POST['cfg_rew']  ?? [];
        $bon  = $_POST['cfg_bon']  ?? [];

        foreach ($ids as $i => $id) {
            update_row('quiz_level_config', [
                'questions_per_level' => max(1, (int) ($per[$i] ?? 10)),
                'pass_percent'        => max(1, min(100, (int) ($pass[$i] ?? 60))),
                'max_levels'          => max(0, (int) ($maxl[$i] ?? 0)),
                'time_seconds'        => (int) ($secs[$i] ?? 0) > 0 ? (int) $secs[$i] : null,
                'reward_coins'        => (int) ($rew[$i] ?? 0) > 0 ? (int) $rew[$i] : null,
                'level_clear_bonus'   => max(0, (int) ($bon[$i] ?? 0)),
            ], 'id = ?', [(int) $id]);
        }
        flash('Per-level rules saved.');

    } elseif ($do === 'add_topic_rule') {
        $cat  = (int) post('category_id');
        $diff = post('difficulty');
        if ($cat > 0 && in_array($diff, ['easy', 'mid', 'hard'], true)) {
            $base = quiz_level_cfg(0, $diff);
            try {
                insert_row('quiz_level_config', [
                    'category_id'         => $cat,
                    'difficulty'          => $diff,
                    'questions_per_level' => $base['questions_per_level'],
                    'pass_percent'        => $base['pass_percent'],
                    'level_clear_bonus'   => $base['level_clear_bonus'],
                ]);
                flash('Topic rule added — set it below.');
            } catch (Throwable $e) {
                flash('That topic already has a rule for this level.', 'info');
            }
        }

    } elseif ($do === 'reset_player') {
        $uid = (int) post('user_id');
        q('DELETE FROM quiz_user_levels WHERE user_id = ?', [$uid]);
        flash('That player\'s level progress was reset to level 1.', 'info');

    } elseif ($do === 'clear_history') {
        $uid = (int) post('user_id');
        q('DELETE FROM quiz_question_history WHERE user_id = ?', [$uid]);
        flash('Question history cleared — every question is available to that player again.', 'info');
    }

    redirect('admin/quiz-levels.php');
}

// -------------------------------------------------------------------
// Data
// -------------------------------------------------------------------
$cats = fetch_all('SELECT id, name FROM quiz_categories ORDER BY name');
$catName = [];
foreach ($cats as $c) {
    $catName[(int) $c['id']] = $c['name'];
}

$rules = fetch_all(
    'SELECT * FROM quiz_level_config
      ORDER BY category_id IS NOT NULL, category_id,
               FIELD(difficulty,"easy","mid","hard")');

$overview = [];
foreach (quiz_difficulty_order() as $d) {
    $bank = quiz_bank_count(0, $d);
    $cfg  = quiz_level_cfg(0, $d);
    $overview[$d] = [
        'label'  => $cfg['label'],
        'bank'   => $bank,
        'per'    => $cfg['questions_per_level'],
        'levels' => quiz_level_total(0, $d),
        'pass'   => $cfg['pass_percent'],
    ];
}

$players = fetch_all(
    'SELECT u.id, u.username, u.name,
            SUM(l.status = "passed") AS passed,
            MAX(l.updated_at) AS last_play,
            (SELECT COUNT(*) FROM quiz_question_history h WHERE h.user_id = u.id) AS seen
       FROM quiz_user_levels l
       JOIN users u ON u.id = l.user_id
      GROUP BY u.id, u.username, u.name
      ORDER BY passed DESC, last_play DESC
      LIMIT 25');

$blockDays = (int) setting('quiz_repeat_block_days', '180');

require __DIR__ . '/includes/layout.php';
?>

<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:22px">
  <?php foreach ($overview as $o): ?>
    <div class="card stat-card">
      <div class="sc-num"><?= (int) $o['levels'] ?></div>
      <div class="sc-lbl"><?= e($o['label']) ?> levels &middot;
        <?= num($o['bank']) ?> questions</div>
    </div>
  <?php endforeach; ?>
  <div class="card stat-card">
    <div class="sc-num"><?= $blockDays ?></div>
    <div class="sc-lbl">Days before a question can return</div>
  </div>
</div>

<div class="adm-form-grid">

  <!-- ==================== GLOBAL RULES ==================== -->
  <div class="adm-panel">
    <div class="adm-panel-head">
      <div><h2>Global rules</h2>
        <p>These apply everywhere unless a topic below overrides them.</p></div>
    </div>
    <div class="adm-panel-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="global">

        <div class="form-group">
          <label class="form-label">Questions per level</label>
          <input class="form-control" type="number" min="1" max="100" name="questions_per_level"
                 value="<?= (int) setting('quiz_questions_per_level', '10') ?>">
          <p class="form-hint">
            300 questions at 10 a level is 30 levels. The number of levels follows the
            bank automatically — upload more questions and more levels appear.
          </p>
        </div>

        <div class="form-group">
          <label class="form-label">Pass mark (%)</label>
          <input class="form-control" type="number" min="1" max="100" name="pass_percent"
                 value="<?= (int) setting('quiz_pass_percent', '60') ?>">
          <p class="form-hint">Below this the level has to be replayed before the next one opens.</p>
        </div>

        <div class="form-group">
          <label class="form-label">Do not repeat a question for (days)</label>
          <input class="form-control" type="number" min="0" max="3650" name="repeat_days"
                 value="<?= $blockDays ?>">
          <p class="form-hint">
            180 days is six months. Once a player has been shown a question it is kept out
            of their draws for this long; if the bank runs dry the oldest ones come back first.
            Set 0 to switch the rule off.
          </p>
        </div>

        <div class="form-group">
          <label class="form-label">Coins for clearing a level</label>
          <input class="form-control" type="number" min="0" name="clear_bonus"
                 value="<?= (int) setting('quiz_level_clear_bonus', '50') ?>">
          <p class="form-hint">Paid once, the first time a level is passed.</p>
        </div>

        <label class="check">
          <input type="checkbox" name="ladder" value="1"
                 <?= setting('quiz_unlock_next_difficulty', '1') === '1' ? 'checked' : '' ?>>
          <span>Every Easy level must be cleared before Mid opens, and every Mid before Hard</span>
        </label>
        <label class="check">
          <input type="checkbox" name="retry_free" value="1"
                 <?= setting('quiz_level_retry_free', '1') === '1' ? 'checked' : '' ?>>
          <span>A failed level can be retried immediately, free</span>
        </label>

        <div class="row mt-3"><button class="btn btn-primary" type="submit">Save rules</button></div>
      </form>
    </div>
  </div>

  <!-- ==================== HOW IT WORKS ==================== -->
  <div class="adm-panel">
    <div class="adm-panel-head"><h2>What the players see</h2></div>
    <div class="adm-panel-body">
      <table class="tbl">
        <tbody>
          <?php foreach ($overview as $k => $o): ?>
            <tr>
              <td><strong><?= e($o['label']) ?></strong></td>
              <td class="text-dim"><?= num($o['bank']) ?> questions</td>
              <td class="text-dim">÷ <?= (int) $o['per'] ?></td>
              <td><span class="badge badge-green"><?= (int) $o['levels'] ?> levels</span></td>
              <td class="text-dim">pass at <?= (int) $o['pass'] ?>%</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <p class="form-hint mt-3">
        <strong>No two players get the same set.</strong> Every account carries its own
        shuffle key, and the bank is reordered by that key before it is cut into levels.
        Two players sitting side by side on level 3 are answering different questions
        from the same bank.
      </p>
      <p class="form-hint">
        <strong>Questions do not come back.</strong> Everything served is recorded against
        the player. Anything seen inside the window above is filtered out of their next
        draw and replaced from the unseen pool.
      </p>
      <p class="form-hint">
        <strong>The ladder.</strong> Level 2 opens when level 1 is passed. Mid opens when
        every Easy level is passed. Hard opens when every Mid level is passed.
      </p>
    </div>
  </div>
</div>

<!-- ==================== PER-LEVEL RULES ==================== -->
<div class="adm-panel mt-4">
  <div class="adm-panel-head">
    <div><h2>Rules per level</h2>
      <p>The three global rows apply to everything. Add a topic row to give one topic
         its own level size, clock or reward.</p></div>
  </div>
  <div class="adm-panel-body">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="rules">
      <div class="table-scroll">
        <table class="tbl" style="min-width:900px">
          <thead>
            <tr><th>Applies to</th><th>Level</th><th>Q / level</th><th>Pass %</th>
                <th>Max levels</th><th>Seconds</th><th>Coins / right</th>
                <th>Clear bonus</th><th></th></tr>
          </thead>
          <tbody>
          <?php foreach ($rules as $i => $r):
                $isGlobal = $r['category_id'] === null; ?>
            <tr>
              <td>
                <?php if ($isGlobal): ?>
                  <strong>Everything</strong><div class="text-dim">global default</div>
                <?php else: ?>
                  <strong><?= e($catName[(int) $r['category_id']] ?? 'Topic #' . $r['category_id']) ?></strong>
                <?php endif; ?>
                <input type="hidden" name="cfg_id[<?= $i ?>]" value="<?= (int) $r['id'] ?>">
              </td>
              <td><span class="badge badge-info"><?= e($r['difficulty']) ?></span></td>
              <td><input class="form-control" style="width:90px" type="number" min="1"
                         name="cfg_per[<?= $i ?>]" value="<?= (int) $r['questions_per_level'] ?>"></td>
              <td><input class="form-control" style="width:80px" type="number" min="1" max="100"
                         name="cfg_pass[<?= $i ?>]" value="<?= (int) $r['pass_percent'] ?>"></td>
              <td><input class="form-control" style="width:90px" type="number" min="0"
                         name="cfg_max[<?= $i ?>]" value="<?= (int) $r['max_levels'] ?>"
                         placeholder="0 = all"></td>
              <td><input class="form-control" style="width:90px" type="number" min="0"
                         name="cfg_sec[<?= $i ?>]" value="<?= (int) $r['time_seconds'] ?>"
                         placeholder="default"></td>
              <td><input class="form-control" style="width:90px" type="number" min="0"
                         name="cfg_rew[<?= $i ?>]" value="<?= (int) $r['reward_coins'] ?>"
                         placeholder="default"></td>
              <td><input class="form-control" style="width:90px" type="number" min="0"
                         name="cfg_bon[<?= $i ?>]" value="<?= (int) $r['level_clear_bonus'] ?>"></td>
              <td>
                <?php if (!$isGlobal): ?>
                  <button class="btn btn-ghost btn-sm" type="submit"
                          name="drop_id" value="<?= (int) $r['id'] ?>"
                          data-confirm="Remove this topic rule and fall back to the global one?">
                    Remove</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="row mt-3"><button class="btn btn-primary" type="submit">Save all rules</button></div>
    </form>

    <form method="post" class="mt-3 row" style="gap:10px;align-items:flex-end">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="add_topic_rule">
      <div class="form-group" style="margin:0">
        <label class="form-label">Topic</label>
        <select class="form-control" name="category_id">
          <?php foreach ($cats as $c): ?>
            <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label">Level</label>
        <select class="form-control" name="difficulty">
          <option value="easy">Easy</option><option value="mid">Mid</option><option value="hard">Hard</option>
        </select>
      </div>
      <button class="btn btn-ghost" type="submit">Add topic rule</button>
    </form>
  </div>
</div>

<!-- ==================== PLAYER PROGRESS ==================== -->
<div class="adm-panel mt-4">
  <div class="adm-panel-head">
    <div><h2>Player progress</h2>
      <p>Resetting progress sends a player back to level 1. Clearing history makes every
         question available to them again straight away.</p></div>
  </div>
  <div class="adm-panel-body flush">
    <?php if (!$players): ?>
      <div class="empty"><div class="em-ico">&#129504;</div>
        <h3>Nobody has played a level yet</h3></div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="tbl" style="min-width:700px">
          <thead><tr><th>Player</th><th>Levels passed</th><th>Questions seen</th>
                     <th>Last played</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($players as $p): ?>
            <tr>
              <td><strong><?= e($p['username']) ?></strong>
                <div class="text-dim"><?= e($p['name']) ?></div></td>
              <td><span class="badge badge-green"><?= (int) $p['passed'] ?></span></td>
              <td class="text-dim"><?= num((int) $p['seen']) ?></td>
              <td class="text-dim"><?= fdate($p['last_play'], 'd M Y, H:i') ?></td>
              <td>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="reset_player">
                  <input type="hidden" name="user_id" value="<?= (int) $p['id'] ?>">
                  <button class="btn btn-ghost btn-sm" type="submit"
                          data-confirm="Send <?= e($p['username']) ?> back to level 1?">Reset</button>
                </form>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="clear_history">
                  <input type="hidden" name="user_id" value="<?= (int) $p['id'] ?>">
                  <button class="btn btn-ghost btn-sm" type="submit"
                          data-confirm="Let <?= e($p['username']) ?> see every question again?">
                    Clear history</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
