<?php
/**
 * Admin — visitors.
 *
 * Every page view the site logged, sliced the ways you actually want to
 * look at it: per day, per month, per year, and by country / region /
 * city. Also which pages get opened, on what devices, and where the
 * traffic came from.
 *
 * The raw table can be purged from here; the daily roll-up (visit_daily)
 * survives a purge, so the long-range month and year totals stay right.
 */
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();
require_manage();

$admin_title = 'Visitors';
$admin_sub   = 'Traffic by day, month, year and location';

if (!has_table('visits')) {
    flash('Visitor logging is not installed yet. Run cppsmigration.php once, '
        . 'then come back.', 'error');
    redirect('admin/index.php');
}

// -------------------------------------------------------------------
// Actions
// -------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $do = post('do');

    if ($do === 'settings') {
        set_setting('analytics_enabled',        empty($_POST['analytics_enabled']) ? '0' : '1');
        set_setting('analytics_ignore_admin',   empty($_POST['ignore_admin']) ? '0' : '1');
        set_setting('analytics_ignore_bots',    empty($_POST['ignore_bots']) ? '0' : '1');
        $mode = post('geo_mode');
        set_setting('analytics_geo_mode',
                    in_array($mode, ['off', 'header', 'auto'], true) ? $mode : 'auto');
        set_setting('analytics_retention_days',
                    (string) max(7, min(3650, (int) post('retention'))));
        flash('Visitor settings saved.');

    } elseif ($do === 'resolve') {
        // each lookup can take up to two seconds, so give the batch room
        @set_time_limit(180);
        $n = resolve_pending_geo(25);
        flash($n > 0
            ? $n . ' view' . ($n === 1 ? '' : 's') . ' given a location.'
            : 'Nothing could be resolved this time — try again in a moment.',
            $n > 0 ? 'success' : 'info');

    } elseif ($do === 'purge') {
        $n = purge_old_visits((int) post('days'));
        flash(num($n) . ' old page view' . ($n === 1 ? '' : 's') . ' deleted.');

    } elseif ($do === 'wipe') {
        q('DELETE FROM visits');
        q('DELETE FROM visit_daily');
        flash('Visitor log cleared.', 'info');
    }

    redirect('admin/visitors.php?range=' . urlencode(get('range', '30d')));
}

// -------------------------------------------------------------------
// Data
// -------------------------------------------------------------------
$rangeKey = get('range', '30d');
[$from, $to, $rangeLabel] = visit_range($rangeKey);

$totals    = visit_totals($from, $to);
$byDay     = visits_by_day($from, $to);
$byHour    = visits_by_hour($from, $to);
$countries = visits_group('country', $from, $to, 12);
$places    = visits_by_place($from, $to, 30);
$pages     = visits_group('page', $from, $to, 15);
$devices   = visits_group('device', $from, $to, 6);
$browsers  = visits_group('browser', $from, $to, 8);
$refs      = visits_group('referrer_host', $from, $to, 10);
$months    = visits_by_month(24);
$years     = visits_by_year();
$recent    = visits_recent(30);
$pending   = visits_pending_geo();

$peakDay  = 1;
foreach ($byDay as $d) { $peakDay = max($peakDay, $d['views']); }
$peakHour = max(1, max($byHour));

$ranges = ['today' => 'Today', 'yest' => 'Yesterday', '7d' => '7 days',
           '30d' => '30 days', '90d' => '90 days', 'month' => 'This month',
           'year' => 'This year', 'all' => 'All time'];

require __DIR__ . '/includes/layout.php';
?>

<!-- ==================== RANGE ==================== -->
<div class="tabs" style="margin-bottom:18px">
  <?php foreach ($ranges as $k => $lbl): ?>
    <a href="<?= admin_url('visitors.php?range=' . $k) ?>"
       class="<?= $rangeKey === $k ? 'active' : '' ?>"><?= e($lbl) ?></a>
  <?php endforeach; ?>
</div>

