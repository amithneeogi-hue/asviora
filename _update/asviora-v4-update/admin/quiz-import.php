<?php
/**
 * Admin — bulk question upload.
 *
 * Takes the sheet you already keep the bank in (QSTN.xlsx style) and loads it
 * straight into quiz_questions. Both shapes of the "Correct Answer" column are
 * understood: a letter (A/B/C/D), a number (1-4) or the full option text.
 *
 * .xlsx is read with nothing but ZipArchive + SimpleXML — an .xlsx file is a
 * zip of XML parts — so there is no library to install on the host.
 * .csv and .tsv are read directly.
 */
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();
require_manage();

// ---------- CSV template ----------
if (get('template') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="question-template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Question ID', 'Category', 'Difficulty', 'Question',
                   'Option A', 'Option B', 'Option C', 'Option D',
                   'Correct Answer', 'Explanation', 'Time Limit (sec)', 'Marks']);
    fputcsv($out, ['GK000001', 'General Knowledge', 'Easy', 'What is the capital of India?',
                   'Mumbai', 'New Delhi', 'Kolkata', 'Chennai', 'B',
                   'New Delhi is the capital of India.', '20', '10']);
    fputcsv($out, ['SCI000001', 'Science', 'Medium', 'Water boils at?',
                   '100°C', '90°C', '80°C', '120°C', '100°C',
                   'Pure water boils at 100°C at sea level.', '20', '15']);
    fclose($out);
    exit;
}

$admin_title = 'Import Questions';
$admin_sub   = 'Load a whole question bank from one spreadsheet';

require_once ROOT_PATH . '/includes/sheet.php';

// ===================================================================
// Import
// ===================================================================

