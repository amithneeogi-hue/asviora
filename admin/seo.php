<?php
/**
 * Admin — SEO Manager  (v7)
 *
 *   Pages    every public page, game and blog post with an SEO score;
 *            edit title / description / keywords / share image / robots,
 *            with a live Google preview and an "AI suggest" button
 *   Global   title format, default keywords + share image, Search Console
 *            and Bing verification, Analytics / Tag Manager, robots.txt
 *   AI       the Claude API key, the model, and what to focus on
 *
 * /sitemap.xml and /robots.txt are generated from the same settings.
 */
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();
require_manage();

$admin_title = 'SEO Manager';
$admin_sub   = 'Titles, descriptions, keywords, sitemap and AI suggestions';

$installed = has_table('seo_pages');
$tab  = get('tab', 'pages');
$edit = get('edit');

$allPages = $installed ? (seo_static_pages() + seo_dynamic_pages()) : [];

/* ================================================================
   ACTIONS
   ================================================================ */
$aiResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok() && $installed) {
    $do = post('do');

    // ---- one page ---------------------------------------------------
    if ($do === 'page' && isset($allPages[post('key')])) {
        $key = post('key');
        $img = save_upload('og_file', 'seo');
        $cur = seo_override($key);
        $robots = in_array(post('robots'), ['', 'noindex,follow', 'noindex,nofollow', 'index,follow'], true)
                ? post('robots') : '';
        q('INSERT INTO seo_pages (page_key, title, description, keywords, og_image, canonical, robots, ai_generated)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description),
             keywords = VALUES(keywords), og_image = VALUES(og_image), canonical = VALUES(canonical),
             robots = VALUES(robots), ai_generated = VALUES(ai_generated)',
          [$key,
           mb_substr(post('title'), 0, 190) ?: null,
           mb_substr(post('description'), 0, 400) ?: null,
           mb_substr(post('keywords'), 0, 400) ?: null,
           $img ?: (!empty($_POST['og_clear']) ? null : ($cur['og_image'] ?? null)),
           filter_var(post('canonical'), FILTER_VALIDATE_URL) ? post('canonical') : null,
           $robots ?: null,
           !empty($_POST['ai_generated']) ? 1 : 0]);
        flash('SEO saved for “' . $allPages[$key]['label'] . '”.');
        redirect('admin/seo.php?edit=' . urlencode($key));
    }

    if ($do === 'reset' && isset($allPages[post('key')])) {
        q('DELETE FROM seo_pages WHERE page_key = ?', [post('key')]);
        flash('Back to the page’s own text.', 'info');
        redirect('admin/seo.php');
    }

    // ---- AI suggestion for one page (fills the form, does not save) --
    if ($do === 'ai' && isset($allPages[post('key')])) {
        $key = post('key');
        $edit = $key;
        $def = seo_default_meta($key);
        $aiResult = seo_ai_suggest($allPages[$key]['label'], $allPages[$key]['url'], [
            'title'       => post('title') ?: $def['title'],
            'description' => post('description') ?: $def['desc'],
            'keywords'    => post('keywords'),
        ], $def['desc']);
        if (!$aiResult['ok']) {
            flash($aiResult['error'], 'error');
            redirect('admin/seo.php?edit=' . urlencode($key));
        }
        // fall through: the edit form below shows the suggestion, unsaved
    }

    // ---- AI for every page that has nothing saved yet ----------------
    if ($do === 'ai_bulk') {
        $done = 0; $fail = '';
        foreach ($allPages as $key => $pg) {
            if ($done >= 6) {
                break;                          // keep one click under ~1 minute
            }
            if (seo_override($key) || in_array($key, ['login', 'register'], true)) {
                continue;
            }
            $def = seo_default_meta($key);
            $r = seo_ai_suggest($pg['label'], $pg['url'], ['title' => $def['title'],
                                'description' => $def['desc'], 'keywords' => ''], $def['desc']);
            if (!$r['ok']) {
                $fail = $r['error'];
                break;
            }
            q('INSERT INTO seo_pages (page_key, title, description, keywords, ai_generated)
               VALUES (?, ?, ?, ?, 1)
               ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description),
                 keywords = VALUES(keywords), ai_generated = 1',
              [$key, $r['title'], $r['description'], $r['keywords']]);
            $done++;
        }
        $fail !== '' ? flash($fail, 'error') : null;
        flash($done ? ('AI wrote SEO for ' . $done . ' page(s). Review them below — click again for the next batch.')
                    : 'Every page already has SEO saved.', $done ? 'success' : 'info');
        redirect('admin/seo.php');
    }

    // ---- global -----------------------------------------------------
    if ($do === 'global') {
        foreach (['seo_title_format' => 120, 'seo_home_title' => 120, 'seo_keywords' => 400,
                  'seo_twitter' => 60, 'seo_google_verify' => 120, 'seo_bing_verify' => 120,
                  'seo_ga4_id' => 30, 'seo_gtm_id' => 30, 'seo_org_name' => 120] as $k => $len) {
            set_setting($k, mb_substr(post($k), 0, $len));
        }
        set_setting('site_description', mb_substr(post('site_description'), 0, 300));
        set_setting('seo_robots_extra', mb_substr((string) ($_POST['seo_robots_extra'] ?? ''), 0, 2000));
        set_setting('seo_noindex_site', empty($_POST['seo_noindex_site']) ? '0' : '1');
        set_setting('seo_public_catalogue', empty($_POST['seo_public_catalogue']) ? '0' : '1');
        if ($f = save_upload('og_default', 'seo')) {
            set_setting('seo_og_image', $f);
        }
        flash('Global SEO settings saved.');
        redirect('admin/seo.php?tab=global');
    }

    // ---- AI settings ------------------------------------------------
    if ($do === 'aiset') {
        if (post('seo_ai_key') !== '') {
            set_setting('seo_ai_key', post('seo_ai_key'));
        }
        if (!empty($_POST['ai_forget'])) {
            set_setting('seo_ai_key', '');
        }
        set_setting('seo_ai_model', preg_replace('/[^a-z0-9.\-]/i', '', post('seo_ai_model')) ?: 'claude-sonnet-4-5');
        set_setting('seo_ai_focus', mb_substr(post('seo_ai_focus'), 0, 300));
        flash('AI settings saved.');
        redirect('admin/seo.php?tab=ai');
    }

    if ($do === 'aitest') {
        $r = seo_ai_suggest('Home page', url(''), ['title' => setting('site_name'),
                            'description' => setting('site_description')]);
        $r['ok'] ? flash('Claude answered: “' . $r['title'] . '”') : flash($r['error'], 'error');
        redirect('admin/seo.php?tab=ai');
    }
}