<?php if (!analytics_on()): ?>
  <div class="alert alert-info"><span>&#8505;</span>
    Visitor logging is currently switched <strong>off</strong> — the numbers below
    stop at the last view that was recorded. Turn it back on at the bottom of this page.
  </div>
<?php endif; ?>

<!-- ==================== HEADLINE ==================== -->
<div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:22px"
     class="adm-split">
  <div class="card stat-card"><div class="sc-num"><?= num($totals['views']) ?></div>
    <div class="sc-lbl">Page views</div>
    <div class="text-dim" style="font-size:12px"><?= e($rangeLabel) ?></div></div>
  <div class="card stat-card"><div class="sc-num"><?= num($totals['visitors']) ?></div>
    <div class="sc-lbl">Unique visitors</div>
    <div class="text-dim" style="font-size:12px"><?= num($totals['new']) ?> first-time</div></div>
  <div class="card stat-card"><div class="sc-num"><?= num($totals['members']) ?></div>
    <div class="sc-lbl">Signed-in players</div>
    <div class="text-dim" style="font-size:12px">of <?= num($totals['visitors']) ?> devices</div></div>
  <div class="card stat-card"><div class="sc-num"><?= num($totals['countries']) ?></div>
    <div class="sc-lbl">Countries</div>
    <div class="text-dim" style="font-size:12px"><?= num($totals['cities']) ?> cities</div></div>
</div>

<!-- ==================== PER DAY ==================== -->
<div class="adm-panel">
  <div class="adm-panel-head">
    <div><h2>Views per day</h2>
      <p>Busiest day in this range: <?= num($peakDay) ?> view<?= $peakDay === 1 ? '' : 's' ?></p></div>
    <?php if ($pending > 0): ?>
      <form method="post" style="margin:0">
        <?= csrf_field() ?><input type="hidden" name="do" value="resolve">
        <button class="btn btn-ghost btn-xs" type="submit">
          Resolve <?= num($pending) ?> pending location<?= $pending === 1 ? '' : 's' ?></button>
      </form>
    <?php endif; ?>
  </div>
  <div class="adm-panel-body">
    <div class="adm-chart">
      <?php foreach ($byDay as $day => $d): ?>
        <div class="bar-col"
             title="<?= date('d M Y', strtotime($day)) ?>: <?= $d['views'] ?> views, <?= $d['visitors'] ?> visitors">
          <span class="bar-val"><?= $d['views'] ?: '' ?></span>
          <div class="bar-fill" style="height:<?= max(2, ($d['views'] / $peakDay) * 100) ?>%"></div>
          <span class="bar-lbl"><?= date('d/m', strtotime($day)) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ==================== LOCATION IN DETAIL ==================== -->
<div class="adm-form-grid">

  <div class="adm-panel">
    <div class="adm-panel-head">
      <div><h2>By country</h2><p><?= e($rangeLabel) ?></p></div>
    </div>
    <div class="adm-panel-body flush">
      <?php if (!$countries): ?>
        <div class="empty"><div class="em-ico">&#127760;</div>
          <h3>No location data yet</h3>
          <p>Locations appear as soon as the first visitor is resolved.</p></div>
      <?php else: ?>
        <div class="table-scroll">
          <table class="tbl">
            <thead><tr><th>Country</th><th>Views</th><th>Visitors</th><th>Share</th></tr></thead>
            <tbody>
              <?php foreach ($countries as $c):
                    $share = $totals['views'] > 0
                           ? round($c['views'] / $totals['views'] * 100) : 0; ?>
                <tr>
                  <td><?= country_flag($c['cc']) ?> <strong><?= e($c['label']) ?></strong></td>
                  <td><?= num($c['views']) ?></td>
                  <td class="text-green"><?= num($c['visitors']) ?></td>
                  <td style="min-width:120px">
                    <div class="bar"><i style="width:<?= min(100, $share) ?>%"></i></div>
                    <span class="text-dim"><?= $share ?>%</span>
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
    <div class="adm-panel-head"><div><h2>Devices &amp; browsers</h2></div></div>
    <div class="adm-panel-body">
      <h3 style="font-size:.95rem;margin:0 0 8px">Device</h3>
      <?php foreach ($devices as $d): ?>
        <div class="kv"><span class="k"><?= e(ucfirst($d['label'])) ?></span>
          <span class="v"><?= num($d['views']) ?></span></div>
      <?php endforeach; ?>
      <?php if (!$devices): ?><p class="text-dim">Nothing yet.</p><?php endif; ?>

      <h3 style="font-size:.95rem;margin:18px 0 8px">Browser</h3>
      <?php foreach ($browsers as $b): ?>
        <div class="kv"><span class="k"><?= e($b['label']) ?></span>
          <span class="v"><?= num($b['views']) ?></span></div>
      <?php endforeach; ?>
      <?php if (!$browsers): ?><p class="text-dim">Nothing yet.</p><?php endif; ?>
    </div>
  </div>
