<?php
/**
 * Admin — Government competitive exam.
 *
 * Controls the navigation entry (label, on/off, the "soon" flag and the
 * launch date), the two blocks of copy on the landing page, and the
 * interest list people have joined from it.
 */
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();
require_manage();

$admin_title = 'Govt Exam';
$admin_sub   = 'The coming-soon section and its interest list';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $do = post('do');

    if ($do === 'save') {
        set_setting('govt_exam_enabled', empty($_POST['enabled']) ? '0' : '1');
        set_setting('govt_exam_status',  post('status') === 'live' ? 'live' : 'soon');
        set_setting('govt_exam_label',
                    mb_substr(post('label'), 0, 30) ?: 'Govt Exam');

        $d = post('launch');
        set_setting('govt_exam_launch',
                    preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '');

        set_setting('govt_exam_tagline', mb_substr(post('tagline'), 0, 190));
        set_setting('govt_exam_blurb',   mb_substr(post('blurb'), 0, 600));

        // keep the module row in step, so Admin -> Modules agrees with this page
        if (has_table('modules')) {
            module_set('govt_exam', empty($_POST['enabled']) ? false : true, (int) $A['id']);
            module_set_nav('govt_exam', !empty($_POST['in_nav']), (int) $A['id']);
        }
        flash('Govt Exam settings saved.');

    } elseif ($do === 'delete' && has_table('exam_notify')) {
        q('DELETE FROM exam_notify WHERE id = ?', [(int) post('id')]);
        flash('Removed from the list.', 'info');
    }

    redirect('admin/govt-exam.php');
}

// ---- CSV export of the interest list -------------------------------
if (get('export') === 'csv' && has_table('exam_notify')) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="govt-exam-list-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Email', 'Phone', 'Exam', 'Note', 'Player', 'Joined']);
    foreach (fetch_all('SELECT e.*, u.username FROM exam_notify e
                        LEFT JOIN users u ON u.id = e.user_id
                        ORDER BY e.id DESC') as $r) {
        fputcsv($out, [$r['name'], $r['email'], $r['phone'], $r['exam_pref'],
                       $r['note'], $r['username'], $r['created_at']]);
    }
    fclose($out);
    exit;
}

