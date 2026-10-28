<?php
/**
 * Admin — Quiz Levels.
 *
 * The level map, the pass mark, the repeat window and the marks are all set
 * here. How many levels a difficulty has follows from the bank:
 *
 *      300 live questions / 10 questions per level = 30 levels
 *
 * unless a difficulty is pinned to a fixed count below.
 */
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();
require_manage();

$admin_title = 'Quiz Levels';
$admin_sub   = 'Level size, pass mark, marks and the no-repeat window';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $do = post('do');

    if ($do === 'settings') {
        set_setting('quiz_level_mode',          post('level_mode') === '1' ? '1' : '0');
        set_setting('quiz_questions_per_level', (string) max(1, (int) post('per_level')));
        set_setting('quiz_pass_percent',        (string) min(100, max(1, (int) post('pass_percent'))));
        set_setting('quiz_repeat_months',       (string) max(0, (int) post('repeat_months')));
        set_setting('quiz_level_bonus',         (string) max(0, (int) post('level_bonus')));
        set_setting('quiz_default_marks',       (string) max(1, (int) post('default_marks')));
        foreach (quiz_chain() as $d) {
            set_setting('quiz_levels_' . $d, (string) max(0, (int) post('levels_' . $d)));
        }
        flash('Level settings saved.');
        redirect('admin/quiz-levels.php');

    } elseif ($do === 'marks') {
        $marks = max(1, (int) post('bulk_marks'));
        $diff  = post('bulk_diff');
        $cat   = (int) post('bulk_cat');

        $sql    = 'UPDATE quiz_questions SET marks = ? WHERE 1';
        $params = [$marks];
        if (in_array($diff, quiz_chain(), true)) {
            $sql     .= ' AND difficulty = ?';
            $params[] = $diff;
        }
        if ($cat > 0) {
            $sql     .= ' AND category_id = ?';
            $params[] = $cat;
        }
        $n = q($sql, $params)->rowCount();
        flash('Marks updated on ' . num($n) . ' question' . ($n === 1 ? '' : 's') . '.');
        redirect('admin/quiz-levels.php');

    } elseif ($do === 'reset_progress') {
        $uid = (int) post('user_id');
        q('DELETE FROM quiz_level_progress WHERE user_id = ?', [$uid]);
        flash('Level progress reset — that player starts at Easy level 1 again.');
        redirect('admin/quiz-levels.php');

    } elseif ($do === 'reset_seen') {
        $uid = (int) post('user_id');
        q('DELETE FROM quiz_seen WHERE user_id = ?', [$uid]);
        flash('Question history cleared — every question is fresh for that player again.');
        redirect('admin/quiz-levels.php');
    }
}

