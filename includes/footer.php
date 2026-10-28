<?php
/**
 * Public site footer.
 */
$fbUrl   = setting('social_facebook');
$igUrl   = setting('social_instagram');
$ytUrl   = setting('social_youtube');
$waUrl   = setting('social_whatsapp');
$twUrl   = setting('social_twitter');
$siteNm  = setting('site_name', 'Asviora Games');
// everything playable — the three games plus the quiz and the spin
$footGames = array_slice(arena_items(), 0, 6);
?>
</main>

<footer class="site-footer">
  <div class="container">
    <div class="foot-grid">

      <div class="foot-col foot-about">
        <a href="<?= url('index.php') ?>" class="brand" style="margin-bottom:16px">
          <img src="<?= e(logo_icon(192)) ?>" alt="<?= e($siteNm) ?>">
          <span class="brand-txt">
            <span class="brand-name"><?= e(strtoupper($siteNm)) ?></span>
            <span class="brand-sub"><?= e(setting('site_tagline', 'Where Skill Wins')) ?></span>
          </span>
        </a>
        <p><?= e(setting('site_description')) ?></p>

        <div class="socials">
          <?php if ($fbUrl): ?>
          <a href="<?= e($fbUrl) ?>" class="fb" target="_blank" rel="noopener" aria-label="Facebook" title="Facebook">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5 3.66 9.15 8.44 9.94v-7.03H7.9v-2.91h2.54V9.85c0-2.51 1.49-3.9 3.77-3.9 1.09 0 2.23.2 2.23.2v2.46h-1.26c-1.24 0-1.63.78-1.63 1.57v1.88h2.78l-.45 2.91h-2.33V22c4.78-.79 8.45-4.94 8.45-9.94z"/></svg>
          </a>
          <?php endif; ?>
          <?php if ($igUrl): ?>
          <a href="<?= e($igUrl) ?>" class="ig" target="_blank" rel="noopener" aria-label="Instagram" title="Instagram">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.96.24 2.66.51.72.28 1.33.66 1.94 1.27.61.61.99 1.22 1.27 1.94.27.7.46 1.49.51 2.66.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.24 1.96-.51 2.66a5.4 5.4 0 0 1-1.27 1.94 5.4 5.4 0 0 1-1.94 1.27c-.7.27-1.49.46-2.66.51-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.96-.24-2.66-.51a5.4 5.4 0 0 1-1.94-1.27 5.4 5.4 0 0 1-1.27-1.94c-.27-.7-.46-1.49-.51-2.66C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.24-1.96.51-2.66.28-.72.66-1.33 1.27-1.94A5.4 5.4 0 0 1 5.95 1.28c.7-.27 1.49-.46 2.66-.51C9.88 2.17 10.26 2.16 12 2.16zm0 1.96c-3.15 0-3.5.01-4.74.07-.95.04-1.47.2-1.81.34-.46.18-.79.39-1.13.74-.35.34-.56.67-.74 1.13-.14.34-.3.86-.34 1.81-.06 1.24-.07 1.59-.07 4.74s.01 3.5.07 4.74c.04.95.2 1.47.34 1.81.18.46.39.79.74 1.13.34.35.67.56 1.13.74.34.14.86.3 1.81.34 1.24.06 1.59.07 4.74.07s3.5-.01 4.74-.07c.95-.04 1.47-.2 1.81-.34.46-.18.79-.39 1.13-.74.35-.34.56-.67.74-1.13.14-.34.3-.86.34-1.81.06-1.24.07-1.59.07-4.74s-.01-3.5-.07-4.74c-.04-.95-.2-1.47-.34-1.81a3.06 3.06 0 0 0-.74-1.13 3.06 3.06 0 0 0-1.13-.74c-.34-.14-.86-.3-1.81-.34-1.24-.06-1.59-.07-4.74-.07zM12 6.87a5.13 5.13 0 1 1 0 10.26 5.13 5.13 0 0 1 0-10.26zm0 8.46a3.33 3.33 0 1 0 0-6.66 3.33 3.33 0 0 0 0 6.66zm6.54-8.68a1.2 1.2 0 1 1-2.4 0 1.2 1.2 0 0 1 2.4 0z"/></svg>
          </a>
          <?php endif; ?>
          <?php if ($ytUrl): ?>
          <a href="<?= e($ytUrl) ?>" class="yt" target="_blank" rel="noopener" aria-label="YouTube" title="YouTube">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M23.5 6.2a3 3 0 0 0-2.1-2.1C19.5 3.6 12 3.6 12 3.6s-7.5 0-9.4.5A3 3 0 0 0 .5 6.2C0 8.1 0 12 0 12s0 3.9.5 5.8a3 3 0 0 0 2.1 2.1c1.9.5 9.4.5 9.4.5s7.5 0 9.4-.5a3 3 0 0 0 2.1-2.1c.5-1.9.5-5.8.5-5.8s0-3.9-.5-5.8zM9.6 15.6V8.4L15.8 12l-6.2 3.6z"/></svg>
          </a>
          <?php endif; ?>
          <?php if ($waUrl): ?>
          <a href="<?= e($waUrl) ?>" class="wa" target="_blank" rel="noopener" aria-label="WhatsApp" title="WhatsApp">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M17.5 14.4c-.3-.2-1.8-.9-2.1-1-.3-.1-.5-.2-.7.1-.2.3-.8 1-.9 1.2-.2.2-.3.2-.6.1-.3-.2-1.2-.5-2.3-1.4-.8-.8-1.4-1.7-1.5-2-.2-.3 0-.5.1-.6.1-.2.4-.5.6-.7.1-.2.2-.3.3-.5 0-.2 0-.4-.1-.5-.1-.2-.7-1.7-1-2.3-.2-.6-.5-.5-.7-.5h-.6c-.2 0-.5.1-.8.4-.3.3-1 1-1 2.4s1 2.8 1.2 3c.1.2 1.9 3 4.6 4.1 2.3.9 2.8.8 3.3.7.5-.1 1.6-.6 1.8-1.3.2-.6.2-1.2.2-1.3-.1-.2-.2-.2-.5-.4zM12 22a9.9 9.9 0 0 1-5-1.4L2 22l1.4-4.9A9.9 9.9 0 0 1 2 12 10 10 0 1 1 12 22z"/></svg>
          </a>
          <?php endif; ?>
          <?php if ($twUrl): ?>
          <a href="<?= e($twUrl) ?>" class="tw" target="_blank" rel="noopener" aria-label="X" title="X / Twitter">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M18.9 2H22l-6.8 7.8L23 22h-6.4l-5-6.6L5.8 22H2.7l7.3-8.3L1.5 2H8l4.7 6.2L18.9 2zm-1.1 18h1.7L6.4 3.8H4.6L17.8 20z"/></svg>
          </a>
          <?php endif; ?>
        </div>
      </div>

      <div class="foot-col">
        <h4>Explore</h4>
        <ul>
          <li><a href="<?= url('games.php') ?>">All Games</a></li>
          <?php if (quiz_on()): ?>
            <li><a href="<?= url('quiz.php') ?>">Quiz Contest</a></li>
          <?php endif; ?>
          <?php if (spin_on()): ?>
            <li><a href="<?= url('spin.php') ?>">Daily Spin</a></li>
          <?php endif; ?>
          <?php if (govt_exam_in_nav()): ?>
            <li><a href="<?= url('govt-exam.php') ?>">
              <?= e(setting('govt_exam_label', 'Govt Exam')) ?><?php
              if (govt_exam_status() === 'soon'): ?> <span class="nav-soon">soon</span><?php
              endif; ?></a></li>
          <?php endif; ?>
          <li><a href="<?= url('leaderboard.php') ?>">Leaderboard</a></li>
          <li><a href="<?= url('blog.php') ?>">Blog</a></li>
          <li><a href="<?= url('about.php') ?>">About Us</a></li>
          <li><a href="<?= url('contact.php') ?>">Contact</a></li>
        </ul>
      </div>

      <div class="foot-col">
        <h4>Games</h4>
        <ul>
          <?php foreach ($footGames as $g): ?>
            <li><a href="<?= e($g['play_url']) ?>"><?= $g['thumb']
              ? '<img src="' . e($g['thumb']) . '" alt="" class="g-mini" width="20" height="20" loading="lazy">'
              : $g['icon'] ?> <?= e($g['title']) ?></a></li>
          <?php endforeach; ?>
          <?php if (!$footGames): ?><li class="text-dim">Coming soon</li><?php endif; ?>
        </ul>
      </div>

      <div class="foot-col">
        <h4>Get in Touch</h4>
        <ul class="contact-lines">
          <?php if (setting('contact_email')): ?>
          <li><span class="ci">&#9993;</span>
            <a href="mailto:<?= e(setting('contact_email')) ?>"><?= e(setting('contact_email')) ?></a></li>
          <?php endif; ?>
          <?php if (setting('contact_phone')): ?>
          <li><span class="ci">&#9742;</span>
            <a href="tel:<?= e(preg_replace('/\s+/', '', setting('contact_phone'))) ?>"><?= e(setting('contact_phone')) ?></a></li>
          <?php endif; ?>
          <?php if (setting('contact_address')): ?>
          <li><span class="ci">&#128205;</span><span><?= e(setting('contact_address')) ?></span></li>
          <?php endif; ?>
          <?php if ($fbUrl): ?>
          <li><span class="ci">&#127760;</span>
            <a href="<?= e($fbUrl) ?>" target="_blank" rel="noopener">Facebook</a></li>
          <?php endif; ?>
          <?php if ($igUrl): ?>
          <li><span class="ci">&#128247;</span>
            <a href="<?= e($igUrl) ?>" target="_blank" rel="noopener">Instagram</a></li>
          <?php endif; ?>
          <?php if ($waUrl): ?>
          <li><span class="ci">&#128172;</span>
            <a href="<?= e($waUrl) ?>" target="_blank" rel="noopener">WhatsApp</a></li>
          <?php endif; ?>
          <?php if (!setting('contact_email') && !$fbUrl && !$igUrl): ?>
          <li class="text-dim">Contact details coming soon.</li>
          <?php endif; ?>
        </ul>
      </div>

    </div>

    <!-- ============ INSTALL AS AN APP (PWA) ============ -->
    <div class="foot-install" id="installBand">
      <div class="fi-icon"><img src="<?= e(logo_icon(192)) ?>" alt=""></div>
      <div class="fi-copy">
        <h4>Get <?= e($siteNm) ?> on your phone</h4>
        <p id="installHint">Install the app and play from your home screen &mdash;
           full screen, faster loading, no app store needed.</p>
      </div>
      <div class="fi-actions">
        <button type="button" class="btn btn-primary" id="installBtn">
          <span aria-hidden="true">&#11015;</span>&nbsp;Install App
        </button>
        <button type="button" class="btn btn-ghost btn-sm" id="installHelpBtn" hidden>How?</button>
      </div>
    </div>

    <!-- manual instructions, shown when the browser gives us no install prompt -->
    <div class="install-sheet" id="installSheet" hidden>
      <div class="is-card" role="dialog" aria-modal="true" aria-labelledby="isTitle">
        <button type="button" class="is-close" id="installSheetClose" aria-label="Close">&times;</button>
        <h3 id="isTitle">Add <?= e($siteNm) ?> to your home screen</h3>
        <div class="is-steps" id="isIos">
          <h4>iPhone / iPad &mdash; Safari</h4>
          <ol>
            <li>Tap the <b>Share</b> button <span class="is-key">&#9053;</span> at the bottom of Safari.</li>
            <li>Scroll down and tap <b>Add to Home Screen</b>.</li>
            <li>Tap <b>Add</b>. The app icon appears on your home screen.</li>
          </ol>
        </div>
        <div class="is-steps" id="isAndroid">
          <h4>Android &mdash; Chrome</h4>
          <ol>
            <li>Tap the <b>&#8942;</b> menu at the top right.</li>
            <li>Tap <b>Install app</b> (or <b>Add to Home screen</b>).</li>
            <li>Confirm with <b>Install</b>.</li>
          </ol>
        </div>
        <div class="is-steps" id="isDesktop">
          <h4>Desktop &mdash; Chrome / Edge</h4>
          <ol>
            <li>Click the install icon <span class="is-key">&#8862;</span> at the right of the address bar.</li>
            <li>Choose <b>Install</b>.</li>
          </ol>
        </div>
      </div>
    </div>

    <div class="foot-bottom">
      <span>&copy; <?= date('Y') ?> <?= e($siteNm) ?>. All rights reserved.</span>
      <span>
        Skill-based games with virtual coins only &mdash; no real-money wagering.
        &nbsp;&middot;&nbsp; <a href="<?= url('admin/') ?>">Admin</a>
      </span>
    </div>
  </div>
</footer>

<script src="<?= asset('assets/js/main.js') ?>" defer></script>
<script src="<?= asset('assets/js/pwa.js') ?>"
        data-sw="<?= e(url('sw.js')) ?>"
        data-scope="<?= e(rtrim(SITE_URL, '/') . '/') ?>" defer></script>
<?= $extra_foot ?? '' ?>
</body>
</html>
