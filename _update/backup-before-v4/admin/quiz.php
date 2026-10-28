<?php
/**
 * Admin — quiz question bank.
 * Add, edit, deactivate and delete questions across the three levels.
 */
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();
require_manage();

$admin_title = 'Quiz Questions';
$admin_sub   = 'The question bank behind the Easy / Mid / Hard contest';

if (!has_table('quiz_questions')) {
    flash('The quiz tables are not installed. Import config/upgrade-quiz-spin.sql first.', 'error');
    redirect('admin/index.php');
}

$levels = ['easy' => 'Easy', 'mid' => 'Mid', 'hard' => 'Hard'];

$editId = (int) get('edit');
$edit   = $editId ? fetch_one('SELECT * FROM quiz_questions WHERE id = ?', [$editId]) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $do = post('do');

    if ($do === 'save') {
        $id       = (int) post('id');
        $question = trim((string) ($_POST['question'] ?? ''));
        $a = post('option_a');
        $b = post('option_b');
        $c = post('option_c');
        $d = post('option_d');
        $correct = (int) post('correct');
        $level   = isset($levels[post('difficulty')]) ? post('difficulty') : 'easy';
        $cat     = (int) post('category_id');

        $errors = [];
        if (mb_strlen($question) < 8)      $errors[] = 'The question needs at least 8 characters.';
        if ($a === '' || $b === '')        $errors[] = 'Options A and B are both required.';
        if ($correct < 1 || $correct > 4)  $errors[] = 'Pick which option is the correct one.';
        if ($correct === 3 && $c === '')   $errors[] = 'Option C is marked correct but is empty.';
        if ($correct === 4 && $d === '')   $errors[] = 'Option D is marked correct but is empty.';

        if ($errors) {
            foreach ($errors as $x) flash($x, 'error');
            redirect('admin/quiz.php' . ($id ? '?edit=' . $id : ''));
        }

        $data = [
            'category_id' => $cat ?: null,
            'difficulty'  => $level,
            'question'    => $question,
            'option_a'    => $a,
            'option_b'    => $b,
            'option_c'    => $c !== '' ? $c : null,
            'option_d'    => $d !== '' ? $d : null,
            'correct'     => $correct,
            'explanation' => post('explanation') !== '' ? post('explanation') : null,
            'is_active'   => empty($_POST['is_active']) ? 0 : 1,
        ];

        $img = save_upload('image', 'quiz');
        if ($img) {
            $data['image'] = $img;
        }

        if ($id) {
            update_row('quiz_questions', $data, 'id = ?', [$id]);
            flash('Question updated.');
        } else {
            insert_row('quiz_questions', $data);
            flash('Question added.');
        }

    } elseif ($do === 'toggle') {
        q('UPDATE quiz_questions SET is_active = 1 - is_active WHERE id = ?', [(int) post('id')]);
        flash('Question visibility changed.');

    } elseif ($do === 'delete') {
        $id  = (int) post('id');
        $row = fetch_one('SELECT image FROM quiz_questions WHERE id = ?', [$id]);
        if ($row && $row['image']) {
            delete_upload($row['image'], 'quiz');
        }
        q('DELETE FROM quiz_questions WHERE id = ?', [$id]);
        flash('Question deleted.');
    }

    redirect('admin/quiz.php');
}

// ---- filters ----
$fLevel = isset($levels[get('level')]) ? get('level') : '';
$fCat   = (int) get('cat');
$search = get('q');

$where  = ['1=1'];
$params = [];
if ($fLevel) { $where[] = 'q.difficulty = ?';  $params[] = $fLevel; }
if ($fCat)   { $where[] = 'q.category_id = ?'; $params[] = $fCat; }
if ($search) { $where[] = 'q.question LIKE ?'; $params[] = '%' . $search . '%'; }
$w = implode(' AND ', $where);