/* ================================================================
   DATA
   ================================================================ */
$rows = [];
if ($installed && $tab === 'pages' && $edit === '') {
    foreach ($allPages as $key => $pg) {
        $def = seo_default_meta($key);
        $s   = seo_resolve(['key' => $key, 'title' => $def['title'], 'desc' => $def['desc']]);
        [$score, $notes] = seo_audit($s);
        $rows[$key] = $pg + ['s' => $s, 'score' => $score, 'notes' => $notes, 'o' => seo_override($key)];
    }
}
$avg = $rows ? (int) round(array_sum(array_column($rows, 'score')) / count($rows)) : 0;

require __DIR__ . '/includes/layout.php';
?>

<?php if (!$installed): ?>
  <div class="adm-panel"><div class="adm-panel-body"><div class="empty"><div class="em-ico">&#128269;</div>
    <h3>Not installed yet</h3><p>Open any page of the site once (or run <code>v7migration.php</code>).</p></div></div></div>
<?php require __DIR__ . '/includes/layout_end.php'; exit; endif; ?>

<div class="tabs" style="margin-bottom:18px">
  <a href="<?= admin_url('seo.php') ?>" class="<?= $tab === 'pages' ? 'active' : '' ?>">Pages</a>
  <a href="<?= admin_url('seo.php?tab=global') ?>" class="<?= $tab === 'global' ? 'active' : '' ?>">Global</a>
  <a href="<?= admin_url('seo.php?tab=ai') ?>" class="<?= $tab === 'ai' ? 'active' : '' ?>">AI assistant
    <span class="tab-n"><?= seo_ai_ready() ? 'on' : 'off' ?></span></a>
  <a href="<?= url('sitemap.xml') ?>" target="_blank" rel="noopener">sitemap.xml &nearr;</a>
  <a href="<?= url('robots.txt') ?>" target="_blank" rel="noopener">robots.txt &nearr;</a>
