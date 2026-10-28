<?php
/**
 * Admin — bulk question upload.
 *
 * Upload a .xlsx or .csv and the whole bank goes in at once. The sheet's
 * own column headings are matched by name, so the columns can be in any
 * order and the labels can vary a little; the layout of QSTN.xlsx works
 * as-is:
 *
 *   Question ID | Category | Difficulty | Question | Option A..D |
 *   Correct Answer | Explanation | Time Limit (sec) | Marks
 *
 * The upload is a two-step: everything is checked and shown back to you
 * first, and nothing is written until you confirm. Rows that would create
 * a duplicate are pointed out rather than silently dropped.
 */
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();
require_manage();

require_once ROOT_PATH . '/includes/sheet.php';

$admin_title = 'Bulk Upload';
$admin_sub   = 'Import a whole question bank from Excel or CSV';

if (!has_table('quiz_questions')) {
    flash('The quiz tables are not installed yet.', 'error');
    redirect('admin/index.php');
}

// -------------------------------------------------------------------
// Column aliases — how a heading in the sheet maps to a database field
// -------------------------------------------------------------------
const IMPORT_ALIASES = [
    'ext_id'      => ['question id', 'questionid', 'qid', 'id', 'ref', 'code'],
    'category'    => ['category', 'topic', 'subject', 'cat'],
    'difficulty'  => ['difficulty', 'level', 'diff'],
    'question'    => ['question', 'question text', 'q', 'title'],
    'option_a'    => ['option a', 'optiona', 'a', 'opt a', 'option1', 'option 1'],
    'option_b'    => ['option b', 'optionb', 'b', 'opt b', 'option2', 'option 2'],
    'option_c'    => ['option c', 'optionc', 'c', 'opt c', 'option3', 'option 3'],
    'option_d'    => ['option d', 'optiond', 'd', 'opt d', 'option4', 'option 4'],
    'correct'     => ['correct answer', 'correct', 'answer', 'ans', 'right answer', 'key'],
    'explanation' => ['explanation', 'reason', 'note', 'solution', 'why'],
    'time_limit'  => ['time limit sec', 'time limit', 'time', 'seconds', 'timer', 'time limit (sec)'],
    'marks'       => ['marks', 'mark', 'points', 'score', 'weight'],
];

/** Fold the many ways people write a difficulty into our three. */
function import_difficulty(string $raw, string $fallback = 'easy'): string
{
    $d = strtolower(trim($raw));
    $d = preg_replace('/[^a-z]/', '', $d);

    if ($d === '') {
        return $fallback;
    }
    if (in_array($d, ['easy', 'beginner', 'basic', 'simple', 'starter', 'l1', 'low'], true)) {
        return 'easy';
    }
    if (in_array($d, ['mid', 'medium', 'moderate', 'intermediate', 'average', 'l2', 'normal'], true)) {
        return 'mid';
    }
    if (in_array($d, ['hard', 'difficult', 'tough', 'advanced', 'expert', 'l3', 'high'], true)) {
        return 'hard';
    }
    return $fallback;
}

/**
 * Which option is the right one? Accepts A/B/C/D, 1-4, or the answer
 * spelled out in full — some sheets put the text rather than the letter.
 * Returns 1-4, or 0 when it cannot be worked out.
 */
function import_correct(string $raw, array $options): int
{
    $v = trim($raw);
    if ($v === '') {
        return 0;
    }

    $letter = strtoupper(preg_replace('/[^a-dA-D]/', '', $v));
    if (strlen($letter) === 1 && $v !== '' && strlen(preg_replace('/\s/', '', $v)) <= 2) {
        return ord($letter) - 64;                       // A=1 .. D=4
    }

    if (ctype_digit($v) && (int) $v >= 1 && (int) $v <= 4) {
        return (int) $v;
    }

    // fall back to matching the answer text against the options
    foreach ($options as $i => $opt) {
        if ($opt !== '' && strcasecmp(trim($opt), $v) === 0) {
            return $i + 1;
        }
    }

    if (strlen($letter) === 1) {
        return ord($letter) - 64;
    }
    return 0;
}

/** Stable identity for a question, used to spot repeats. */
function import_fingerprint(string $question, string $a, string $b): string
{
    $norm = static fn($s) => preg_replace('/\s+/', ' ',
                              strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $s))));
    return md5($norm($question) . '|' . $norm($a) . '|' . $norm($b));
}