$report = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok() && post('do') === 'import') {

    $sheetIndex   = max(0, (int) post('sheet'));
    $defLevel     = norm_level(post('def_level'), 'easy');
    $defCat       = trim(post('def_category'));
    $defMarks     = max(1, (int) (post('def_marks') !== '' ? post('def_marks') : setting('quiz_default_marks', '10')));
    $defTime      = max(0, (int) post('def_time'));
    $updateSame   = post('update_same') === '1';
    $activate     = post('activate') !== '0';
    $forceLevel   = post('force_level') === '1';

    $file = $_FILES['sheet_file'] ?? null;

    if (!$file || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        flash('Choose a file to upload first.', 'error');
    } else {
        $ext  = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $rows = [];

        if ($ext === 'xlsx' || $ext === 'xlsm') {
            $rows = xlsx_rows($file['tmp_name'], $sheetIndex);
        } elseif (in_array($ext, ['csv', 'tsv', 'txt'], true)) {
            $rows = csv_rows($file['tmp_name']);
        } else {
            flash('Upload an .xlsx, .csv or .tsv file. Old .xls is not supported — '
                . 'open it in Excel and "Save as" .xlsx.', 'error');
        }

        if ($rows) {
            $head = array_shift($rows);
            $map  = map_headers($head);

            if (!isset($map['question'])) {
                flash('No "Question" column found in the header row. '
                    . 'Download the template to see the expected columns.', 'error');
            } else {
                // topic name -> id, created on the fly
                $catIds = [];
                foreach (fetch_all('SELECT id, name FROM quiz_categories') as $c) {
                    $catIds[strtolower($c['name'])] = (int) $c['id'];
                }

                $added = $updated = $skipped = 0;
                $errors = [];
                $line   = 1;

                foreach ($rows as $r) {
                    $line++;
                    $get = static function (string $k) use ($r, $map): string {
                        return isset($map[$k]) ? trim((string) ($r[$map[$k]] ?? '')) : '';
                    };

                    $question = $get('question');
                    $optA     = $get('a');
                    $optB     = $get('b');
                    if ($question === '' || $optA === '' || $optB === '') {
                        $skipped++;
                        if (count($errors) < 25 && $question !== '') {
                            $errors[] = 'Row ' . $line . ': needs at least two options.';
                        }
                        continue;
                    }

                    $optC    = $get('c');
                    $optD    = $get('d');
                    $correct = norm_correct($get('correct'), [$optA, $optB, $optC, $optD]);
                    if ($correct < 1 || $correct > 4) {
                        $skipped++;
                        if (count($errors) < 25) {
                            $errors[] = 'Row ' . $line . ': the correct answer "'
                                      . e($get('correct')) . '" does not match any option.';
                        }
                        continue;
                    }
                    if (($correct === 3 && $optC === '') || ($correct === 4 && $optD === '')) {
                        $skipped++;
                        if (count($errors) < 25) {
                            $errors[] = 'Row ' . $line . ': the correct option is empty.';
                        }
                        continue;
                    }

                    // topic
                    $catName = $get('category');
                    if ($catName === '') {
                        $catName = $defCat;
                    }
                    $catId = null;
                    if ($catName !== '') {
                        $k = strtolower($catName);
                        if (!isset($catIds[$k])) {
                            $catIds[$k] = insert_row('quiz_categories', [
                                'name' => mb_substr($catName, 0, 80),
                                'slug' => unique_slug('quiz_categories', slugify($catName)),
                                'icon' => '&#128218;',
                                'is_active' => 1,
                            ]);
                        }
                        $catId = $catIds[$k];
                    }

                    $level = $forceLevel ? $defLevel : norm_level($get('level'), $defLevel);
                    $marks = (int) $get('marks');
                    $marks = $marks > 0 ? $marks : $defMarks;
                    $time  = (int) $get('time');
                    $time  = $time > 0 ? $time : ($defTime ?: null);
                    $code  = $get('qcode');
                    $code  = $code !== '' ? mb_substr($code, 0, 40) : null;

                    $data = [
                        'category_id' => $catId,
                        'difficulty'  => $level,
                        'question'    => $question,
                        'option_a'    => mb_substr($optA, 0, 255),
                        'option_b'    => mb_substr($optB, 0, 255),
                        'option_c'    => $optC !== '' ? mb_substr($optC, 0, 255) : null,
                        'option_d'    => $optD !== '' ? mb_substr($optD, 0, 255) : null,
                        'correct'     => $correct,
                        'marks'       => $marks,
                        'time_limit'  => $time,
                        'explanation' => $get('explain') !== '' ? mb_substr($get('explain'), 0, 500) : null,
                        'is_active'   => $activate ? 1 : 0,
                    ];

                    // Already in the bank? Matched on the sheet's own Question ID
                    // when there is one, otherwise on the question text.
                    $existing = null;
                    if ($code !== null) {
                        $existing = fetch_one('SELECT id FROM quiz_questions WHERE qcode = ?', [$code]);
                    }
                    if (!$existing) {
                        $existing = fetch_one(
                            'SELECT id FROM quiz_questions WHERE question = ? LIMIT 1', [$question]);
                    }

                    if ($existing) {
                        if ($updateSame) {
                            update_row('quiz_questions', $data, 'id = ?', [(int) $existing['id']]);
                            if ($code !== null) {
                                q('UPDATE quiz_questions SET qcode = ? WHERE id = ?',
                                  [$code, (int) $existing['id']]);
                            }
                            $updated++;
                        } else {
                            $skipped++;
                        }
                        continue;
                    }

                    $data['qcode'] = $code;
                    insert_row('quiz_questions', $data);
                    $added++;
                }

                $report = ['added' => $added, 'updated' => $updated,
                           'skipped' => $skipped, 'errors' => $errors,
                           'rows' => count($rows)];
                flash($added . ' added, ' . $updated . ' updated, ' . $skipped . ' skipped.');
            }
        } elseif ($file && ($file['error'] ?? 1) === UPLOAD_ERR_OK) {
            flash('Nothing could be read out of that file.', 'error');
        }
    }
}

