<?php
/**
 * Admin — marks & scores.
 *
 * Correct a game score or a quiz result: a round that was voided
 * unfairly, a marking mistake, a goodwill adjustment. Every change is
 * written to the audit log with who made it and why, and any coin
 * difference goes through the normal ledger — so balances and history
 * never drift apart.
 *
 * Changing a quiz round's marks re-decides the level: if the corrected
 * score now passes, the next level unlocks exactly as it would have.
 */
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();
require_manage();

$admin_title = 'Marks & Scores';
$admin_sub   = 'Correct a result — every edit is logged';

$tab = get('tab', 'quiz') === 'games' ? 'games' : 'quiz';

if ($tab === 'quiz' && !has_table('quiz_sessions')) {
    $tab = 'games';
}

// -------------------------------------------------------------------
// Save an edit
// -------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $do     = post('do');
    $reason = post('reason');

    if ($reason === '') {
        flash('Please say why the result is being changed — it goes in the log.', 'error');
        redirect('admin/marks.php?tab=' . $tab);
    }

    if ($do === 'edit_quiz') {
        $n = marks_edit_quiz((int) post('id'), [
            'correct'      => post('correct'),
            'coins_earned' => post('coins_earned'),
            'status'       => post('status'),
        ], $reason, $A);
        flash($n ? $n . ' field(s) updated and logged.' : 'Nothing changed.', $n ? 'success' : 'info');

    } elseif ($do === 'edit_score') {
        $n = marks_edit_score((int) post('id'), [
            'score'        => post('score'),
            'coins_earned' => post('coins_earned'),
            'result'       => post('result'),
        ], $reason, $A);
        flash($n ? $n . ' field(s) updated and logged.' : 'Nothing changed.', $n ? 'success' : 'info');
    }

    redirect('admin/marks.php?tab=' . $tab . (get('q') !== '' ? '&q=' . urlencode(get('q')) : ''));
}

// -------------------------------------------------------------------
// Data
// -------------------------------------------------------------------
$search = trim(get('q'));
$editId = (int) get('edit');

$quizRows = [];
$gameRows = [];

if ($tab === 'quiz' && has_table('quiz_sessions')) {
    $w = []; $p = [];
    if ($search !== '') {
        $w[] = '(u.username LIKE ? OR u.name LIKE ?)';
        $p[] = '%' . $search . '%'; $p[] = '%' . $search . '%';
    }
    $quizRows = fetch_all(
        'SELECT s.*, u.username, u.name,
                c.name AS category
           FROM quiz_sessions s
           JOIN users u ON u.id = s.user_id
           LEFT JOIN quiz_categories c ON c.id = s.category_id'
        . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
        . ' ORDER BY s.id DESC LIMIT 60', $p);
} else {
    $w = []; $p = [];
    if ($search !== '') {
        $w[] = '(u.username LIKE ? OR u.name LIKE ? OR g.title LIKE ?)';
        $p[] = '%' . $search . '%'; $p[] = '%' . $search . '%'; $p[] = '%' . $search . '%';
    }
    $gameRows = fetch_all(
        'SELECT s.*, u.username, u.name, g.title AS game
           FROM scores s
           JOIN users u ON u.id = s.user_id
           JOIN games g ON g.id = s.game_id'
        . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
        . ' ORDER BY s.id DESC LIMIT 60', $p);
}