/** Turn a category name into an id, creating the topic if it is new. */
function import_category(string $name, array &$cache, bool $create): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    $key = strtolower($name);

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $id = fetch_col('SELECT id FROM quiz_categories WHERE LOWER(name) = ?', [$key], null);
    if ($id === null && $create) {
        $id = insert_row('quiz_categories', [
            'name'      => mb_substr($name, 0, 80),
            'slug'      => unique_slug('quiz_categories', slugify($name)),
            'is_active' => 1,
        ]);
    }

    return $cache[$key] = $id !== null ? (int) $id : null;
}

/**
 * Read the file and turn it into checked rows.
 * Returns ['rows'=>[], 'errors'=>[], 'header'=>[], 'map'=>[], 'error'=>''].
 */
function import_parse(string $path, string $filename, array $opt): array
{
    $read = sheet_read($path, $filename);
    if ($read['error'] !== '') {
        return ['rows' => [], 'errors' => [], 'error' => $read['error']];
    }

    $raw = $read['rows'];
    if (count($raw) < 2) {
        return ['rows' => [], 'errors' => [],
                'error' => 'The sheet needs a heading row and at least one question.'];
    }

    $header = array_shift($raw);
    $map    = sheet_map_headers($header, IMPORT_ALIASES);

    foreach (['question', 'option_a', 'option_b', 'correct'] as $need) {
        if (!isset($map[$need])) {
            return ['rows' => [], 'errors' => [], 'header' => $header, 'map' => $map,
                    'error' => 'The sheet has no "' . ucwords(str_replace('_', ' ', $need))
                             . '" column. Download the template to see the expected headings.'];
        }
    }

    $cell = static function (array $r, array $map, string $f) {
        return isset($map[$f]) ? trim((string) ($r[$map[$f]] ?? '')) : '';
    };

    $catCache = [];
    $seen     = [];
    $rows     = [];
    $errors   = [];
    $line     = 1;

    foreach ($raw as $r) {
        $line++;

        // skip blank lines
        if (!array_filter($r, static fn($c) => trim((string) $c) !== '')) {
            continue;
        }

        $question = $cell($r, $map, 'question');
        $a        = $cell($r, $map, 'option_a');
        $b        = $cell($r, $map, 'option_b');
        $c        = $cell($r, $map, 'option_c');
        $d        = $cell($r, $map, 'option_d');
        $answer   = $cell($r, $map, 'correct');

        if ($question === '') {
            $errors[] = ['line' => $line, 'why' => 'No question text.'];
            continue;
        }
        if ($a === '' || $b === '') {
            $errors[] = ['line' => $line, 'why' => 'At least two options are needed.',
                         'q' => $question];
            continue;
        }

        $correct = import_correct($answer, [$a, $b, $c, $d]);
        if ($correct < 1 || $correct > 4) {
            $errors[] = ['line' => $line, 'q' => $question,
                         'why' => 'Correct answer "' . $answer . '" is not A, B, C, D or 1-4.'];
            continue;
        }
        if (($correct === 3 && $c === '') || ($correct === 4 && $d === '')) {
            $errors[] = ['line' => $line, 'q' => $question,
                         'why' => 'The correct answer points at an option that is empty.'];
            continue;
        }

        $diff = import_difficulty($cell($r, $map, 'difficulty'), $opt['difficulty']);
        $catN = $cell($r, $map, 'category');
        $cat  = $catN !== ''
              ? import_category($catN, $catCache, $opt['create_cats'])
              : ($opt['category'] ?: null);

        $fp = import_fingerprint($question, $a, $b);

        $dupe = '';
        if (isset($seen[$fp])) {
            $dupe = 'Repeated inside this file (line ' . $seen[$fp] . ')';
        } else {
            $seen[$fp] = $line;
            $exists = fetch_col('SELECT id FROM quiz_questions WHERE fingerprint = ?', [$fp], null);
            if ($exists === null) {
                $exists = fetch_col(
                    'SELECT id FROM quiz_questions WHERE question = ? LIMIT 1', [$question], null);
            }
            if ($exists !== null) {
                $dupe = 'Already in the bank (#' . (int) $exists . ')';
            }
        }

        $ext = $cell($r, $map, 'ext_id');
        if ($ext !== '' && $dupe === '') {
            $byExt = fetch_col('SELECT id FROM quiz_questions WHERE ext_id = ?', [$ext], null);
            if ($byExt !== null) {
                $dupe = 'Question ID ' . $ext . ' already exists (#' . (int) $byExt . ')';
            }
        }

        $marks = (int) ($cell($r, $map, 'marks') ?: 1);
        $time  = (int) $cell($r, $map, 'time_limit');

        $rows[] = [
            'line'        => $line,
            'ext_id'      => $ext !== '' ? mb_substr($ext, 0, 40) : null,
            'category_id' => $cat,
            'category'    => $catN,
            'difficulty'  => $diff,
            'question'    => $question,
            'option_a'    => mb_substr($a, 0, 255),
            'option_b'    => mb_substr($b, 0, 255),
            'option_c'    => $c !== '' ? mb_substr($c, 0, 255) : null,
            'option_d'    => $d !== '' ? mb_substr($d, 0, 255) : null,
            'correct'     => $correct,
            'explanation' => mb_substr($cell($r, $map, 'explanation'), 0, 500) ?: null,
            'marks'       => $marks > 0 ? $marks : 1,
            'time_limit'  => $time > 0 ? $time : null,
            'fingerprint' => $fp,
            'dupe'        => $dupe,
        ];
    }

    return ['rows' => $rows, 'errors' => $errors, 'header' => $header,
            'map' => $map, 'error' => ''];
}

