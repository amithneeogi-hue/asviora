<?php
require_once __DIR__ . '/config/config.php';

$page_title = 'Contact Us';
$page_desc  = 'Get in touch with the Asviora Games team by email, Facebook or Instagram.';

$errors = [];
$sent   = false;
$old    = ['name' => '', 'email' => '', 'subject' => '', 'message' => ''];

if ($me = current_user()) {
    $old['name']  = $me['name'];
    $old['email'] = $me['email'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* ---------- newsletter (posted from the homepage CTA too) ---------- */
    if (post('action') === 'subscribe') {
        $email = strtolower(post('email'));
        if (!csrf_ok()) {
            flash('Session expired, please try again.', 'error');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Please enter a valid email address.', 'error');
        } else {
            q('INSERT IGNORE INTO subscribers (email) VALUES (?)', [$email]);
            flash('You are on the list. We will email you when new games launch.');
        }
        redirect('contact.php');
    }

    /* ---------- contact form ---------- */
    if (!csrf_ok()) {
        $errors[] = 'Your session expired. Please submit the form again.';
    } else {
        $old['name']    = post('name');
        $old['email']   = strtolower(post('email'));
        $old['subject'] = post('subject');
        $old['message'] = post('message');

        if (mb_strlen($old['name']) < 2)   $errors[] = 'Please enter your name.';
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
        if (mb_strlen($old['message']) < 10) $errors[] = 'Please write at least a sentence or two.';
        if (post('nope') !== '')            $errors[] = 'Spam check failed.';

        // no more than 5 messages per hour from one IP
        $recent = (int) fetch_col(
            'SELECT COUNT(*) FROM contact_messages
             WHERE ip_address = ? AND created_at > (NOW() - INTERVAL 1 HOUR)',
            [client_ip()], 0);
        if ($recent >= 5) $errors[] = 'You have sent several messages already. Please wait a while.';

        if (!$errors) {
            insert_row('contact_messages', [
                'name'       => $old['name'],
                'email'      => $old['email'],
                'subject'    => $old['subject'] !== '' ? $old['subject'] : null,
                'message'    => $old['message'],
                'ip_address' => client_ip(),
            ]);
            $sent = true;
            $old  = ['name' => '', 'email' => '', 'subject' => '', 'message' => ''];
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="container">
    <div class="crumb"><a href="<?= url('index.php') ?>">Home</a> &rsaquo; Contact</div>
    <h1>Get in <span class="gradient-text">touch</span></h1>
    <p>Bug report, feature idea, partnership or just a hello &mdash; all of it reaches us.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <?= render_flash() ?>

    <div class="grid" style="grid-template-columns:1.25fr .95fr;gap:30px;align-items:start">

      <!-- ---------- form ---------- -->
      <div class="card">
        <h2 style="font-size:1.35rem">Send us a message</h2>

        <?php if ($sent): ?>
          <div class="alert alert-success" data-persist="1">
            <span>&#10003;</span>
            Thanks — your message is with us. We usually reply within one working day.
          </div>
        <?php endif; ?>
        <?php foreach ($errors as $er): ?>
          <div class="alert alert-error" data-persist="1"><span>&#9888;</span><?= e($er) ?></div>
        <?php endforeach; ?>

        <form method="post" novalidate>
          <?= csrf_field() ?>
          <div style="position:absolute;left:-9999px" aria-hidden="true">
            <input type="text" name="nope" tabindex="-1" autocomplete="off">
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="name">Your name <span class="req">*</span></label>
              <input class="form-control" id="name" name="name" value="<?= e($old['name']) ?>" required>
            </div>
            <div class="form-group">
              <label class="form-label" for="email">Email <span class="req">*</span></label>
              <input class="form-control" type="email" id="email" name="email"
                     value="<?= e($old['email']) ?>" required>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="subject">Subject</label>
            <select class="form-select" id="subject" name="subject">
              <?php foreach (['General enquiry', 'Bug report', 'Game suggestion',
                              'Account help', 'Partnership / sponsorship', 'Something else'] as $s): ?>
                <option value="<?= e($s) ?>" <?= $old['subject'] === $s ? 'selected' : '' ?>><?= e($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label" for="message">Message <span class="req">*</span></label>
            <textarea class="form-control" id="message" name="message" required
                      placeholder="Tell us what is on your mind…"><?= e($old['message']) ?></textarea>
          </div>

          <button class="btn btn-primary btn-lg" type="submit">Send Message</button>
        </form>
      </div>

      <!-- ---------- details ---------- -->
      <aside>
        <div class="card mb-3">
          <h3 style="font-size:1.1rem">Contact details</h3>

          <?php if (setting('contact_email')): ?>
            <div class="kv"><span class="k">Email</span>
              <span class="v"><a href="mailto:<?= e(setting('contact_email')) ?>"><?= e(setting('contact_email')) ?></a></span></div>
          <?php endif; ?>
          <?php if (setting('contact_phone')): ?>
            <div class="kv"><span class="k">Phone</span>
              <span class="v"><a href="tel:<?= e(preg_replace('/\s+/', '', setting('contact_phone'))) ?>"><?= e(setting('contact_phone')) ?></a></span></div>
          <?php endif; ?>
          <?php if (setting('contact_address')): ?>
            <div class="kv"><span class="k">Location</span>
              <span class="v" style="text-align:right"><?= e(setting('contact_address')) ?></span></div>
          <?php endif; ?>
          <div class="kv"><span class="k">Response time</span>
            <span class="v">Within 1 working day</span></div>
        </div>

        <div class="card mb-3">
          <h3 style="font-size:1.1rem">Follow us</h3>
          <p class="text-muted" style="font-size:.9rem">
            Launch news, weekly challenges and community highlights land here first.</p>

          <?php if (setting('social_facebook')): ?>
            <a href="<?= e(setting('social_facebook')) ?>" target="_blank" rel="noopener"
               class="row" style="gap:12px;align-items:center;padding:12px 0;border-top:1px solid var(--line-soft)">
              <span style="width:36px;height:36px;border-radius:10px;display:grid;place-items:center;
                           background:#1877f2;color:#fff;flex-shrink:0">
                <svg viewBox="0 0 24 24" width="17" height="17" fill="currentColor"><path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5 3.66 9.15 8.44 9.94v-7.03H7.9v-2.91h2.54V9.85c0-2.51 1.49-3.9 3.77-3.9 1.09 0 2.23.2 2.23.2v2.46h-1.26c-1.24 0-1.63.78-1.63 1.57v1.88h2.78l-.45 2.91h-2.33V22c4.78-.79 8.45-4.94 8.45-9.94z"/></svg>
              </span>
              <span>
                <span style="display:block;font-weight:700;color:var(--text)">Facebook</span>
                <span class="text-dim"><?= e(preg_replace('#^https?://(www\.)?#', '', setting('social_facebook'))) ?></span>
              </span>
            </a>
          <?php endif; ?>

          <?php if (setting('social_instagram')): ?>
            <a href="<?= e(setting('social_instagram')) ?>" target="_blank" rel="noopener"
               class="row" style="gap:12px;align-items:center;padding:12px 0;border-top:1px solid var(--line-soft)">
              <span style="width:36px;height:36px;border-radius:10px;display:grid;place-items:center;
                           background:linear-gradient(45deg,#f09433,#dc2743,#bc1888);color:#fff;flex-shrink:0">
                <svg viewBox="0 0 24 24" width="17" height="17" fill="currentColor"><path d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.96.24 2.66.51.72.28 1.33.66 1.94 1.27.61.61.99 1.22 1.27 1.94.27.7.46 1.49.51 2.66.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.24 1.96-.51 2.66a5.4 5.4 0 0 1-1.27 1.94 5.4 5.4 0 0 1-1.94 1.27c-.7.27-1.49.46-2.66.51-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.96-.24-2.66-.51a5.4 5.4 0 0 1-1.94-1.27 5.4 5.4 0 0 1-1.27-1.94c-.27-.7-.46-1.49-.51-2.66C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.24-1.96.51-2.66.28-.72.66-1.33 1.27-1.94A5.4 5.4 0 0 1 5.95 1.28c.7-.27 1.49-.46 2.66-.51C9.88 2.17 10.26 2.16 12 2.16zM12 6.87a5.13 5.13 0 1 1 0 10.26 5.13 5.13 0 0 1 0-10.26zm0 8.46a3.33 3.33 0 1 0 0-6.66 3.33 3.33 0 0 0 0 6.66zm6.54-8.68a1.2 1.2 0 1 1-2.4 0 1.2 1.2 0 0 1 2.4 0z"/></svg>
              </span>
              <span>
                <span style="display:block;font-weight:700;color:var(--text)">Instagram</span>
                <span class="text-dim"><?= e(preg_replace('#^https?://(www\.)?#', '', setting('social_instagram'))) ?></span>
              </span>
            </a>
          <?php endif; ?>

          <?php if (setting('social_youtube')): ?>
            <a href="<?= e(setting('social_youtube')) ?>" target="_blank" rel="noopener"
               class="row" style="gap:12px;align-items:center;padding:12px 0;border-top:1px solid var(--line-soft)">
              <span style="width:36px;height:36px;border-radius:10px;display:grid;place-items:center;
                           background:#f00;color:#fff;flex-shrink:0">&#9654;</span>
              <span><span style="display:block;font-weight:700;color:var(--text)">YouTube</span>
                <span class="text-dim">Watch our channel</span></span>
            </a>
          <?php endif; ?>

          <?php if (setting('social_whatsapp')): ?>
            <a href="<?= e(setting('social_whatsapp')) ?>" target="_blank" rel="noopener"
               class="row" style="gap:12px;align-items:center;padding:12px 0;border-top:1px solid var(--line-soft)">
              <span style="width:36px;height:36px;border-radius:10px;display:grid;place-items:center;
                           background:#25d366;color:#fff;flex-shrink:0">&#128172;</span>
              <span><span style="display:block;font-weight:700;color:var(--text)">WhatsApp</span>
                <span class="text-dim">Join the community</span></span>
            </a>
          <?php endif; ?>
        </div>

        <div class="card">
          <h3 style="font-size:1.1rem">Newsletter</h3>
          <p class="text-muted" style="font-size:.9rem">One short email when a new game goes live.
            No spam, unsubscribe any time.</p>
          <form method="post" class="subscribe" style="max-width:none;margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="subscribe">
            <input type="email" name="email" class="form-control"
                   placeholder="you@email.com" required aria-label="Newsletter email">
            <button class="btn btn-gold" type="submit">Join</button>
          </form>
        </div>
      </aside>

    </div>
  </div>
</section>

<style>
@media (max-width: 900px) {
  section.section > .container > .grid[style*="1.25fr"] { grid-template-columns: 1fr !important; }
}
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