$bank = [];
foreach (fetch_all('SELECT difficulty, COUNT(*) n, COALESCE(SUM(marks),0) m
                      FROM quiz_questions WHERE is_active = 1 GROUP BY difficulty') as $r) {
    $bank[$r['difficulty']] = $r;
}

require __DIR__ . '/includes/layout.php';
?>

<?php if ($report): ?>
  <div class="adm-panel">
    <div class="adm-panel-head"><h2>Import report</h2></div>
    <div class="adm-panel-body">
      <div class="row" style="gap:26px;flex-wrap:wrap">
        <div><div class="text-dim">Rows read</div><div style="font-weight:800"><?= num($report['rows']) ?></div></div>
        <div><div class="text-dim">Added</div>
          <div style="font-weight:800" class="text-green"><?= num($report['added']) ?></div></div>
        <div><div class="text-dim">Updated</div>
          <div style="font-weight:800"><?= num($report['updated']) ?></div></div>
        <div><div class="text-dim">Skipped</div>
          <div style="font-weight:800" class="text-muted"><?= num($report['skipped']) ?></div></div>
      </div>
      <?php if ($report['errors']): ?>
        <ul class="mt-3 text-muted" style="font-size:.88rem">
          <?php foreach ($report['errors'] as $x): ?><li><?= $x ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <a class="btn btn-ghost btn-sm mt-2" href="<?= admin_url('quiz.php') ?>">Open the question list</a>
    </div>
  </div>
<?php endif; ?>

<div class="adm-panel">
  <div class="adm-panel-head">
    <div>
      <h2>Upload a question sheet</h2>
      <p>.xlsx, .csv or .tsv &mdash; the columns of your QSTN.xlsx are understood as they are.</p>
    </div>
    <a class="btn btn-ghost btn-sm" href="<?= admin_url('quiz-import.php?template=1') ?>">
      Download CSV template</a>
  </div>
  <div class="adm-panel-body">
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="import">

      <div class="adm-form-grid">
        <div>
          <div class="form-group">
            <label class="form-label" for="sheet_file">Spreadsheet <span class="req">*</span></label>
            <input class="form-control" type="file" id="sheet_file" name="sheet_file"
                   accept=".xlsx,.xlsm,.csv,.tsv,.txt" required>
            <div class="form-hint">
              Header row first. Recognised columns: Question ID, Category, Difficulty, Question,
              Option A&ndash;D, Correct Answer, Explanation, Time Limit, Marks.
              The answer may be a letter (B), a number (2) or the option text itself.
              Server upload limit: <?= e(ini_get('upload_max_filesize')) ?>.
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="sheet">Sheet number (.xlsx only)</label>
              <input class="form-control" type="number" id="sheet" name="sheet" min="0" value="0">
              <div class="form-hint">0 = the first sheet, 1 = the second, and so on.</div>
            </div>
            <div class="form-group">
              <label class="form-label" for="def_category">Topic when the sheet has none</label>
              <input class="form-control" id="def_category" name="def_category"
                     placeholder="General Knowledge" list="qcatList">
              <datalist id="qcatList">
                <?php foreach (fetch_all('SELECT name FROM quiz_categories ORDER BY name') as $c): ?>
                  <option value="<?= e($c['name']) ?>"></option>
                <?php endforeach; ?>
              </datalist>
            </div>
          </div>
        </div>

        <div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="def_level">Difficulty when the sheet has none</label>
              <select class="form-select" id="def_level" name="def_level">
                <option value="easy">Easy</option>
                <option value="mid">Mid / Medium</option>
                <option value="hard">Hard</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" for="def_marks">Marks when the sheet has none</label>
              <input class="form-control" type="number" id="def_marks" name="def_marks" min="1"
                     value="<?= (int) setting('quiz_default_marks', '10') ?>">
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="def_time">Seconds per question (0 = use the level default)</label>
            <input class="form-control" type="number" id="def_time" name="def_time" min="0" value="0">
          </div>

          <div class="form-group">
            <label class="adm-switch"><input type="checkbox" name="force_level" value="1">
              <span class="track"></span><span>Ignore the sheet's difficulty column and use the one above</span></label>
          </div>
          <div class="form-group">
            <label class="adm-switch"><input type="checkbox" name="update_same" value="1" checked>
              <span class="track"></span><span>Update a question that is already in the bank</span></label>
          </div>
          <div class="form-group">
            <label class="adm-switch"><input type="checkbox" name="activate" value="1" checked>
              <span class="track"></span><span>Make the imported questions live straight away</span></label>
          </div>

          <button class="btn btn-primary btn-block btn-lg" type="submit">Import questions</button>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="adm-panel">
  <div class="adm-panel-head"><h2>The bank right now</h2></div>
  <div class="adm-panel-body flush">
    <div class="table-scroll">
      <table class="tbl">
        <thead><tr><th>Difficulty</th><th>Live questions</th><th>Total marks</th>
                   <th>Levels this makes</th></tr></thead>
        <tbody>
        <?php foreach (quiz_chain() as $d):
          $n = (int) ($bank[$d]['n'] ?? 0); ?>
          <tr>
            <td><strong><?= e(ucfirst($d)) ?></strong></td>
            <td><?= num($n) ?></td>
            <td class="text-muted"><?= num((int) ($bank[$d]['m'] ?? 0)) ?></td>
            <td class="text-green"><?= num(quiz_level_count($d)) ?>
              <span class="text-dim">at <?= quiz_per_level() ?> per level</span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
