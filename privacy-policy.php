<?php
require_once __DIR__ . '/config/config.php';

$page_title = 'Privacy Policy';
$page_desc  = 'Privacy policy for Asviora Games, including registration data, cookies, analytics, advertising cookies, third-party services, and user rights.';
$seo_keywords = 'privacy policy, asviora games privacy, cookies, analytics, advertising cookies, data protection';

require __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
  <div class="container">
    <div class="crumb"><a href="<?= url('index.php') ?>">Home</a> &rsaquo; Privacy Policy</div>
    <h1>Privacy <span class="gradient-text">Policy</span></h1>
    <p>How Asviora collects, uses, stores, and protects your information.</p>
  </div>
</section>

<section class="section">
  <div class="container" style="max-width:860px">
    <div class="card" style="padding:28px">
      <h2>1. Information we collect</h2>
      <p>When you register or use Asviora, we may collect your name, username, email address, phone number, password hash, profile details, avatar, game scores, match history, virtual coin balance, XP, level, and activity logs.</p>

      <h2>2. How we use information</h2>
      <p>We use this information to create and manage accounts, provide games and leaderboards, validate scores, prevent fraud and abuse, communicate service updates, and improve the platform.</p>

      <h2>3. Cookies and analytics</h2>
      <p>Asviora may use cookies and similar technologies to keep you signed in, remember preferences, measure traffic, and understand how pages are used. Analytics services may collect device, browser, and usage information.</p>

      <h2>4. Advertising cookies</h2>
      <p>If advertising is enabled, Google and other advertising partners may use cookies or similar technologies to serve and measure ads. These technologies may be used to personalize or limit repeated advertisements, subject to your consent and applicable law.</p>

      <h2>5. Third-party services</h2>
      <p>We may use third-party providers for hosting, payments, analytics, advertising, email delivery, and social login. These providers process data according to their own privacy policies and our instructions.</p>

      <h2>6. Payments</h2>
      <p>Payments for registration fees or game passes are processed by third-party payment gateways. We do not store card or UPI details. Payment records may be retained for accounting, fraud prevention, and legal compliance.</p>

      <h2>7. Data sharing</h2>
      <p>We do not sell personal information. We may share data with service providers, payment processors, analytics providers, advertising partners, or legal authorities when required by law or necessary to protect the platform and users.</p>

      <h2>8. Data retention</h2>
      <p>We retain account and activity data for as long as needed to operate the service, comply with legal obligations, resolve disputes, and enforce our terms. You may request deletion of your account where permitted by law.</p>

      <h2>9. Your rights</h2>
      <p>You may request access, correction, or deletion of your personal data, and you may object to certain processing where applicable. To make a request, contact us using the details on our Contact page.</p>

      <h2>10. Children</h2>
      <p>Asviora is not intended for users below the minimum age required by applicable law. If you believe a child has provided personal information, contact us so we can review and remove it.</p>

      <h2>11. Changes to this policy</h2>
      <p>We may update this Privacy Policy from time to time. The updated version will be posted on this page with a revised date.</p>

      <h2>12. Contact</h2>
      <p>For privacy questions, contact Asviora through the <a href="<?= url('contact.php') ?>">Contact page</a>.</p>
    </div>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>