</div>

<div class="adm-panel">
  <div class="adm-panel-head">
    <div><h2>City &amp; region breakdown</h2>
      <p>The same traffic, down to the town it came from</p></div>
  </div>
  <div class="adm-panel-body flush">
    <?php if (!$places): ?>
      <div class="empty"><div class="em-ico">&#128506;</div>
        <h3>Nothing to place yet</h3>
        <p>City-level data needs the IP lookup — check the geo mode at the bottom of this page.</p></div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="tbl" style="min-width:720px">
          <thead><tr><th>Country</th><th>Region / state</th><th>City</th>
                     <th>Views</th><th>Visitors</th><th>Last seen</th></tr></thead>
          <tbody>
            <?php foreach ($places as $p): ?>
              <tr>
                <td><?= country_flag($p['cc']) ?> <strong><?= e($p['country']) ?></strong></td>
                <td class="text-muted"><?= e($p['region']) ?></td>
                <td class="text-muted"><?= e($p['city']) ?></td>
                <td><strong class="text-green"><?= num($p['views']) ?></strong></td>
                <td class="text-dim"><?= num($p['visitors']) ?></td>
                <td class="text-dim nowrap"><?= fdate($p['last_seen'], 'd M, H:i') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ==================== MONTH / YEAR ==================== -->
<div class="adm-form-grid">

  <div class="adm-panel">
    <div class="adm-panel-head"><div><h2>Per month</h2><p>Last 24 months</p></div></div>
    <div class="adm-panel-body flush">
      <div class="table-scroll">
        <table class="tbl">
          <thead><tr><th>Month</th><th>Views</th><th>Visitors</th><th>Signed in</th></tr></thead>
          <tbody>
            <?php foreach ($months as $m): ?>
              <tr>
                <td><strong><?= date('F Y', strtotime($m['ym'] . '-01')) ?></strong></td>
                <td><?= num($m['views']) ?></td>
                <td class="text-green"><?= num($m['visitors']) ?></td>
                <td class="text-dim"><?= num($m['members']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$months): ?>
              <tr><td colspan="4" class="text-muted center">No months recorded yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="adm-panel">
    <div class="adm-panel-head"><div><h2>Per year</h2></div></div>
    <div class="adm-panel-body flush">
      <div class="table-scroll">
        <table class="tbl">
          <thead><tr><th>Year</th><th>Views</th><th>Visitors</th><th>Signed in</th></tr></thead>
          <tbody>
            <?php foreach ($years as $y): ?>
              <tr>
                <td><strong><?= (int) $y['y'] ?></strong></td>
                <td><?= num($y['views']) ?></td>
                <td class="text-green"><?= num($y['visitors']) ?></td>
                <td class="text-dim"><?= num($y['members']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$years): ?>
              <tr><td colspan="4" class="text-muted center">No years recorded yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ==================== HOUR OF DAY ==================== -->