// -------------------------------------------------------------------
// Template download
// -------------------------------------------------------------------
if (get('template') !== '') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="question-template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                        // BOM so Excel keeps UTF-8
    fputcsv($out, ['Question ID', 'Category', 'Difficulty', 'Question',
                   'Option A', 'Option B', 'Option C', 'Option D',
                   'Correct Answer', 'Explanation', 'Time Limit (sec)', 'Marks']);
    fputcsv($out, ['GK000001', 'General Knowledge', 'Easy', 'What is the capital of India?',
                   'Mumbai', 'New Delhi', 'Kolkata', 'Chennai', 'B',
                   'New Delhi is the capital of India.', 20, 10]);
    fputcsv($out, ['SCI000001', 'Science', 'Medium', 'Water boils at?',
                   '100°C', '90°C', '80°C', '120°C', 'A',
                   'Pure water boils at 100°C at sea level.', 20, 10]);
    fputcsv($out, ['SPT000001', 'Sports', 'Hard', 'How many players field in a football team?',
                   '10', '11', '12', '9', 'B', 'A football team fields 11 players.', 15, 20]);
    fclose($out);
    exit;
}

// -------------------------------------------------------------------
// Upload / confirm
// -------------------------------------------------------------------
$stage   = 'form';
$parsed  = null;
$options = [
    'category'    => (int) ($_POST['default_category'] ?? 0),
    'difficulty'  => import_difficulty((string) ($_POST['default_difficulty'] ?? 'easy')),
    'create_cats' => !empty($_POST['create_cats']),
    'activate'    => !isset($_POST['do']) || !empty($_POST['activate']),
    'skip_dupes'  => !isset($_POST['do']) || !empty($_POST['skip_dupes']),
];

