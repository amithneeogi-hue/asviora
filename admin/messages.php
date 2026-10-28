<?php
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();

$admin_title = 'Inbox';
$admin_sub   = 'Messages sent through the contact form';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $do = post('do');
    $id = (int) post('id');
    if ($do === 'read' && $id) {
        q('UPDATE contact_messages SET is_read = 1 - is_read WHERE id = ?', [$id]);
    } elseif ($do === 'delete' && $id) {
        q('DELETE FROM contact_messages WHERE id = ?', [$id]);
        flash('Message deleted.');
    } elseif ($do === 'read_all') {
        q('UPDATE contact_messages SET is_read = 1 WHERE is_read = 0');
        flash('All messages marked as read.');
    }
    redirect('admin/messages.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
}

$filter = get('filter');
$page   = max(1, (int) get('page', '1'));
$per    = 15;

$where = $filter === 'unread' ? 'is_read = 0' : ($filter === 'read' ? 'is_read = 1' : '1 = 1');

$total  = (int) fetch_col("SELECT COUNT(*) FROM contact_messages WHERE $where", [], 0);
$pages  = max(1, (int) ceil($total / $per));
$page   = min($page, $pages);
$offset = ($page - 1) * $per;

$msgs = fetch_all("SELECT * FROM contact_messages WHERE $where
                   ORDER BY is_read ASC, id DESC LIMIT $per OFFSET $offset");

$unread = (int) fetch_col('SELECT COUNT(*) FROM contact_messages WHERE is_read = 0', [], 0);

require __DIR__ . '/includes/layout.php';
?>

<div class="adm-panel">
  <div class="adm-toolbar">
    <a href="<?= admin_url('messages.php') ?>"
       class="btn <?= $filter === '' ? 'btn-primary' : 'btn-ghost' ?> btn-sm">All (<?= num($total) ?>)</a>
    <a href="<?= admin_url('messages.php?filter=unread') ?>"
       class="btn <?= $filter === 'unread' ? 'btn-primary' : 'btn-ghost' ?> btn-sm">Unread (<?= num($unread) ?>)</a>
    <a href="<?= admin_url('messages.php?filter=read') ?>"
       class="btn <?= $filter === 'read' ? 'btn-primary' : 'btn-ghost' ?> btn-sm">Read</a>
    <span style="flex:1"></span>
    <?php if ($unread): ?>
      <form method="post" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="do" value="read_all">
        <button class="btn btn-ghost btn-sm" type="submit">Mark all as read</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="adm-panel-body">
    <?php if (!$msgs): ?>
      <div class="empty"><div class="em-ico">&#9993;</div>
        <h3>Nothing here</h3><p>Messages from the contact form will land in this inbox.</p></div>
    <?php else: ?>
      <?php foreach ($msgs as $m): ?>
        <div class="card mb-2" style="<?= $m['is_read'] ? '' : 'border-color:rgba(229,184,75,.35)' ?>">
          <div class="row between" style="align-items:flex-start;gap:14px">
            <div style="flex:1 1 240px;min-width:0">
              <div class="row" style="gap:8px;align-items:center;margin-bottom:6px">
                <strong style="font-size:1rem"><?= e($m['name']) ?></strong>
                <?php if (!$m['is_read']): ?><span class="badge badge-gold">new</span><?php endif; ?>
                <?php if ($m['subject']): ?><span class="badge"><?= e($m['subject']) ?></span><?php endif; ?>
              </div>
              <div class="text-dim mb-2">
                <a href="mailto:<?= e($m['email']) ?>"><?= e($m['email']) ?></a>
                &middot; <?= fdate($m['created_at'], 'd M Y, H:i') ?>
                &middot; IP <?= e($m['ip_address'] ?: '—') ?>
              </div>
              <p class="text-muted" style="margin:0;white-space:pre-wrap;word-break:break-word"><?= e($m['message']) ?></p>
            </div>
            <div class="adm-actions" style="flex-shrink:0">
              <a href="mailto:<?= e($m['email']) ?>?subject=<?= urlencode('Re: ' . ($m['subject'] ?: 'Your message')) ?>"
                 class="btn btn-primary btn-xs">Reply</a>
              <form method="post" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="do" value="read">
                <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                <button class="btn btn-ghost btn-xs" type="submit">
                  Mark <?= $m['is_read'] ? 'unread' : 'read' ?></button>
              </form>
              <form method="post" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="do" value="delete">
                <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                <button class="btn btn-danger btn-xs" type="submit"
                  data-confirm="Delete this message?">Delete</button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($pages > 1): ?>
  <nav class="pagination">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
      <?php $link = admin_url('messages.php?' . http_build_query(array_filter(['filter' => $filter, 'page' => $i]))); ?>
      <?php if ($i === $page): ?><span class="current"><?= $i ?></span>
      <?php else: ?><a href="<?= $link ?>"><?= $i ?></a><?php endif; ?>
    <?php endfor; ?>
  </nav>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