$rows = fetch_all(
    "SELECT q.*, c.name AS cat_name
       FROM quiz_questions q
       LEFT JOIN quiz_categories c ON c.id = q.category_id
      WHERE $w
      ORDER BY q.id DESC
      LIMIT 300", $params);

$cats  = fetch_all('SELECT * FROM quiz_categories ORDER BY sort_order, name');
$stats = [];
foreach (fetch_all('SELECT difficulty, COUNT(*) n, SUM(is_active) live
                      FROM quiz_questions GROUP BY difficulty') as $r) {
    $stats[$r['difficulty']] = $r;
}

$admin_head = '';
require __DIR__ . '/includes/layout.php';
?>

<div class="adm-stats" style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:22px">
  <?php foreach ($levels as $k => $lbl):
      $s = $stats[$k] ?? ['n' => 0, 'live' => 0]; ?>
    <div class="adm-stat card">
      <div class="sc-num" style="font-size:1.7rem;font-weight:900"><?= (int) $s['live'] ?></div>
      <div class="sc-lbl" style="font-size:.72rem;letter-spacing:.12em;text-transform:uppercase;color:var(--dim)">
        <?= e($lbl) ?> live <span class="text-dim">/ <?= (int) $s['n'] ?> total</span>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="adm-form-grid">

  <div class="adm-panel">
    <div class="adm-panel-head">
      <div><h2>Question bank</h2><p><?= count($rows) ?> shown</p></div>
    </div>

    <div class="adm-panel-body">
      <form method="get" class="row" style="gap:10px;margin-bottom:4px">
        <select class="form-select" name="level" style="flex:0 0 130px">
          <option value="">All levels</option>
          <?php foreach ($levels as $k => $lbl): ?>
            <option value="<?= e($k) ?>" <?= $fLevel === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
        <select class="form-select" name="cat" style="flex:0 0 170px">
          <option value="">All topics</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= $fCat === (int) $c['id'] ? 'selected' : '' ?>>
              <?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <input class="form-control" name="q" value="<?= e($search) ?>"
               placeholder="Search question text" style="flex:1;min-width:160px">
        <button class="btn btn-ghost btn-sm" type="submit">Filter</button>
      </form>
    </div>

    <div class="adm-panel-body flush">
      <?php if (!$rows): ?>
        <div class="empty"><div class="em-ico">&#129504;</div>
          <h3>No questions match</h3>
          <p>Add one using the form, or clear the filters.</p></div>
      <?php else: ?>
        <div class="table-scroll">
          <table class="tbl" style="min-width:720px">
            <thead>
              <tr><th>Question</th><th>Level</th><th>Answer</th><th>Asked</th>
                  <th style="text-align:right">Actions</th></tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r):
                $opts = [1 => $r['option_a'], 2 => $r['option_b'], 3 => $r['option_c'], 4 => $r['option_d']];
                $acc  = $r['times_asked'] > 0
                        ? round($r['times_right'] / $r['times_asked'] * 100) : null; ?>
                <tr style="<?= $r['is_active'] ? '' : 'opacity:.5' ?>">
                  <td style="max-width:340px">
                    <strong><?= e(excerpt($r['question'], 90)) ?></strong>
                    <div class="text-dim"><?= e($r['cat_name'] ?? 'Uncategorised') ?></div>
                  </td>
                  <td>
                    <span class="badge badge-<?= $r['difficulty'] === 'hard' ? 'gold'
                        : ($r['difficulty'] === 'mid' ? 'info' : 'green') ?>">
                      <?= e($levels[$r['difficulty']]) ?></span>
                  </td>
                  <td class="text-green"><?= e(excerpt($opts[(int) $r['correct']] ?? '—', 34)) ?></td>
                  <td><?= (int) $r['times_asked'] ?>
                    <?php if ($acc !== null): ?>
                      <div class="text-dim"><?= $acc ?>% right</div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="adm-actions">
                      <a href="<?= admin_url('quiz.php?edit=' . (int) $r['id']) ?>"
                         class="btn btn-ghost btn-xs">Edit</a>
                      <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="do" value="toggle">
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <button class="btn btn-ghost btn-xs" type="submit">
                          <?= $r['is_active'] ? 'Hide' : 'Show' ?></button>
                      </form>
                      <form method="post" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="do" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <button class="btn btn-danger btn-xs" type="submit"
                                data-confirm="Delete this question permanently?">Delete</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="adm-panel">
    <div class="adm-panel-head">
      <h2><?= $edit ? 'Edit question' : 'New question' ?></h2>
    </div>
    <div class="adm-panel-body">
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save">
        <input type="hidden" name="id" value="<?= $edit ? (int) $edit['id'] : '' ?>">

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="difficulty">Level <span class="req">*</span></label>
            <select class="form-select" id="difficulty" name="difficulty">
              <?php foreach ($levels as $k => $lbl): ?>
                <option value="<?= e($k) ?>"
                  <?= ($edit['difficulty'] ?? 'easy') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="category_id">Topic</label>
            <select class="form-select" id="category_id" name="category_id">
              <option value="">Uncategorised</option>
              <?php foreach ($cats as $c): ?>
                <option value="<?= (int) $c['id'] ?>"
                  <?= (int) ($edit['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                  <?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="question">Question <span class="req">*</span></label>
          <textarea class="form-control" id="question" name="question" style="min-height:90px"
                    placeholder="Which planet is known as the Red Planet?"
                    required><?= e($edit['question'] ?? '') ?></textarea>
        </div>

        <?php foreach (['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D'] as $k => $L):
          $req = in_array($k, ['a', 'b'], true); ?>
          <div class="form-group">
            <label class="form-label" for="option_<?= $k ?>">
              Option <?= $L ?> <?= $req ? '<span class="req">*</span>'
                                        : '<span class="text-dim">(optional)</span>' ?>
            </label>
            <input class="form-control" id="option_<?= $k ?>" name="option_<?= $k ?>"
                   value="<?= e($edit['option_' . $k] ?? '') ?>" <?= $req ? 'required' : '' ?>>
          </div>
        <?php endforeach; ?>

        <div class="form-group">
          <label class="form-label" for="correct">Correct option <span class="req">*</span></label>
          <select class="form-select" id="correct" name="correct" required>
            <?php foreach ([1 => 'A', 2 => 'B', 3 => 'C', 4 => 'D'] as $v => $L): ?>
              <option value="<?= $v ?>"
                <?= (int) ($edit['correct'] ?? 1) === $v ? 'selected' : '' ?>>Option <?= $L ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label" for="explanation">Explanation
            <span class="text-dim">(optional)</span></label>
          <textarea class="form-control" id="explanation" name="explanation" style="min-height:70px"
                    placeholder="Shown after the player answers"><?= e($edit['explanation'] ?? '') ?></textarea>
        </div>

        <div class="form-group">
          <label class="form-label" for="image">Image <span class="text-dim">(optional)</span></label>
          <input class="form-control" type="file" id="image" name="image" accept="image/*">
          <?php if (!empty($edit['image'])): ?>
            <div class="form-hint">Currently: <?= e($edit['image']) ?> — upload a new file to replace it.</div>
          <?php endif; ?>
        </div>

        <div class="form-group">
          <label class="check">
            <input type="checkbox" name="is_active" value="1"
                   <?= (!$edit || $edit['is_active']) ? 'checked' : '' ?>>
            <span>Live — include this question in rounds</span>
          </label>
        </div>

        <button class="btn btn-primary btn-block" type="submit">
          <?= $edit ? 'Save Question' : 'Add Question' ?></button>
        <?php if ($edit): ?>
          <a href="<?= admin_url('quiz.php') ?>" class="btn btn-ghost btn-block mt-2">Cancel</a>
        <?php endif; ?>
      </form>
    </div>
  </div>

</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