$recentEdits = has_table('score_edits')
    ? fetch_all('SELECT e.*, u.username FROM score_edits e
                   LEFT JOIN users u ON u.id = e.user_id
                  ORDER BY e.id DESC LIMIT 25')
    : [];

$editRow = null;
if ($editId > 0) {
    $editRow = $tab === 'quiz'
        ? fetch_one('SELECT s.*, u.username FROM quiz_sessions s
                       JOIN users u ON u.id = s.user_id WHERE s.id = ?', [$editId])
        : fetch_one('SELECT s.*, u.username, g.title AS game FROM scores s
                       JOIN users u ON u.id = s.user_id
                       JOIN games g ON g.id = s.game_id WHERE s.id = ?', [$editId]);
}

require __DIR__ . '/includes/layout.php';
?>

<div class="tabs" style="margin-bottom:18px">
  <a href="<?= admin_url('marks.php?tab=quiz') ?>"  class="<?= $tab === 'quiz'  ? 'active' : '' ?>">
    &#129504; Quiz marks</a>
  <a href="<?= admin_url('marks.php?tab=games') ?>" class="<?= $tab === 'games' ? 'active' : '' ?>">
    &#127918; Game scores</a>
</div>

<?php if ($editRow): ?>
  <!-- ==================== EDIT ONE RESULT ==================== -->
  <div class="adm-panel" style="margin-bottom:22px">
    <div class="adm-panel-head">
      <div>
        <h2>Editing <?= $tab === 'quiz' ? 'quiz round' : 'score' ?> #<?= (int) $editRow['id'] ?></h2>
        <p><?= e($editRow['username']) ?> &middot;
           <?= $tab === 'quiz'
               ? e(ucfirst($editRow['difficulty'])) . ' level ' . (int) $editRow['level_no']
               : e($editRow['game']) ?>
           &middot; <?= fdate($editRow['started_at'] ?? $editRow['played_at'], 'd M Y, H:i') ?></p>
      </div>
      <a class="btn btn-ghost btn-sm" href="<?= admin_url('marks.php?tab=' . $tab) ?>">Close</a>
    </div>
    <div class="adm-panel-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="<?= $tab === 'quiz' ? 'edit_quiz' : 'edit_score' ?>">
        <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">

        <div class="row" style="gap:12px">
          <?php if ($tab === 'quiz'): ?>
            <div class="form-group" style="flex:1">
              <label class="form-label">Correct answers (of <?= (int) $editRow['total'] ?>)</label>
              <input class="form-control" type="number" min="0" max="<?= (int) $editRow['total'] ?>"
                     name="correct" value="<?= (int) $editRow['correct'] ?>">
            </div>
            <div class="form-group" style="flex:1">
              <label class="form-label">Coins earned</label>
              <input class="form-control" type="number" min="0" name="coins_earned"
                     value="<?= (int) $editRow['coins_earned'] ?>">
            </div>
            <div class="form-group" style="flex:1">
              <label class="form-label">Status</label>
              <select class="form-control" name="status">
                <?php foreach (['finished', 'active', 'abandoned', 'voided'] as $s): ?>
                  <option value="<?= $s ?>" <?= $editRow['status'] === $s ? 'selected' : '' ?>>
                    <?= ucfirst($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php else: ?>
            <div class="form-group" style="flex:1">
              <label class="form-label">Score</label>
              <input class="form-control" type="number" min="0" name="score"
                     value="<?= (int) $editRow['score'] ?>">
            </div>
            <div class="form-group" style="flex:1">
              <label class="form-label">Coins earned</label>
              <input class="form-control" type="number" min="0" name="coins_earned"
                     value="<?= (int) $editRow['coins_earned'] ?>">
            </div>
            <div class="form-group" style="flex:1">
              <label class="form-label">Result</label>
              <select class="form-control" name="result">
                <?php foreach (['win', 'loss', 'incomplete'] as $s): ?>
                  <option value="<?= $s ?>" <?= $editRow['result'] === $s ? 'selected' : '' ?>>
                    <?= ucfirst($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>
        </div>

        <div class="form-group">
          <label class="form-label">Why is this being changed?</label>
          <input class="form-control" type="text" name="reason" required maxlength="190"
                 placeholder="e.g. round voided by a network drop, verified with the player">
          <p class="form-hint">
            Coin differences are credited or debited through the ledger, so the player's
            balance stays consistent with their transaction history.
            <?php if ($tab === 'quiz'): ?>
              Changing the mark also re-decides the level — a corrected pass unlocks the next one.
            <?php endif; ?>
          </p>
        </div>

        <button class="btn btn-primary" type="submit">Save and log the change</button>
      </form>

      <?php $hist = marks_history($tab === 'quiz' ? 'quiz' : 'score', (int) $editRow['id']);
            if ($hist): ?>
        <h3 class="mt-4" style="font-size:15px">Previous edits</h3>
        <table class="tbl mt-2">
          <thead><tr><th>When</th><th>By</th><th>Field</th><th>From</th><th>To</th><th>Reason</th></tr></thead>
          <tbody>
          <?php foreach ($hist as $h): ?>
            <tr>
              <td class="text-dim"><?= fdate($h['created_at'], 'd M, H:i') ?></td>
              <td><?= e($h['admin_name'] ?: '—') ?></td>
              <td><code><?= e($h['field']) ?></code></td>
              <td class="text-dim"><?= e($h['old_value']) ?></td>
              <td class="text-green"><?= e($h['new_value']) ?></td>
              <td class="text-dim"><?= e($h['reason']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<!-- ==================== LIST ==================== -->
<div class="adm-panel">
  <div class="adm-panel-head">
    <div><h2><?= $tab === 'quiz' ? 'Quiz rounds' : 'Game rounds' ?></h2>
      <p>Newest first. Use Edit to correct a result.</p></div>
    <form method="get" class="row" style="gap:8px;margin:0">
      <input type="hidden" name="tab" value="<?= $tab ?>">
      <input class="form-control" type="search" name="q" value="<?= e($search) ?>"
             placeholder="Search player<?= $tab === 'games' ? ' or game' : '' ?>" style="width:230px">
      <button class="btn btn-ghost btn-sm" type="submit">Search</button>
    </form>
  </div>

  <div class="adm-panel-body flush">
    <div class="table-scroll">
      <?php if ($tab === 'quiz'): ?>
        <table class="tbl" style="min-width:900px">
          <thead><tr><th>#</th><th>Player</th><th>Level</th><th>Marks</th><th>Coins</th>
                     <th>Status</th><th>When</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($quizRows as $r): ?>
            <tr>
              <td class="text-dim"><?= (int) $r['id'] ?></td>
              <td><strong><?= e($r['username']) ?></strong>
                <div class="text-dim"><?= e($r['category'] ?: 'All topics') ?></div></td>
              <td>
                <span class="badge badge-info"><?= e(ucfirst($r['difficulty'])) ?></span>
                <?php if ((int) $r['level_no'] > 0): ?>
                  <span class="text-dim">L<?= (int) $r['level_no'] ?></span>
                <?php endif; ?>
              </td>
              <td><strong><?= (int) $r['correct'] ?></strong>
                <span class="text-dim">/ <?= (int) $r['total'] ?></span>
                <?php if (!empty($r['marks_edited_at'])): ?>
                  <span class="badge badge-gold" title="Edited by an admin">edited</span>
                <?php endif; ?>
              </td>
              <td class="text-gold">+<?= num((int) $r['coins_earned']) ?></td>
              <td><span class="badge badge-<?= $r['status'] === 'finished' ? 'green'
                        : ($r['status'] === 'voided' ? 'muted' : 'info') ?>">
                    <?= e($r['status']) ?></span></td>
              <td class="text-dim"><?= fdate($r['started_at'], 'd M, H:i') ?></td>
              <td><a class="btn btn-ghost btn-sm"
                     href="<?= admin_url('marks.php?tab=quiz&edit=' . (int) $r['id']) ?>">Edit</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <table class="tbl" style="min-width:900px">
          <thead><tr><th>#</th><th>Player</th><th>Game</th><th>Score</th><th>Coins</th>
                     <th>Result</th><th>When</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($gameRows as $r): ?>
            <tr>
              <td class="text-dim"><?= (int) $r['id'] ?></td>
              <td><strong><?= e($r['username']) ?></strong>
                <div class="text-dim"><?= e($r['name']) ?></div></td>
              <td><?= e($r['game']) ?></td>
              <td><strong><?= num((int) $r['score']) ?></strong>
                <?php if (!empty($r['edited_at'])): ?>
                  <span class="badge badge-gold" title="Edited by an admin">edited</span>
                <?php endif; ?></td>
              <td class="text-gold">+<?= num((int) $r['coins_earned']) ?></td>
              <td><span class="badge badge-<?= $r['result'] === 'win' ? 'green' : 'muted' ?>">
                    <?= e($r['result']) ?></span></td>
              <td class="text-dim"><?= fdate($r['played_at'], 'd M, H:i') ?></td>
              <td><a class="btn btn-ghost btn-sm"
                     href="<?= admin_url('marks.php?tab=games&edit=' . (int) $r['id']) ?>">Edit</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ==================== AUDIT LOG ==================== -->
<?php if ($recentEdits): ?>
<div class="adm-panel mt-4">
  <div class="adm-panel-head">
    <div><h2>Edit log</h2><p>Every correction made anywhere on the site.</p></div>
  </div>
  <div class="adm-panel-body flush">
    <div class="table-scroll">
      <table class="tbl" style="min-width:860px">
        <thead><tr><th>When</th><th>Admin</th><th>What</th><th>Player</th>
                   <th>Field</th><th>From → To</th><th>Reason</th></tr></thead>
        <tbody>
        <?php foreach ($recentEdits as $h): ?>
          <tr>
            <td class="text-dim"><?= fdate($h['created_at'], 'd M, H:i') ?></td>
            <td><?= e($h['admin_name'] ?: '—') ?></td>
            <td><span class="badge badge-info"><?= e($h['kind']) ?> #<?= (int) $h['ref_id'] ?></span></td>
            <td class="text-dim"><?= e($h['username'] ?: '—') ?></td>
            <td><code><?= e($h['field']) ?></code></td>
            <td><span class="text-dim"><?= e($h['old_value']) ?></span>
                &rarr; <span class="text-green"><?= e($h['new_value']) ?></span></td>
            <td class="text-dim"><?= e(excerpt($h['reason'] ?? '', 60)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