</div>

<?php if ($tab === 'pages' && $edit !== '' && isset($allPages[$edit])):
  /* =========================== EDIT ONE PAGE =========================== */
  $pg  = $allPages[$edit];
  $o   = seo_override($edit);
  $def = seo_default_meta($edit);
  $val = [
      'title'       => $aiResult['title'] ?? ($o['title'] ?? ''),
      'description' => $aiResult['description'] ?? ($o['description'] ?? ''),
      'keywords'    => $aiResult['keywords'] ?? ($o['keywords'] ?? ''),
  ];
  $preview = seo_resolve(['key' => $edit, 'title' => $def['title'], 'desc' => $def['desc']]);
  $autoTitle = ($edit === 'home' || $def['title'] === '')
      ? (setting('seo_home_title') ?: setting('site_name') . ' — ' . setting('site_tagline'))
      : strtr(setting('seo_title_format', '{page} | {site}') ?: '{page} | {site}',
              ['{page}' => $def['title'], '{site}' => setting('site_name'), '{tagline}' => setting('site_tagline')]);
?>

<p><a href="<?= admin_url('seo.php') ?>">&larr; All pages</a></p>

<?php if ($aiResult && $aiResult['ok']): ?>
  <div class="alert alert-success" data-persist="1"><span>&#10024;</span>
    <span>AI suggestion loaded into the form below. It is <strong>not saved yet</strong> — check it, edit if you like, then press Save.</span></div>
<?php endif; ?>

