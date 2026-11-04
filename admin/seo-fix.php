<?php
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();
require_manage();

$admin_title = 'SEO Quick Fix';
$admin_sub   = 'Safe live setting fixes';

$fix = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    if (post('do') === 'fix') {
        $changed = [];

        if ((string) setting('seo_public_catalogue', '0') !== '1') {
            set_setting('seo_public_catalogue', '1');
            $changed[] = 'seo_public_catalogue => 1';
        }

        if ((string) setting('seo_noindex_site', '0') !== '0') {
            set_setting('seo_noindex_site', '0');
            $changed[] = 'seo_noindex_site => 0';
        }

        if ((string) setting('seo_org_name') === '') {
            set_setting('seo_org_name', 'Asviora Games');
            $changed[] = 'seo_org_name => Asviora Games';
        }

        $ogValue = (string) setting('seo_og_image');
        $ogUrl = $ogValue !== '' ? seo_image_url($ogValue) : '';
        if ($ogValue !== '' && $ogUrl === '') {
            set_setting('seo_og_image', '');
            $changed[] = 'seo_og_image => cleared (missing file)';
        }

        $fix = $changed;
        flash($fix ? 'SEO quick fix applied.' : 'SEO quick fix skipped - already correct.', $fix ? 'success' : 'info');
        redirect('admin/seo.php?tab=global');
    }
}

$wantPublic = (string) setting('seo_public_catalogue', '0') !== '1';
$wantNoindex = (string) setting('seo_noindex_site', '0') !== '0';
$wantOrg = (string) setting('seo_org_name') === '';
$ogValue = (string) setting('seo_og_image');
$ogUrl = $ogValue !== '' ? seo_image_url($ogValue) : '';
$ogMissing = $ogValue !== '' && $ogUrl === '';

require __DIR__ . '/includes/layout.php';
?>
<?php if ($fix): ?>
  <div class="adm-panel"><div class="adm-panel-body">
    <div class="empty"><div class="em-ico">&#9989;</div>
      <h3>SEO quick fix applied</h3>
      <p>The following settings were updated:</p>
      <?php if ($fix): foreach ($fix as $f): ?><div>&bull; <?= e($f) ?></div><?php endforeach; endif; ?>
      <a class="btn btn-primary" href="<?= admin_url('seo.php?tab=global') ?>">Back to SEO Manager</a>
    </div>
  </div></div>
<?php else: ?>
  <div class="adm-panel"><div class="adm-panel-head"><div><h2>Safe SEO settings</h2>
      <p>Apply the remaining safe live fixes from the SEO Manager.</p></div></div>
    <div class="adm-panel-body">
      <div class="kv" style="display:grid;gap:10px;margin-bottom:14px">
        <div class="kv"><span class="k">All Games visible to guests</span>
          <span class="v <?= $wantPublic ? 'text-green' : 'text-gold' ?>"><?= $wantPublic ? 'On' : 'Off' ?></span></div>
        <div class="kv"><span class="k">Whole-site noindex</span>
          <span class="v <?= $wantNoindex ? 'text-green' : 'text-gold' ?>"><?= $wantNoindex ? 'On' : 'Off' ?></span></div>
        <div class="kv"><span class="k">Organisation name</span>
          <span class="v"><?= e((string) setting('seo_org_name')) ?></span></div>
        <div class="kv"><span class="k">Default share image</span>
          <span class="v"><?= e((string) setting('seo_og_image')) ?></span></div>
      </div>
      <?php if ($wantPublic || $wantNoindex || $wantOrg || $ogMissing): ?>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="do" value="fix">
          <button class="btn btn-primary" type="submit">Apply safe SEO fixes</button>
        </form>
      <?php else: ?>
        <div class="text-green">✓ All safe SEO settings are already correct.</div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/layout_end.php'; ?>
