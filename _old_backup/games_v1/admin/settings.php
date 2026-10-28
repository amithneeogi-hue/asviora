<?php
require_once __DIR__ . '/includes/auth.php';
$A = require_admin();
require_manage();

$admin_title = 'Site Settings';
$admin_sub   = 'Branding, contact details, social links and coin economy';

/**
 * Every editable setting, grouped for the form.
 * [key, label, type, placeholder, hint]
 */
$schema = [
    'General' => [
        ['site_name',        'Site name',        'text',     'Asviora Games', ''],
        ['site_tagline',     'Tagline',          'text',     'Where Skill Wins', 'Shown under the logo'],
        ['site_description', 'Meta description', 'textarea', '', 'Used for SEO and social previews. Around 155 characters is ideal.'],
    ],
    'Contact details' => [
        ['contact_email',   'Support email', 'email', 'support@asviora.com', 'Shown in the footer and on the contact page'],
        ['contact_phone',   'Phone number',  'text',  '+91 98765 43210', ''],
        ['contact_address', 'Address',       'text',  'Kolkata, West Bengal, India', ''],
    ],
    'Social links' => [
        ['social_facebook',  'Facebook page URL',  'url', 'https://www.facebook.com/asvioragames',  'Leave blank to hide the icon'],
        ['social_instagram', 'Instagram profile URL', 'url', 'https://www.instagram.com/asvioragames', 'Leave blank to hide the icon'],
        ['social_youtube',   'YouTube channel URL', 'url', 'https://www.youtube.com/@asvioragames', ''],
        ['social_whatsapp',  'WhatsApp link',      'url', 'https://chat.whatsapp.com/…', 'Group invite or wa.me link'],
        ['social_twitter',   'X / Twitter URL',    'url', 'https://x.com/asvioragames', ''],
    ],
    'Coin economy' => [
        ['signup_bonus',   'Signup bonus coins',    'number', '500', 'Credited once when an account is created'],
        ['daily_bonus',    'Daily login bonus',     'number', '50',  'Credited on the first login each day. Set 0 to disable.'],
        ['referral_bonus', 'Referral bonus coins',  'number', '100', 'Paid to both the referrer and the new player'],
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        flash('Session expired. Please save again.', 'error');
    } else {
        $errors = [];
        foreach ($schema as $fields) {
            foreach ($fields as [$key, $label, $type]) {
                $val = trim((string) ($_POST[$key] ?? ''));

                if ($type === 'url' && $val !== '' && !filter_var($val, FILTER_VALIDATE_URL)) {
                    $errors[] = $label . ' must be a full URL starting with https://';
                    continue;
                }
                if ($type === 'email' && $val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = $label . ' must be a valid email address.';
                    continue;
                }
                if ($type === 'number') {
                    $val = (string) max(0, (int) $val);
                }
                set_setting($key, $val);
            }
        }
        if ($errors) {
            foreach ($errors as $x) flash($x, 'error');
        } else {
            flash('Settings saved.');
        }
        redirect('admin/settings.php');
    }
}

require __DIR__ . '/includes/layout.php';
?>

<form method="post">
  <?= csrf_field() ?>

  <div class="adm-form-grid">
    <div>
      <?php foreach ($schema as $group => $fields): ?>
        <div class="adm-panel">
          <div class="adm-panel-head"><h2><?= e($group) ?></h2></div>
          <div class="adm-panel-body">
            <?php foreach ($fields as [$key, $label, $type, $ph, $hint]): $v = setting($key); ?>
              <div class="form-group">
                <label class="form-label" for="<?= e($key) ?>"><?= e($label) ?></label>
                <?php if ($type === 'textarea'): ?>
                  <textarea class="form-control" id="<?= e($key) ?>" name="<?= e($key) ?>"
                            style="min-height:90px" placeholder="<?= e($ph) ?>"><?= e($v) ?></textarea>
                <?php else: ?>
                  <input class="form-control"
                         type="<?= $type === 'number' ? 'number' : 'text' ?>"
                         <?= $type === 'number' ? 'min="0"' : '' ?>
                         id="<?= e($key) ?>" name="<?= e($key) ?>"
                         value="<?= e($v) ?>" placeholder="<?= e($ph) ?>">
                <?php endif; ?>
                <?php if ($hint): ?><div class="form-hint"><?= e($hint) ?></div><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div>
      <div class="adm-panel">
        <div class="adm-panel-head"><h2>Save</h2></div>
        <div class="adm-panel-body">
          <p class="text-muted" style="font-size:.88rem">
            Changes take effect immediately across the public site.</p>
          <button class="btn btn-primary btn-block btn-lg" type="submit">Save All Settings</button>
          <a href="<?= url('index.php') ?>" target="_blank" rel="noopener"
             class="btn btn-ghost btn-block mt-2">Preview the site &nearr;</a>
        </div>
      </div>

      <div class="adm-panel">
        <div class="adm-panel-head"><h2>Live preview</h2></div>
        <div class="adm-panel-body">
          <div class="row" style="gap:11px;align-items:center;margin-bottom:16px">
            <img src="<?= url('assets/img/logo.jpg') ?>" alt=""
                 style="width:42px;height:42px;border-radius:11px;object-fit:cover">
            <span>
              <strong style="display:block" class="gradient-text"><?= e(strtoupper(setting('site_name', 'Asviora Games'))) ?></strong>
              <span class="text-dim" style="letter-spacing:.2em;text-transform:uppercase;font-size:.6rem">
                <?= e(setting('site_tagline')) ?></span>
            </span>
          </div>
          <div class="kv"><span class="k">Email</span><span class="v"><?= e(setting('contact_email') ?: '—') ?></span></div>
          <div class="kv"><span class="k">Phone</span><span class="v"><?= e(setting('contact_phone') ?: '—') ?></span></div>
          <div class="kv"><span class="k">Facebook</span>
            <span class="v"><?= setting('social_facebook') ? '<span class="text-green">set</span>' : '<span class="text-dim">not set</span>' ?></span></div>
          <div class="kv"><span class="k">Instagram</span>
            <span class="v"><?= setting('social_instagram') ? '<span class="text-green">set</span>' : '<span class="text-dim">not set</span>' ?></span></div>
          <div class="kv"><span class="k">Signup bonus</span>
            <span class="v text-gold"><?= num((int) setting('signup_bonus', '0')) ?> coins</span></div>
        </div>
      </div>

      <div class="adm-panel">
        <div class="adm-panel-head"><h2>Logo</h2></div>
        <div class="adm-panel-body">
          <div class="adm-thumb" style="aspect-ratio:auto;padding:14px">
            <img src="<?= url('assets/img/logo.jpg') ?>" alt="" style="object-fit:contain">
          </div>
          <p class="text-dim" style="margin:0">
            To change the logo, replace <code>assets/img/logo.jpg</code> on the server.
            It is used in the header, footer, favicon and social previews.</p>
        </div>
      </div>
    </div>
  </div>
</form>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