<div class="adm-form-grid">
  <div class="adm-panel">
    <div class="adm-panel-head"><div><h2><?= e($pg['label']) ?></h2>
      <p><a href="<?= e($pg['url']) ?>" target="_blank" rel="noopener"><?= e($pg['url']) ?></a></p></div></div>
    <div class="adm-panel-body">
      <form method="post" enctype="multipart/form-data" id="seoForm">
        <?= csrf_field() ?><input type="hidden" name="key" value="<?= e($edit) ?>">
        <input type="hidden" name="ai_generated" value="<?= $aiResult ? '1' : (int) ($o['ai_generated'] ?? 0) ?>">

        <div class="form-group">
          <label class="form-label" for="title">SEO title <span class="text-dim" id="tLen"></span></label>
          <input class="form-control" id="title" name="title" maxlength="190" value="<?= e($val['title']) ?>"
                 placeholder="<?= e($autoTitle) ?>">
          <p class="form-hint">Blank = automatic: “<?= e($autoTitle) ?>”. Best 45–60 characters, main keyword first.</p>
        </div>
        <div class="form-group">
          <label class="form-label" for="description">Meta description <span class="text-dim" id="dLen"></span></label>
          <textarea class="form-control" id="description" name="description" rows="3" maxlength="400"
                    placeholder="<?= e($def['desc']) ?>"><?= e($val['description']) ?></textarea>
          <p class="form-hint">Best 140–160 characters, with a reason to click.</p>
        </div>
        <div class="form-group">
          <label class="form-label" for="keywords">Keywords <span class="text-dim">(comma separated, most important first)</span></label>
          <input class="form-control" id="keywords" name="keywords" maxlength="400" value="<?= e($val['keywords']) ?>"
                 placeholder="<?= e(setting('seo_keywords')) ?>">
        </div>
        <div class="row" style="gap:12px;flex-wrap:wrap">
          <div class="form-group" style="flex:1;min-width:200px">
            <label class="form-label" for="robots">Search engines</label>
            <select class="form-select" id="robots" name="robots">
              <?php foreach (['' => 'Automatic', 'index,follow' => 'Show in search (index)',
                              'noindex,follow' => 'Hide page, follow links', 'noindex,nofollow' => 'Hide completely'] as $k => $l): ?>
                <option value="<?= e($k) ?>" <?= ($o['robots'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex:2;min-width:240px">
            <label class="form-label" for="canonical">Canonical URL <span class="text-dim">(optional)</span></label>
            <input class="form-control" id="canonical" name="canonical" value="<?= e($o['canonical'] ?? '') ?>"
                   placeholder="<?= e($pg['url']) ?>">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label" for="og_file">Share image (Facebook / WhatsApp / X) <span class="text-dim">1200×630 best</span></label>
          <?php if (!empty($o['og_image'])): ?>
            <div class="row" style="gap:10px;align-items:center;margin-bottom:8px">
              <img src="<?= e(seo_image_url($o['og_image'])) ?>" alt="" style="width:140px;border-radius:8px">
              <label class="check"><input type="checkbox" name="og_clear" value="1"><span>Remove</span></label>
            </div>
          <?php endif; ?>
          <input class="form-control" type="file" id="og_file" name="og_file" accept="image/*">
        </div>

        <div class="row" style="gap:10px;flex-wrap:wrap">
          <button class="btn btn-primary" type="submit" name="do" value="page">Save</button>
          <button class="btn btn-gold" type="submit" name="do" value="ai" <?= seo_ai_ready() ? '' : 'disabled title="Add a Claude API key in the AI tab"' ?>
                  onclick="this.textContent='Asking Claude…'">&#10024; AI suggest</button>
          <?php if ($o): ?>
            <button class="btn btn-ghost" type="submit" name="do" value="reset" data-confirm="Remove the saved SEO for this page?">Reset to automatic</button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <div class="adm-panel">
    <div class="adm-panel-head"><div><h2>Google preview</h2><p>Roughly how the result will look</p></div></div>
    <div class="adm-panel-body">
      <div class="serp">
        <div class="serp-url"><?= e(preg_replace('#^https?://#', '', $pg['url'])) ?></div>
        <div class="serp-title" id="pvTitle"><?= e($val['title'] ?: $preview['title']) ?></div>
        <div class="serp-desc" id="pvDesc"><?= e($val['description'] ?: $preview['description']) ?></div>
      </div>
      <?php [$sc, $notes] = seo_audit(array_merge($preview, array_filter([
                'title' => $val['title'], 'description' => $val['description'], 'keywords' => $val['keywords']])));
      ?>
      <div class="kv mt-3"><span class="k">SEO score</span>
        <span class="v <?= $sc >= 80 ? 'text-green' : ($sc >= 55 ? 'text-gold' : '') ?>"><?= $sc ?>/100</span></div>
      <?php foreach ($notes as $n): ?><div class="text-dim" style="font-size:.85rem;margin-top:6px">• <?= e($n) ?></div><?php endforeach; ?>
      <?php if (!$notes): ?><div class="text-green" style="font-size:.85rem;margin-top:6px">✓ Looks good.</div><?php endif; ?>
    </div>
  </div>
</div>

<script>
(function () {
  var t = document.getElementById('title'), d = document.getElementById('description');
  var pt = document.getElementById('pvTitle'), pd = document.getElementById('pvDesc');
  var tl = document.getElementById('tLen'), dl = document.getElementById('dLen');
  var t0 = pt.textContent, d0 = pd.textContent;
  function upd() {
    pt.textContent = t.value || t0; pd.textContent = d.value || d0;
    tl.textContent = '(' + (t.value || t0).length + ')'; dl.textContent = '(' + (d.value || d0).length + ')';
  }
  t.addEventListener('input', upd); d.addEventListener('input', upd); upd();
})();
</script>

<?php elseif ($tab === 'global'): /* =========================== GLOBAL =========================== */ ?>

<form method="post" enctype="multipart/form-data">
  <?= csrf_field() ?><input type="hidden" name="do" value="global">
  <div class="adm-form-grid">
    <div class="adm-panel">
      <div class="adm-panel-head"><div><h2>Defaults</h2><p>Used wherever a page has nothing of its own</p></div></div>
      <div class="adm-panel-body">
        <div class="form-group"><label class="form-label" for="seo_home_title">Home page title</label>
          <input class="form-control" id="seo_home_title" name="seo_home_title" maxlength="120"
                 value="<?= e(setting('seo_home_title')) ?>"
                 placeholder="<?= e(setting('site_name') . ' — ' . setting('site_tagline')) ?>"></div>
        <div class="form-group"><label class="form-label" for="seo_title_format">Title format for other pages</label>
          <input class="form-control" id="seo_title_format" name="seo_title_format" maxlength="120"
                 value="<?= e(setting('seo_title_format', '{page} | {site}')) ?>">
          <p class="form-hint"><code>{page}</code> page name · <code>{site}</code> site name · <code>{tagline}</code></p></div>
        <div class="form-group"><label class="form-label" for="site_description">Default meta description</label>
          <textarea class="form-control" id="site_description" name="site_description" rows="3" maxlength="300"><?= e(setting('site_description')) ?></textarea></div>
        <div class="form-group"><label class="form-label" for="seo_keywords">Default keywords</label>
          <textarea class="form-control" id="seo_keywords" name="seo_keywords" rows="2" maxlength="400"><?= e(setting('seo_keywords')) ?></textarea></div>
        <div class="form-group"><label class="form-label" for="og_default">Default share image</label>
          <?php if (setting('seo_og_image') !== ''): ?>
            <img src="<?= e(seo_image_url(setting('seo_og_image'))) ?>" alt="" style="width:160px;border-radius:8px;display:block;margin-bottom:8px">
          <?php endif; ?>
          <input class="form-control" type="file" id="og_default" name="og_default" accept="image/*">
          <p class="form-hint">Blank = the site logo.</p></div>
        <div class="form-group"><label class="form-label" for="seo_org_name">Organisation name (structured data)</label>
          <input class="form-control" id="seo_org_name" name="seo_org_name" maxlength="120"
                 value="<?= e(setting('seo_org_name')) ?>" placeholder="<?= e(setting('site_name')) ?>"></div>
      </div>
    </div>

    <div class="adm-panel">
      <div class="adm-panel-head"><div><h2>Search Console &amp; analytics</h2></div></div>
      <div class="adm-panel-body">
        <div class="form-group"><label class="form-label" for="seo_google_verify">Google Search Console — verification code</label>
          <input class="form-control" id="seo_google_verify" name="seo_google_verify" value="<?= e(setting('seo_google_verify')) ?>"
                 placeholder="only the content=&quot;…&quot; part">
          <p class="form-hint">Then submit <code><?= e(url('sitemap.xml')) ?></code> in Search Console → Sitemaps.</p></div>
        <div class="form-group"><label class="form-label" for="seo_bing_verify">Bing Webmaster — verification code</label>
          <input class="form-control" id="seo_bing_verify" name="seo_bing_verify" value="<?= e(setting('seo_bing_verify')) ?>"></div>
        <div class="row" style="gap:12px">
          <div class="form-group" style="flex:1"><label class="form-label" for="seo_ga4_id">Google Analytics 4 ID</label>
            <input class="form-control" id="seo_ga4_id" name="seo_ga4_id" value="<?= e(setting('seo_ga4_id')) ?>" placeholder="G-XXXXXXX"></div>
          <div class="form-group" style="flex:1"><label class="form-label" for="seo_gtm_id">Tag Manager ID</label>
            <input class="form-control" id="seo_gtm_id" name="seo_gtm_id" value="<?= e(setting('seo_gtm_id')) ?>" placeholder="GTM-XXXXXX"></div>
        </div>
        <div class="form-group"><label class="form-label" for="seo_twitter">X / Twitter handle</label>
          <input class="form-control" id="seo_twitter" name="seo_twitter" value="<?= e(setting('seo_twitter')) ?>" placeholder="@asviora"></div>

        <label class="check"><input type="checkbox" name="seo_public_catalogue" value="1" <?= setting('seo_public_catalogue') === '1' ? 'checked' : '' ?>>
          <span>Let visitors (and Google) see the <strong>All Games</strong> page without logging in
            <span class="text-dim">— playing still needs an account. Recommended for SEO.</span></span></label>
        <label class="check"><input type="checkbox" name="seo_noindex_site" value="1" <?= setting('seo_noindex_site') === '1' ? 'checked' : '' ?>>
          <span class="text-gold">Hide the whole site from search engines (staging / maintenance only)</span></label>

        <div class="form-group mt-2"><label class="form-label" for="seo_robots_extra">Extra robots.txt lines</label>
          <textarea class="form-control" id="seo_robots_extra" name="seo_robots_extra" rows="3"
                    style="font-family:monospace"><?= e(setting('seo_robots_extra')) ?></textarea></div>
      </div>
    </div>
  </div>
  <button class="btn btn-primary" type="submit">Save global settings</button>
</form>

<?php elseif ($tab === 'ai'): /* =========================== AI =========================== */ ?>

<div class="adm-form-grid">
  <div class="adm-panel">
    <div class="adm-panel-head"><div><h2>&#10024; Claude AI assistant</h2>
      <p>Writes titles, descriptions and keywords. Nothing goes live until you save.</p></div></div>
    <div class="adm-panel-body">
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="do" value="aiset">
        <div class="form-group"><label class="form-label" for="seo_ai_key">Claude API key</label>
          <input class="form-control" type="password" id="seo_ai_key" name="seo_ai_key" autocomplete="new-password"
                 placeholder="<?= setting('seo_ai_key') !== '' ? '•••••••• saved — leave blank to keep' : 'sk-ant-…' ?>">
          <p class="form-hint">From console.anthropic.com → API Keys. Stored on your server only.</p>
          <?php if (setting('seo_ai_key') !== ''): ?>
            <label class="check"><input type="checkbox" name="ai_forget" value="1"><span>Remove the saved key</span></label>
          <?php endif; ?></div>
        <div class="form-group"><label class="form-label" for="seo_ai_model">Model</label>
          <input class="form-control" id="seo_ai_model" name="seo_ai_model" value="<?= e(setting('seo_ai_model', 'claude-sonnet-4-5')) ?>">
          <p class="form-hint">Any Claude model id your key can use.</p></div>
        <div class="form-group"><label class="form-label" for="seo_ai_focus">Focus topics / target audience</label>
          <input class="form-control" id="seo_ai_focus" name="seo_ai_focus" maxlength="300" value="<?= e(setting('seo_ai_focus')) ?>"></div>
        <button class="btn btn-primary" type="submit">Save</button>
      </form>
    </div>
  </div>
  <div class="adm-panel">
    <div class="adm-panel-head"><div><h2>Use it</h2></div></div>
    <div class="adm-panel-body">
      <p class="text-muted">On any page in the <strong>Pages</strong> tab press <strong>AI suggest</strong> —
        the form fills in, you review and save. Or let it write every page that has nothing saved yet, six at a time:</p>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="ai_bulk">
        <button class="btn btn-gold" type="submit" <?= seo_ai_ready() ? '' : 'disabled' ?>
                onclick="this.textContent='Working… (up to a minute)'">&#10024; AI-write empty pages</button></form>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="aitest">
        <button class="btn btn-ghost" type="submit" <?= seo_ai_ready() ? '' : 'disabled' ?>>Test the key</button></form>
    </div>
  </div>
</div>

<?php else: /* =========================== PAGE LIST =========================== */ ?>

<div class="seo-stats">
  <div class="card stat-card"><div class="sc-num <?= $avg >= 80 ? 'text-green' : 'text-gold' ?>"><?= $avg ?>/100</div><div class="sc-lbl">Average SEO score</div></div>
  <div class="card stat-card"><div class="sc-num"><?= num(count($rows)) ?></div><div class="sc-lbl">Pages tracked</div></div>
  <div class="card stat-card"><div class="sc-num"><?= num(count(array_filter($rows, fn($r) => $r['o']))) ?></div><div class="sc-lbl">Custom SEO saved</div></div>
  <div class="card stat-card"><div class="sc-num"><?= num(count(array_filter($rows, fn($r) => $r['score'] < 70))) ?></div><div class="sc-lbl">Need attention</div></div>
</div>

<?php if (seo_ai_ready()): ?>
  <form method="post" style="margin-bottom:14px"><?= csrf_field() ?><input type="hidden" name="do" value="ai_bulk">
    <button class="btn btn-gold btn-sm" type="submit" onclick="this.textContent='Working… (up to a minute)'">&#10024; AI-write pages with no SEO yet</button></form>
<?php endif; ?>

<div class="adm-panel">
  <div class="adm-panel-body flush">
    <div class="table-scroll">
      <table class="tbl" style="min-width:900px">
        <thead><tr><th>Page</th><th>Title in Google</th><th>Score</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $key => $r): ?>
          <tr>
            <td><strong><?= e($r['label']) ?></strong>
              <?php if ($r['o']): ?><span class="badge badge-green"><?= !empty($r['o']['ai_generated']) ? 'AI' : 'custom' ?></span><?php endif; ?>
              <div class="text-dim" style="font-size:.75rem"><?= e(preg_replace('#^https?://[^/]+#', '', $r['url'])) ?></div></td>
            <td style="max-width:420px"><div style="font-weight:600"><?= e($r['s']['title']) ?></div>
              <div class="text-dim" style="font-size:.8rem"><?= e(excerpt($r['s']['description'], 120)) ?></div></td>
            <td><span class="badge <?= $r['score'] >= 80 ? 'badge-green' : ($r['score'] >= 55 ? 'badge-gold' : 'badge-red') ?>"
                      title="<?= e(implode("\n", $r['notes'])) ?>"><?= $r['score'] ?></span></td>
            <td><a class="btn btn-ghost btn-xs" href="<?= admin_url('seo.php?edit=' . urlencode($key)) ?>">Edit</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<p class="text-dim" style="font-size:.85rem">Login, dashboard, payment and in-game pages are kept out of search automatically.
  Pages behind the login (games, quiz, spin, chess) only rank once you make them public — see Global.</p>

<?php endif; ?>

<style>
.seo-stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; margin-bottom:18px; }
@media (max-width: 1000px) { .seo-stats { grid-template-columns:repeat(2,minmax(0,1fr)); } }
.serp { background:#fff; border-radius:12px; padding:16px 18px; font-family:Arial, sans-serif; }
.serp-url { color:#202124; font-size:13px; }
.serp-title { color:#1a0dab; font-size:19px; line-height:1.3; margin:4px 0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.serp-desc { color:#4d5156; font-size:13.5px; line-height:1.55; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
</style>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