$storeDir = ROOT_PATH . '/storage/imports';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $do = post('do');

    // ---- step 1: upload and check --------------------------------------
    if ($do === 'upload') {
        if (empty($_FILES['sheet']['tmp_name']) || $_FILES['sheet']['error'] !== UPLOAD_ERR_OK) {
            flash('No file arrived. Check the file size limit in php.ini if it is a big sheet.', 'error');
            redirect('admin/quiz-import.php');
        }

        if (!is_dir($storeDir)) {
            @mkdir($storeDir, 0775, true);
        }

        $orig = (string) $_FILES['sheet']['name'];
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xlsm', 'csv', 'tsv', 'txt'], true)) {
            flash('Upload a .xlsx, .csv or .tsv file.', 'error');
            redirect('admin/quiz-import.php');
        }

        $stored = $storeDir . '/' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($_FILES['sheet']['tmp_name'], $stored)) {
            flash('The file could not be saved. Check that storage/imports is writable.', 'error');
            redirect('admin/quiz-import.php');
        }

        $_SESSION['import_file'] = $stored;
        $_SESSION['import_name'] = $orig;

        $parsed = import_parse($stored, $orig, $options);
        if ($parsed['error'] !== '') {
            flash($parsed['error'], 'error');
            @unlink($stored);
            unset($_SESSION['import_file']);
            redirect('admin/quiz-import.php');
        }
        $stage = 'preview';

    // ---- step 2: write it in -------------------------------------------
    } elseif ($do === 'commit') {
        $stored = (string) ($_SESSION['import_file'] ?? '');
        $orig   = (string) ($_SESSION['import_name'] ?? 'upload');

        if ($stored === '' || !is_readable($stored)) {
            flash('The uploaded file has expired. Please upload it again.', 'error');
            redirect('admin/quiz-import.php');
        }

        $parsed = import_parse($stored, $orig, $options);
        if ($parsed['error'] !== '') {
            flash($parsed['error'], 'error');
            redirect('admin/quiz-import.php');
        }

        $batch = insert_row('quiz_imports', [
            'admin_id'   => (int) $A['id'],
            'filename'   => mb_substr($orig, 0, 190),
            'rows_total' => count($parsed['rows']) + count($parsed['errors']),
        ]);

        $ok = $dupe = $failed = 0;
        $log = [];

        foreach ($parsed['rows'] as $r) {
            if ($r['dupe'] !== '' && $options['skip_dupes']) {
                $dupe++;
                $log[] = ['line' => $r['line'], 'status' => 'skipped', 'why' => $r['dupe']];
                continue;
            }

            try {
                insert_row('quiz_questions', [
                    'ext_id'       => $r['ext_id'],
                    'category_id'  => $r['category_id'],
                    'difficulty'   => $r['difficulty'],
                    'question'     => $r['question'],
                    'option_a'     => $r['option_a'],
                    'option_b'     => $r['option_b'],
                    'option_c'     => $r['option_c'],
                    'option_d'     => $r['option_d'],
                    'correct'      => $r['correct'],
                    'marks'        => $r['marks'],
                    'time_limit'   => $r['time_limit'],
                    'explanation'  => $r['explanation'],
                    'is_active'    => $options['activate'] ? 1 : 0,
                    'import_batch' => $batch,
                    'fingerprint'  => $r['fingerprint'],
                ]);
                $ok++;
            } catch (Throwable $e) {
                $failed++;
                $log[] = ['line' => $r['line'], 'status' => 'failed',
                          'why' => DEBUG_MODE ? $e->getMessage() : 'Database rejected the row.'];
            }
        }

        foreach ($parsed['errors'] as $er) {
            $failed++;
            $log[] = ['line' => $er['line'], 'status' => 'failed', 'why' => $er['why']];
        }

        update_row('quiz_imports', [
            'rows_ok'     => $ok,
            'rows_dupe'   => $dupe,
            'rows_failed' => $failed,
            'report'      => json_encode(array_slice($log, 0, 500)),
        ], 'id = ?', [$batch]);

        @unlink($stored);
        unset($_SESSION['import_file'], $_SESSION['import_name']);

        flash($ok . ' questions imported' .
              ($dupe   ? ', ' . $dupe . ' duplicates skipped' : '') .
              ($failed ? ', ' . $failed . ' rows rejected' : '') . '.',
              $ok ? 'success' : 'error');
        redirect('admin/quiz-import.php');

    // ---- undo a batch ---------------------------------------------------
    } elseif ($do === 'undo') {
        $batch = (int) post('batch');
        $n = q('DELETE FROM quiz_questions WHERE import_batch = ?', [$batch])->rowCount();
        q('DELETE FROM quiz_imports WHERE id = ?', [$batch]);
        flash($n . ' questions from that upload were removed.', 'info');
        redirect('admin/quiz-import.php');
    }
}

