<?php
require_once __DIR__ . '/config/config.php';

$page_title = 'Terms and Conditions';
$page_desc  = 'Terms and conditions for using Asviora Games, including accounts, virtual coins, payments, acceptable use, and service availability.';
$seo_keywords = 'terms and conditions, asviora games terms, user agreement, virtual coins, game passes';

require __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
  <div class="container">
    <div class="crumb"><a href="<?= url('index.php') ?>">Home</a> &rsaquo; Terms and Conditions</div>
    <h1>Terms and <span class="gradient-text">Conditions</span></h1>
    <p>Rules for using the Asviora platform.</p>
  </div>
</section>

<section class="section">
  <div class="container" style="max-width:860px">
    <div class="card" style="padding:28px">
      <h2>1. Acceptance</h2>
      <p>By creating an account or using Asviora, you agree to these terms and to our Privacy Policy.</p>

      <h2>2. Accounts</h2>
      <p>You are responsible for your account credentials and activity. You must provide accurate information during registration and keep your password secure.</p>

      <h2>3. Virtual coins and XP</h2>
      <p>Coins, XP, scores, and leaderboard positions are virtual features of the platform. They have no cash value, cannot be withdrawn, and cannot be redeemed for money or other value.</p>

      <h2>4. Payments</h2>
      <p>Registration fees and game passes, where applicable, are payments for access to platform features. They are not deposits, wagers, or purchases of withdrawable value. Payment terms, duration, and renewal details are shown before payment.</p>

      <h2>5. Fair play</h2>
      <p>Scores are validated server-side. Cheating, score manipulation, abuse, or attempts to bypass security may result in account suspension or removal.</p>

      <h2>6. Gaming content</h2>
      <p>Asviora offers skill-based games, quizzes, chess, and daily challenges. Outcomes depend on player performance and, for certain features, random reward mechanics. No cash prizes are offered.</p>

      <h2>7. Availability</h2>
      <p>We may modify, pause, or discontinue features at any time. We do not guarantee uninterrupted access.</p>

      <h2>8. Intellectual property</h2>
      <p>Asviora content, branding, and software are protected by applicable intellectual property laws. You may not copy or redistribute them without permission.</p>

      <h2>9. Limitation of liability</h2>
      <p>Asviora is provided on an “as is” basis. To the extent permitted by law, we are not liable for indirect or consequential losses arising from use of the platform.</p>

      <h2>10. Contact</h2>
      <p>Questions about these terms can be sent through the <a href="<?= url('contact.php') ?>">Contact page</a>.</p>
    </div>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>