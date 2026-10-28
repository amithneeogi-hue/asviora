<?php
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();

$admin_title = 'Newsletter Subscribers';
$admin_sub   = 'Everyone who asked to hear about new game launches';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    if (post('do') === 'delete') {
        q('DELETE FROM subscribers WHERE id = ?', [(int) post('id')]);
        flash('Subscriber removed.');
    }
    redirect('admin/subscribers.php');
}

/* CSV export */
if (get('export') === 'csv') {
    $rows = fetch_all('SELECT email, created_at FROM subscribers ORDER BY id DESC');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="asviora-subscribers-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Email', 'Subscribed on']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['email'], $r['created_at']]);
    }
    fclose($out);
    exit;
}

$subs  = fetch_all('SELECT * FROM subscribers ORDER BY id DESC LIMIT 500');
$total = (int) fetch_col('SELECT COUNT(*) FROM subscribers', [], 0);
$week  = (int) fetch_col('SELECT COUNT(*) FROM subscribers
                          WHERE created_at > (NOW() - INTERVAL 7 DAY)', [], 0);

$admin_actions = $total
    ? '<a href="' . admin_url('subscribers.php?export=csv') . '" class="btn btn-primary btn-sm">&#11015; Export CSV</a>'
    : '';

require __DIR__ . '/includes/layout.php';
?>

<div class="adm-stats" style="grid-template-columns:repeat(2,minmax(0,1fr))">
  <div class="adm-stat green"><span class="as-ico">&#128231;</span>
    <div class="as-num"><?= num($total) ?></div><div class="as-lbl">Total subscribers</div></div>
  <div class="adm-stat gold"><span class="as-ico">&#128200;</span>
    <div class="as-num"><?= num($week) ?></div><div class="as-lbl">Joined this week</div></div>
</div>

<div class="adm-panel">
  <div class="adm-panel-head">
    <div><h2>Subscriber list</h2>
      <p><?= $total > 500 ? 'Showing the 500 most recent — export for the full list' : 'Newest first' ?></p></div>
  </div>
  <div class="adm-panel-body flush">
    <?php if (!$subs): ?>
      <div class="empty"><div class="em-ico">&#128231;</div>
        <h3>No subscribers yet</h3>
        <p>The signup box lives on the homepage and the contact page.</p></div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="tbl" style="min-width:520px">
          <thead><tr><th style="width:56px">#</th><th>Email</th><th>Subscribed</th>
                     <th style="text-align:right"></th></tr></thead>
          <tbody>
            <?php foreach ($subs as $i => $s): ?>
              <tr>
                <td class="text-dim"><?= $i + 1 ?></td>
                <td><a href="mailto:<?= e($s['email']) ?>"><?= e($s['email']) ?></a></td>
                <td class="text-dim"><?= fdate($s['created_at'], 'd M Y, H:i') ?></td>
                <td>
                  <div class="adm-actions">
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?><input type="hidden" name="do" value="delete">
                      <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                      <button class="btn btn-danger btn-xs" type="submit"
                        data-confirm="Remove <?= e($s['email']) ?> from the list?">Remove</button>
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

<?php require __DIR__ . '/includes/layout_end.php'; ?>
