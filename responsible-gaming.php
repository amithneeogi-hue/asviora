<?php
require_once __DIR__ . '/config/config.php';

$page_title = 'Responsible Gaming';
$page_desc  = 'Responsible gaming information for Asviora, including virtual coins, no cash prizes, paid access, and player safety.';
$seo_keywords = 'responsible gaming, asviora games, virtual coins, no cash prizes, fair play';

require __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
  <div class="container">
    <div class="crumb"><a href="<?= url('index.php') ?>">Home</a> &rsaquo; Responsible Gaming</div>
    <h1>Responsible <span class="gradient-text">Gaming</span></h1>
    <p>How Asviora keeps play fair, transparent, and virtual.</p>
  </div>
</section>

<section class="section">
  <div class="container" style="max-width:860px">
    <div class="card" style="padding:28px">
      <h2>Virtual coins only</h2>
      <p>Asviora uses virtual coins and XP for progress, rewards, and leaderboards. Virtual coins cannot be purchased for cash value, withdrawn, or redeemed for money.</p>

      <h2>No cash prizes</h2>
      <p>Asviora does not offer cash prizes, betting, wagering, or real-money gambling. Game rewards are virtual and have no monetary value.</p>

      <h2>Paid access</h2>
      <p>Some features may require a registration fee or game pass. These payments provide access to platform features and are not deposits, stakes, or purchases of withdrawable value.</p>

      <h2>Skill and chance</h2>
      <p>Most games are skill-based. Certain features, such as daily challenges or reward wheels, may include random outcomes. Odds and rules are displayed where applicable.</p>

      <h2>Fair play</h2>
      <p>Scores are validated server-side. Players should not attempt to manipulate scores, exploit bugs, or abuse accounts.</p>

      <h2>Player wellbeing</h2>
      <p>Play should remain enjoyable and balanced. If gaming affects your wellbeing, finances, or responsibilities, take a break and seek support from a trusted person or professional service.</p>

      <h2>Contact</h2>
      <p>For questions about gaming features or account concerns, contact us through the <a href="<?= url('contact.php') ?>">Contact page</a>.</p>
    </div>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>