$installed = has_table('exam_notify');
$list      = $installed
    ? fetch_all('SELECT e.*, u.username FROM exam_notify e
                 LEFT JOIN users u ON u.id = e.user_id
                 ORDER BY e.id DESC LIMIT 200')
    : [];
$byExam    = $installed
    ? fetch_all('SELECT COALESCE(NULLIF(exam_pref, ""), "Not decided") AS label,
                        COUNT(*) AS n
                   FROM exam_notify GROUP BY label ORDER BY n DESC')
    : [];
$total     = govt_exam_signups();
$week      = $installed
    ? (int) fetch_col('SELECT COUNT(*) FROM exam_notify
                        WHERE created_at > (NOW() - INTERVAL 7 DAY)', [], 0)
    : 0;
$days      = govt_exam_days_left();

require __DIR__ . '/includes/layout.php';
?>

<div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:22px"
     class="adm-split">
  <div class="card stat-card"><div class="sc-num"><?= num($total) ?></div>
    <div class="sc-lbl">On the interest list</div></div>
  <div class="card stat-card"><div class="sc-num"><?= num($week) ?></div>
    <div class="sc-lbl">Joined this week</div></div>
  <div class="card stat-card">
    <div class="sc-num"><?= govt_exam_status() === 'live' ? 'Live' : 'Soon' ?></div>
    <div class="sc-lbl">Current status</div></div>
  <div class="card stat-card">
    <div class="sc-num"><?= $days === null ? '&mdash;' : (int) $days ?></div>
    <div class="sc-lbl">Days to launch</div></div>
</div>

<div class="adm-form-grid">

  <!-- ==================== SETTINGS ==================== -->
  <div class="adm-panel">
    <div class="adm-panel-head">
      <div><h2>Section settings</h2><p>What players see in the navigation</p></div>
      <a href="<?= url('govt-exam.php') ?>" target="_blank" rel="noopener"
         class="btn btn-ghost btn-sm">Preview &nearr;</a>
    </div>
    <div class="adm-panel-body">
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="do" value="save">

        <label class="check">
          <input type="checkbox" name="enabled" value="1"
                 <?= govt_exam_on() ? 'checked' : '' ?>>
          <span>The section is switched on</span>
        </label>
        <label class="check">
          <input type="checkbox" name="in_nav" value="1"
                 <?= module_in_nav('govt_exam') ? 'checked' : '' ?>>
          <span>Show it in the site navigation</span>
        </label>

        <div class="row mt-3" style="gap:12px">
          <div class="form-group" style="flex:1">
            <label class="form-label" for="label">Navigation label</label>
            <input class="form-control" type="text" id="label" name="label" maxlength="30"
                   value="<?= e(setting('govt_exam_label', 'Govt Exam')) ?>">
          </div>
          <div class="form-group" style="flex:1">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
              <option value="soon" <?= govt_exam_status() === 'soon' ? 'selected' : '' ?>>
                Coming soon — shows a “soon” flag</option>
              <option value="live" <?= govt_exam_status() === 'live' ? 'selected' : '' ?>>
                Live — no flag</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="launch">Launch date (optional)</label>
          <input class="form-control" type="date" id="launch" name="launch"
                 value="<?= e(govt_exam_launch()) ?>" style="max-width:200px">
          <p class="form-hint">Set it and the page counts down to it. Leave blank for no date.</p>
        </div>

        <div class="form-group">
          <label class="form-label" for="tagline">Tagline (one line, under the title)</label>
          <input class="form-control" type="text" id="tagline" name="tagline" maxlength="190"
                 value="<?= e(setting('govt_exam_tagline')) ?>">
        </div>

        <div class="form-group">
          <label class="form-label" for="blurb">Main paragraph</label>
          <textarea class="form-control" id="blurb" name="blurb" rows="4"
                    maxlength="600"><?= e(setting('govt_exam_blurb')) ?></textarea>
        </div>

        <button class="btn btn-primary" type="submit">Save</button>
      </form>
    </div>
  </div>

  <!-- ==================== BY EXAM ==================== -->
  <div class="adm-panel">
    <div class="adm-panel-head"><div><h2>Which exam they want</h2></div></div>
    <div class="adm-panel-body">
      <?php if (!$byExam): ?>
        <p class="text-dim" style="margin:0">Nobody has joined the list yet.</p>
      <?php else: ?>
        <?php foreach ($byExam as $b):
              $pct = $total > 0 ? round($b['n'] / $total * 100) : 0; ?>
          <div class="kv"><span class="k"><?= e($b['label']) ?></span>
            <span class="v"><?= num($b['n']) ?>
              <span class="text-dim">· <?= $pct ?>%</span></span></div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ==================== THE LIST ==================== -->
<div class="adm-panel">
  <div class="adm-panel-head">
    <div><h2>Interest list</h2><p>Newest first, most recent 200</p></div>
    <?php if ($list): ?>
      <a href="<?= admin_url('govt-exam.php?export=csv') ?>"
         class="btn btn-ghost btn-xs">Download CSV</a>
    <?php endif; ?>
  </div>
  <div class="adm-panel-body flush">
    <?php if (!$installed): ?>
      <div class="empty"><div class="em-ico">&#127891;</div>
        <h3>Table not installed</h3>
        <p>Run <code>cppsmigration.php</code> once to create the interest list.</p></div>
    <?php elseif (!$list): ?>
      <div class="empty"><div class="em-ico">&#127891;</div>
        <h3>Nobody yet</h3>
        <p>Names appear here as soon as someone signs up on the landing page.</p></div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="tbl" style="min-width:860px">
          <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Exam</th>
                     <th>Note</th><th>Player</th><th>Joined</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($list as $r): ?>
              <tr>
                <td><strong><?= e($r['name'] ?: '—') ?></strong></td>
                <td class="text-muted"><?= e($r['email']) ?></td>
                <td class="text-dim"><?= e($r['phone'] ?: '—') ?></td>
                <td><?= $r['exam_pref']
                      ? '<span class="badge badge-green">' . e($r['exam_pref']) . '</span>'
                      : '<span class="text-dim">—</span>' ?></td>
                <td class="text-dim"><?= e(excerpt($r['note'] ?? '', 40)) ?></td>
                <td class="text-dim"><?= $r['username'] ? e($r['username']) : 'guest' ?></td>
                <td class="text-dim nowrap"><?= fdate($r['created_at'], 'd M, H:i') ?></td>
                <td>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?><input type="hidden" name="do" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button class="btn btn-ghost btn-xs" type="submit"
                            data-confirm="Remove this entry?">Remove</button>
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

<style>
@media (max-width: 1180px) { .adm-split { grid-template-columns: 1fr !important; } }
</style>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