$cats    = fetch_all('SELECT id, name FROM quiz_categories ORDER BY name');
$imports = has_table('quiz_imports')
    ? fetch_all('SELECT i.*, a.name AS admin_name FROM quiz_imports i
                   LEFT JOIN admins a ON a.id = i.admin_id
                  ORDER BY i.id DESC LIMIT 12')
    : [];

$bank = fetch_all(
    'SELECT difficulty, COUNT(*) AS n FROM quiz_questions WHERE is_active = 1 GROUP BY difficulty');
$bankBy = [];
foreach ($bank as $b) {
    $bankBy[$b['difficulty']] = (int) $b['n'];
}

$admin_actions = '<a class="btn btn-ghost btn-sm" href="' . admin_url('quiz-import.php?template=csv')
               . '">&#11015; Download template</a>';

require __DIR__ . '/includes/layout.php';
?>

<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:22px">
  <?php foreach (['easy' => 'Easy', 'mid' => 'Mid', 'hard' => 'Hard'] as $k => $lbl):
        $n = $bankBy[$k] ?? 0;
        $per = max(1, (int) setting('quiz_questions_per_level', '10')); ?>
    <div class="card stat-card">
      <div class="sc-num"><?= num($n) ?></div>
      <div class="sc-lbl"><?= $lbl ?> questions &middot; <?= (int) ceil($n / $per) ?> levels</div>
    </div>
  <?php endforeach; ?>
  <div class="card stat-card">
    <div class="sc-num"><?= num(array_sum($bankBy)) ?></div>
    <div class="sc-lbl">Live in the bank</div>
  </div>
</div>

<?php if ($stage === 'preview' && $parsed):
      $good  = array_filter($parsed['rows'], static fn($r) => $r['dupe'] === '');
      $dupes = array_filter($parsed['rows'], static fn($r) => $r['dupe'] !== ''); ?>

  <!-- ======================= PREVIEW ======================= -->
  <div class="adm-panel">
    <div class="adm-panel-head">
      <div>
        <h2>Check before importing</h2>
        <p><?= count($good) ?> ready &middot; <?= count($dupes) ?> look like duplicates
           &middot; <?= count($parsed['errors']) ?> rejected. Nothing has been saved yet.</p>
      </div>
    </div>
    <div class="adm-panel-body">

      <?php if ($parsed['errors']): ?>
        <div class="alert alert-error" style="display:block">
          <strong>&#9888; <?= count($parsed['errors']) ?> rows cannot be imported</strong>
          <ul style="margin:8px 0 0 18px">
            <?php foreach (array_slice($parsed['errors'], 0, 12) as $er): ?>
              <li>Line <?= (int) $er['line'] ?> — <?= e($er['why']) ?>
                <?php if (!empty($er['q'])): ?>
                  <span class="text-dim">(<?= e(excerpt($er['q'], 60)) ?>)</span>
                <?php endif; ?></li>
            <?php endforeach; ?>
            <?php if (count($parsed['errors']) > 12): ?>
              <li class="text-dim">…and <?= count($parsed['errors']) - 12 ?> more</li>
            <?php endif; ?>
          </ul>
        </div>
      <?php endif; ?>

      <div class="table-scroll">
        <table class="tbl" style="min-width:900px">
          <thead>
            <tr><th>#</th><th>Question</th><th>Topic</th><th>Level</th>
                <th>Answer</th><th>Marks</th><th>Status</th></tr>
          </thead>
          <tbody>
          <?php foreach (array_slice($parsed['rows'], 0, 60) as $r): ?>
            <tr>
              <td class="text-dim"><?= (int) $r['line'] ?></td>
              <td>
                <strong><?= e(excerpt($r['question'], 90)) ?></strong>
                <div class="text-dim"><?= e(excerpt($r['option_a'] . ' / ' . $r['option_b'], 60)) ?></div>
              </td>
              <td class="text-dim"><?= e($r['category'] ?: '—') ?></td>
              <td><span class="badge badge-info"><?= e($r['difficulty']) ?></span></td>
              <td><strong><?= chr(64 + (int) $r['correct']) ?></strong></td>
              <td class="text-dim"><?= (int) $r['marks'] ?></td>
              <td>
                <?php if ($r['dupe']): ?>
                  <span class="badge badge-muted" title="<?= e($r['dupe']) ?>">duplicate</span>
                <?php else: ?>
                  <span class="badge badge-green">ready</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (count($parsed['rows']) > 60): ?>
        <p class="form-hint mt-2">Showing the first 60 of <?= count($parsed['rows']) ?> rows.</p>
      <?php endif; ?>

      <form method="post" class="mt-3">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="commit">
        <input type="hidden" name="default_category" value="<?= (int) $options['category'] ?>">
        <input type="hidden" name="default_difficulty" value="<?= e($options['difficulty']) ?>">
        <input type="hidden" name="create_cats" value="<?= $options['create_cats'] ? '1' : '' ?>">

        <label class="check">
          <input type="checkbox" name="skip_dupes" value="1" <?= $options['skip_dupes'] ? 'checked' : '' ?>>
          <span>Skip the <?= count($dupes) ?> duplicates (uncheck to import them anyway)</span>
        </label>
        <label class="check">
          <input type="checkbox" name="activate" value="1" <?= $options['activate'] ? 'checked' : '' ?>>
          <span>Make these questions live straight away</span>
        </label>

        <div class="row mt-3" style="gap:10px">
          <button class="btn btn-primary" type="submit">
            Import <?= count($good) ?> questions
          </button>
          <a class="btn btn-ghost" href="<?= admin_url('quiz-import.php') ?>">Cancel</a>
        </div>
      </form>
    </div>
  </div>

<?php else: ?>

  <!-- ======================= UPLOAD FORM ======================= -->
  <div class="adm-form-grid">
    <div class="adm-panel">
      <div class="adm-panel-head">
        <div><h2>Upload a sheet</h2>
          <p>.xlsx or .csv. Columns are matched by their heading, so the order does not matter.</p></div>
      </div>
      <div class="adm-panel-body">
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="upload">

          <div class="form-group">
            <label class="form-label">Question file</label>
            <input class="form-control" type="file" name="sheet"
                   accept=".xlsx,.xlsm,.csv,.tsv,.txt" required>
            <p class="form-hint">Same layout as QSTN.xlsx. Download the template if you need one.</p>
          </div>

          <div class="form-group">
            <label class="form-label">Topic for rows with no category</label>
            <select class="form-control" name="default_category">
              <option value="0">— leave blank —</option>
              <?php foreach ($cats as $c): ?>
                <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">Level for rows with no difficulty</label>
            <select class="form-control" name="default_difficulty">
              <option value="easy">Easy</option>
              <option value="mid">Mid / Medium</option>
              <option value="hard">Hard</option>
            </select>
          </div>

          <label class="check">
            <input type="checkbox" name="create_cats" value="1" checked>
            <span>Create topics that are in the sheet but not in the site yet</span>
          </label>

          <div class="row mt-3">
            <button class="btn btn-primary" type="submit">Check the file</button>
          </div>
        </form>
      </div>
    </div>

    <div class="adm-panel">
      <div class="adm-panel-head"><h2>How the columns are read</h2></div>
      <div class="adm-panel-body">
        <table class="tbl">
          <thead><tr><th>Heading in your sheet</th><th>Goes to</th></tr></thead>
          <tbody>
            <tr><td>Question ID</td><td class="text-dim">Kept as a reference; blocks re-importing the same row twice</td></tr>
            <tr><td>Category / Topic</td><td class="text-dim">Quiz topic, created if new</td></tr>
            <tr><td>Difficulty / Level</td><td class="text-dim">Easy · Medium/Mid · Hard <em>(also accepts beginner, moderate, advanced…)</em></td></tr>
            <tr><td>Question</td><td class="text-dim">Required</td></tr>
            <tr><td>Option A–D</td><td class="text-dim">A and B required, C and D optional</td></tr>
            <tr><td>Correct Answer</td><td class="text-dim">A/B/C/D, 1–4, or the answer written out</td></tr>
            <tr><td>Explanation</td><td class="text-dim">Shown after the player answers</td></tr>
            <tr><td>Time Limit (sec)</td><td class="text-dim">Per-question override of the level clock</td></tr>
            <tr><td>Marks</td><td class="text-dim">What the question is worth</td></tr>
          </tbody>
        </table>
        <p class="form-hint mt-2">
          A row is treated as a duplicate when the question and its first two options
          match one already in the bank, or when the Question ID is already used.
        </p>
      </div>
    </div>
  </div>

  <?php if ($imports): ?>
    <div class="adm-panel mt-4">
      <div class="adm-panel-head"><h2>Recent uploads</h2></div>
      <div class="adm-panel-body flush">
        <div class="table-scroll">
          <table class="tbl" style="min-width:760px">
            <thead><tr><th>File</th><th>By</th><th>When</th><th>Imported</th>
                       <th>Skipped</th><th>Rejected</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($imports as $im): ?>
              <tr>
                <td><strong><?= e($im['filename']) ?></strong></td>
                <td class="text-dim"><?= e($im['admin_name'] ?: '—') ?></td>
                <td class="text-dim"><?= fdate($im['created_at'], 'd M Y, H:i') ?></td>
                <td class="text-green"><?= num((int) $im['rows_ok']) ?></td>
                <td class="text-dim"><?= num((int) $im['rows_dupe']) ?></td>
                <td class="<?= $im['rows_failed'] ? 'text-gold' : 'text-dim' ?>">
                  <?= num((int) $im['rows_failed']) ?></td>
                <td>
                  <?php if ((int) $im['rows_ok'] > 0): ?>
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="do" value="undo">
                      <input type="hidden" name="batch" value="<?= (int) $im['id'] ?>">
                      <button class="btn btn-ghost btn-sm" type="submit"
                              data-confirm="Delete the <?= (int) $im['rows_ok'] ?> questions this upload added?">
                        Undo</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