<div class="adm-panel">
  <div class="adm-panel-head">
    <div><h2>Busiest hours</h2><p>All times <?= e(TIMEZONE) ?></p></div>
  </div>
  <div class="adm-panel-body">
    <div class="adm-chart">
      <?php foreach ($byHour as $h => $n): ?>
        <div class="bar-col" title="<?= sprintf('%02d:00', $h) ?> — <?= $n ?> views">
          <span class="bar-val"><?= $n ?: '' ?></span>
          <div class="bar-fill" style="height:<?= max(2, ($n / $peakHour) * 100) ?>%"></div>
          <span class="bar-lbl"><?= sprintf('%02d', $h) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ==================== PAGES / REFERRERS ==================== -->
<div class="adm-form-grid">

  <div class="adm-panel">
    <div class="adm-panel-head"><div><h2>Most-opened pages</h2></div></div>
    <div class="adm-panel-body flush">
      <div class="table-scroll">
        <table class="tbl">
          <thead><tr><th>Page</th><th>Views</th><th>Visitors</th></tr></thead>
          <tbody>
            <?php foreach ($pages as $p): ?>
              <tr>
                <td><code style="font-size:12px"><?= e($p['label']) ?></code></td>
                <td><strong><?= num($p['views']) ?></strong></td>
                <td class="text-dim"><?= num($p['visitors']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$pages): ?>
              <tr><td colspan="3" class="text-muted center">Nothing recorded yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="adm-panel">
    <div class="adm-panel-head">
      <div><h2>Came from</h2><p>External sites only</p></div>
    </div>
    <div class="adm-panel-body flush">
      <div class="table-scroll">
        <table class="tbl">
          <thead><tr><th>Source</th><th>Views</th><th>Visitors</th></tr></thead>
          <tbody>
            <?php foreach ($refs as $r): ?>
              <tr>
                <td><?= $r['label'] === 'Unknown'
                      ? '<span class="text-dim">Direct / typed in</span>'
                      : '<strong>' . e($r['label']) . '</strong>' ?></td>
                <td><?= num($r['views']) ?></td>
                <td class="text-dim"><?= num($r['visitors']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$refs): ?>
              <tr><td colspan="3" class="text-muted center">Nothing recorded yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ==================== LIVE FEED ==================== -->
<div class="adm-panel">
  <div class="adm-panel-head"><div><h2>Latest views</h2><p>Most recent first</p></div></div>
  <div class="adm-panel-body flush">
    <div class="table-scroll">
      <table class="tbl" style="min-width:820px">
        <thead><tr><th>When</th><th>Where</th><th>Page</th><th>Who</th>
                   <th>Device</th><th>IP</th></tr></thead>
        <tbody>
          <?php foreach ($recent as $r): ?>
            <tr>
              <td class="text-dim nowrap"><?= fdate($r['created_at'], 'd M, H:i') ?></td>
              <td>
                <?= country_flag($r['country_code']) ?>
                <?= e($r['city'] ?: ($r['country'] ?: 'Unknown')) ?>
                <?php if ($r['city'] && $r['country']): ?>
                  <span class="text-dim">&middot; <?= e($r['country']) ?></span>
                <?php endif; ?>
                <?php if ($r['geo_state'] === 'pending'): ?>
                  <span class="badge badge-muted">pending</span>
                <?php endif; ?>
              </td>
              <td><code style="font-size:12px"><?= e($r['page']) ?></code></td>
              <td><?= $r['username']
                    ? '<strong>' . e($r['username']) . '</strong>'
                    : '<span class="text-dim">guest</span>' ?>
                  <?= $r['is_new'] ? '<span class="badge badge-green">new</span>' : '' ?></td>
              <td class="text-dim"><?= e(ucfirst($r['device'])) ?>
                <span style="font-size:11px">· <?= e($r['browser']) ?></span></td>
              <td class="text-dim" style="font-size:12px"><?= e($r['ip_address']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$recent): ?>
            <tr><td colspan="6" class="text-muted center">No views recorded yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ==================== SETTINGS ==================== -->