$bank = [];
foreach (fetch_all('SELECT difficulty, COUNT(*) n FROM quiz_questions
                     WHERE is_active = 1 GROUP BY difficulty') as $r) {
    $bank[$r['difficulty']] = (int) $r['n'];
}

$players = fetch_all(
    'SELECT u.id, u.username, u.name,
            SUM(p.difficulty = "easy" AND p.passed = 1) AS easy_done,
            SUM(p.difficulty = "mid"  AND p.passed = 1) AS mid_done,
            SUM(p.difficulty = "hard" AND p.passed = 1) AS hard_done,
            MAX(p.updated_at) AS last_seen,
            (SELECT COUNT(*) FROM quiz_seen s WHERE s.user_id = u.id) AS seen
       FROM quiz_level_progress p
       JOIN users u ON u.id = p.user_id
      GROUP BY u.id
      ORDER BY SUM(p.passed = 1) DESC, u.username
      LIMIT 100');

$cats = fetch_all('SELECT id, name FROM quiz_categories ORDER BY name');

require __DIR__ . '/includes/layout.php';
?>

<div class="adm-panel">
  <div class="adm-panel-head">
    <div><h2>How the levels work</h2>
      <p>Level size and the pass mark decide the whole map.</p></div>
    <a class="btn btn-ghost btn-sm" href="<?= admin_url('quiz-import.php') ?>">Import questions</a>
  </div>
  <div class="adm-panel-body">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="do" value="settings">

      <div class="adm-form-grid">
        <div>
          <div class="form-group">
            <label class="adm-switch">
              <input type="checkbox" name="level_mode" value="1"
                     <?= quiz_level_mode() ? 'checked' : '' ?>>
              <span class="track"></span>
              <span>Level mode &mdash; Easy, then Mid, then Hard, one level at a time</span>
            </label>
            <div class="form-hint">Off: players pick any difficulty and play free rounds,
              the way the quiz worked before.</div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="per_level">Questions in one level</label>
              <input class="form-control" type="number" id="per_level" name="per_level" min="1"
                     value="<?= quiz_per_level() ?>">
              <div class="form-hint">300 questions at 10 per level = 30 levels.</div>
            </div>
            <div class="form-group">
              <label class="form-label" for="pass_percent">Pass mark (%)</label>
              <input class="form-control" type="number" id="pass_percent" name="pass_percent"
                     min="1" max="100" value="<?= quiz_pass_percent() ?>">
              <div class="form-hint">Measured in marks, not in answers.</div>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="repeat_months">No-repeat window (months)</label>
              <input class="form-control" type="number" id="repeat_months" name="repeat_months"
                     min="0" max="120" value="<?= quiz_repeat_months() ?>">
              <div class="form-hint">A question a player has seen does not come back to
                <em>that</em> player for this long. 0 = questions may repeat freely.</div>
            </div>
            <div class="form-group">
              <label class="form-label" for="level_bonus">Coins for clearing a level</label>
              <input class="form-control" type="number" id="level_bonus" name="level_bonus"
                     min="0" value="<?= quiz_level_bonus() ?>">
              <div class="form-hint">Paid once, the first time the level is cleared.</div>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="default_marks">Default marks for a new question</label>
            <input class="form-control" type="number" id="default_marks" name="default_marks"
                   min="1" value="<?= (int) setting('quiz_default_marks', '10') ?>">
          </div>
        </div>

        <div>
          <h3 style="font-size:1rem;margin-bottom:10px">Levels per difficulty</h3>
          <p class="text-muted" style="font-size:.86rem">Leave a box at 0 to let the bank
             decide. Put a number in to pin it.</p>

          <?php foreach (quiz_chain() as $d):
            $n    = $bank[$d] ?? 0;
            $auto = (int) floor($n / quiz_per_level()); ?>
            <div class="form-group">
              <label class="form-label" for="levels_<?= $d ?>"><?= e(ucfirst($d)) ?></label>
              <input class="form-control" type="number" id="levels_<?= $d ?>"
                     name="levels_<?= $d ?>" min="0"
                     value="<?= (int) setting('quiz_levels_' . $d, '0') ?>">
              <div class="form-hint"><?= num($n) ?> live questions &rarr;
                <strong><?= num($auto) ?></strong> levels automatically.
                In use now: <strong><?= num(quiz_level_count($d)) ?></strong>.</div>
            </div>
          <?php endforeach; ?>

          <button class="btn btn-primary btn-block btn-lg" type="submit">Save level settings</button>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="adm-panel">
  <div class="adm-panel-head">
    <div><h2>Marks in bulk</h2>
      <p>Set the same marks on a whole difficulty or topic in one go.</p></div>
  </div>
  <div class="adm-panel-body">
    <form method="post" class="adm-form-grid">
      <?= csrf_field() ?><input type="hidden" name="do" value="marks">
      <div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="bulk_diff">Difficulty</label>
            <select class="form-select" id="bulk_diff" name="bulk_diff">
              <option value="">Every difficulty</option>
              <?php foreach (quiz_chain() as $d): ?>
                <option value="<?= $d ?>"><?= e(ucfirst($d)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="bulk_cat">Topic</label>
            <select class="form-select" id="bulk_cat" name="bulk_cat">
              <option value="0">Every topic</option>
              <?php foreach ($cats as $c): ?>
                <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div>
        <div class="form-group">
          <label class="form-label" for="bulk_marks">Marks per question</label>
          <input class="form-control" type="number" id="bulk_marks" name="bulk_marks"
                 min="1" value="<?= (int) setting('quiz_default_marks', '10') ?>">
        </div>
        <button class="btn btn-primary btn-block" type="submit"
                data-confirm="Overwrite the marks on every question that matches?">Apply marks</button>
      </div>
    </form>
  </div>
</div>

<div class="adm-panel">
  <div class="adm-panel-head">
    <div><h2>Player progress</h2><p>Where each player has reached, and what they have seen</p></div>
  </div>
  <div class="adm-panel-body flush">
    <div class="table-scroll">
      <table class="tbl" style="min-width:820px">
        <thead><tr><th>Player</th><th>Easy</th><th>Mid</th><th>Hard</th>
                   <th>Questions seen</th><th>Last played</th>
                   <th style="text-align:right">Reset</th></tr></thead>
        <tbody>
        <?php foreach ($players as $p): ?>
          <tr>
            <td><strong><?= e($p['username']) ?></strong>
              <span class="text-dim" style="display:block"><?= e($p['name']) ?></span></td>
            <td><?= (int) $p['easy_done'] ?> / <?= num(quiz_level_count('easy')) ?></td>
            <td><?= (int) $p['mid_done'] ?> / <?= num(quiz_level_count('mid')) ?></td>
            <td><?= (int) $p['hard_done'] ?> / <?= num(quiz_level_count('hard')) ?></td>
            <td class="text-muted"><?= num($p['seen']) ?></td>
            <td class="text-muted"><?= fdate($p['last_seen'], 'd M Y') ?></td>
            <td>
              <div class="adm-actions">
                <form method="post" style="display:inline">
                  <?= csrf_field() ?><input type="hidden" name="do" value="reset_progress">
                  <input type="hidden" name="user_id" value="<?= (int) $p['id'] ?>">
                  <button class="btn btn-ghost btn-xs" type="submit"
                    data-confirm="Send <?= e($p['username']) ?> back to Easy level 1?">Levels</button>
                </form>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?><input type="hidden" name="do" value="reset_seen">
                  <input type="hidden" name="user_id" value="<?= (int) $p['id'] ?>">
                  <button class="btn btn-ghost btn-xs" type="submit"
                    data-confirm="Clear the question history for <?= e($p['username']) ?>? Every question becomes fresh for them again.">History</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$players): ?>
          <tr><td colspan="7" class="text-muted center">Nobody has played a level yet.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
