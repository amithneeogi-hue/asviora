<?php
require_once __DIR__ . '/config/config.php';
http_response_code(404);
$page_title = 'Page not found';
require __DIR__ . '/includes/header.php';
?>

<section class="section">
  <div class="container">
    <div class="card empty" style="max-width:560px;margin-inline:auto">
      <div class="em-ico" style="font-size:4rem">&#129513;</div>
      <h1 style="font-size:2.4rem;margin-bottom:8px" class="gradient-text">404</h1>
      <h3>This square is empty</h3>
      <p>The page you were after has moved, been renamed, or never existed.</p>
      <div class="row" style="justify-content:center;gap:10px">
        <a href="<?= url('index.php') ?>" class="btn btn-primary">Back to home</a>
        <a href="<?= url('games.php') ?>" class="btn btn-ghost">Play a game</a>
      </div>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