<div class="adm-form-grid">
  <div class="adm-panel">
    <div class="adm-panel-head"><div><h2>Tracking settings</h2></div></div>
    <div class="adm-panel-body">
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="do" value="settings">

        <label class="check">
          <input type="checkbox" name="analytics_enabled" value="1"
                 <?= setting('analytics_enabled', '1') === '1' ? 'checked' : '' ?>>
          <span>Log visitors</span>
        </label>
        <label class="check">
          <input type="checkbox" name="ignore_admin" value="1"
                 <?= setting('analytics_ignore_admin', '1') === '1' ? 'checked' : '' ?>>
          <span>Skip views made while signed in to this admin panel</span>
        </label>
        <label class="check">
          <input type="checkbox" name="ignore_bots" value="1"
                 <?= setting('analytics_ignore_bots', '1') === '1' ? 'checked' : '' ?>>
          <span>Skip search-engine crawlers and bots</span>
        </label>

        <div class="form-group mt-3">
          <label class="form-label" for="geo">Location detection</label>
          <select class="form-select" id="geo" name="geo_mode">
            <?php $gm = analytics_geo_mode(); ?>
            <option value="auto"   <?= $gm === 'auto'   ? 'selected' : '' ?>>
              Auto — hosting header first, then an IP lookup (most detail)</option>
            <option value="header" <?= $gm === 'header' ? 'selected' : '' ?>>
              Header only — no outside calls, country only</option>
            <option value="off"    <?= $gm === 'off'    ? 'selected' : '' ?>>
              Off — do not record location at all</option>
          </select>
          <p class="form-hint">In Auto mode a lookup happens once per address and is
             cached forever, and it can never delay a page: anything slow is left
             pending and filled in afterwards.</p>
        </div>

        <div class="form-group">
          <label class="form-label" for="ret">Keep raw page views for (days)</label>
          <input class="form-control" type="number" min="7" max="3650" id="ret"
                 name="retention" style="max-width:140px"
                 value="<?= (int) setting('analytics_retention_days', '365') ?>">
          <p class="form-hint">Monthly and yearly totals are kept beyond this.</p>
        </div>

        <button class="btn btn-primary" type="submit">Save settings</button>
      </form>
    </div>
  </div>

  <div class="adm-panel">
    <div class="adm-panel-head"><div><h2>Housekeeping</h2></div></div>
    <div class="adm-panel-body">
      <div class="kv"><span class="k">Rows in the log</span>
        <span class="v"><?= num((int) fetch_col('SELECT COUNT(*) FROM visits', [], 0)) ?></span></div>
      <div class="kv"><span class="k">Cached IP locations</span>
        <span class="v"><?= num((int) fetch_col('SELECT COUNT(*) FROM ip_geo', [], 0)) ?></span></div>
      <div class="kv"><span class="k">Waiting on a location</span>
        <span class="v text-gold"><?= num($pending) ?></span></div>

      <hr style="border:0;border-top:1px solid var(--line);margin:16px 0">

      <form method="post" class="row" style="gap:10px;align-items:flex-end;flex-wrap:wrap">
        <?= csrf_field() ?><input type="hidden" name="do" value="purge">
        <div class="form-group" style="margin:0">
          <label class="form-label">Delete views older than (days)</label>
          <input class="form-control" type="number" min="7" max="3650" name="days"
                 value="<?= (int) setting('analytics_retention_days', '365') ?>"
                 style="width:130px">
        </div>
        <button class="btn btn-ghost btn-sm" type="submit"
                data-confirm="Delete page views older than that?">Purge</button>
      </form>

      <form method="post" class="mt-3">
        <?= csrf_field() ?><input type="hidden" name="do" value="wipe">
        <button class="btn btn-ghost btn-sm" type="submit"
                data-confirm="Delete the ENTIRE visitor log? This cannot be undone.">
          Clear the whole log</button>
      </form>
    </div>
  </div>
</div>

<style>
@media (max-width: 1180px) { .adm-split { grid-template-columns: 1fr !important; } }
</style>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
