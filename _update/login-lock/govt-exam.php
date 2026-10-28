<?php
/**
 * Government competitive exam — the announcement page.
 *
 * While the section is in "soon" mode this is what the navigation link
 * opens: what is coming, when, and a form to be told the moment it does.
 * Flip the status to "live" in Admin -> Govt Exam and the same page turns
 * into the entry point for the real thing.
 */
require_once __DIR__ . '/config/config.php';

// No game without an account. One helper, every entry point.
require_play_login();

if (!govt_exam_on()) {
    flash('That section is not available right now.', 'info');
    redirect('index.php');
}

$status  = govt_exam_status();
$launch  = govt_exam_launch();
$daysLeft = govt_exam_days_left();
$joined  = govt_exam_signups();
$me      = current_user();

$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    [$ok, $msg] = govt_exam_join([
        'name'      => post('name'),
        'email'     => post('email'),
        'phone'     => post('phone'),
        'exam_pref' => post('exam_pref'),
        'note'      => post('note'),
    ]);
    flash($msg, $ok ? 'success' : 'error');
    if ($ok) {
        redirect('govt-exam.php?joined=1');
    }
}
$sent = get('joined') === '1';

$page_title = setting('govt_exam_label', 'Govt Exam')
            . ($status === 'soon' ? ' — Coming Soon' : '');
$page_desc  = setting('govt_exam_tagline',
              'WBCS, SSC, Rail, Bank & PSC practice — built the Asviora way.');

require __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="container">
    <div class="crumb"><a href="<?= url('index.php') ?>">Home</a> &rsaquo;
      <?= e(setting('govt_exam_label', 'Govt Exam')) ?></div>
    <h1>Government <span class="gradient-text">Competitive Exam</span></h1>
    <p><?= e($page_desc) ?></p>

    <?php if ($status === 'soon'): ?>
      <div class="row" style="gap:10px;margin-top:16px;flex-wrap:wrap">
        <span class="badge badge-gold" style="font-size:.8rem;padding:6px 12px">Coming soon</span>
        <?php if ($daysLeft !== null && $daysLeft > 0): ?>
          <span class="badge badge-green" style="font-size:.8rem;padding:6px 12px">
            <?= (int) $daysLeft ?> day<?= $daysLeft === 1 ? '' : 's' ?> to go</span>
        <?php elseif ($launch !== ''): ?>
          <span class="badge badge-green" style="font-size:.8rem;padding:6px 12px">
            Opening <?= fdate($launch) ?></span>
        <?php endif; ?>
        <?php if ($joined > 0): ?>
          <span class="badge" style="font-size:.8rem;padding:6px 12px">
            <?= num($joined) ?> already on the list</span>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="section">
  <div class="container">
    <?= render_flash() ?>

    <div class="spin-wrap">

      <!-- ---------- what is coming ---------- -->
      <div>
        <div class="card mb-3">
          <h2 style="margin-top:0">What we are building</h2>
          <p class="text-muted"><?= e(setting('govt_exam_blurb',
            'Full-length mock tests, previous-year papers, sectional practice and a '
          . 'level ladder that actually tracks what you are weak at.')) ?></p>

          <div class="grid g-2 mt-3">
            <div class="kv"><span class="k">&#128220; Previous-year papers</span>
              <span class="v text-green">Solved</span></div>
            <div class="kv"><span class="k">&#9201; Full-length mocks</span>
              <span class="v text-green">Timed</span></div>
            <div class="kv"><span class="k">&#127919; Sectional practice</span>
              <span class="v text-green">By topic</span></div>
            <div class="kv"><span class="k">&#128202; Weak-area report</span>
              <span class="v text-green">After each test</span></div>
            <div class="kv"><span class="k">&#127942; All-India ranking</span>
              <span class="v text-green">Per test</span></div>
            <div class="kv"><span class="k">&#129689; Coins for accuracy</span>
              <span class="v text-gold">Same wallet</span></div>
          </div>
        </div>

        <div class="card mb-3">
          <h3 style="font-size:1.05rem;margin-top:0">Exams we are starting with</h3>
          <div class="row" style="gap:8px;flex-wrap:wrap">
            <?php foreach (govt_exam_streams() as $s): ?>
              <span class="badge"><?= e($s) ?></span>
            <?php endforeach; ?>
          </div>
          <p class="form-hint" style="margin-top:14px">
            Tell us which one you are preparing for and it moves up the queue.
          </p>
        </div>

        <div class="card cta-band" style="border-radius:var(--radius)">
          <h3 style="margin-bottom:6px">Meanwhile, keep the streak alive</h3>
          <p class="text-muted" style="margin:0 0 14px">
            The quiz contest already runs on the same level ladder the exam section will use.
          </p>
          <div class="row" style="gap:10px;flex-wrap:wrap">
            <a class="btn btn-primary btn-sm" href="<?= url('quiz.php') ?>">&#129504; Quiz contest</a>
            <a class="btn btn-ghost btn-sm" href="<?= url('games.php') ?>">&#127918; All games</a>
          </div>
        </div>
      </div>

      <!-- ---------- notify me ---------- -->
      <div>
        <div class="card" id="notify">
          <h2 style="margin-top:0;font-size:1.2rem">
            <?= $status === 'soon' ? 'Tell me when it opens' : 'Join the exam list' ?>
          </h2>

          <?php if ($sent): ?>
            <div class="alert alert-success" style="margin-bottom:16px">
              <span>&#10003;</span> You are on the list. Nothing else to do.
            </div>
          <?php endif; ?>

          <p class="text-muted" style="margin-top:0">
            One email when it goes live. No newsletter, no spam.
          </p>

          <form method="post">
            <?= csrf_field() ?>

            <div class="form-group">
              <label class="form-label" for="name">Your name</label>
              <input class="form-control" type="text" id="name" name="name" maxlength="100"
                     value="<?= e($me['name'] ?? '') ?>" placeholder="Rahul Das">
            </div>

            <div class="form-group">
              <label class="form-label" for="email">Email <span class="text-gold">*</span></label>
              <input class="form-control" type="email" id="email" name="email" required
                     maxlength="150" value="<?= e($me['email'] ?? '') ?>"
                     placeholder="you@example.com">
            </div>

            <div class="form-group">
              <label class="form-label" for="phone">Phone (optional)</label>
              <input class="form-control" type="tel" id="phone" name="phone" maxlength="24"
                     placeholder="+91 ">
            </div>

            <div class="form-group">
              <label class="form-label" for="exam_pref">Which exam?</label>
              <select class="form-select" id="exam_pref" name="exam_pref">
                <option value="">Not decided yet</option>
                <?php foreach (govt_exam_streams() as $s): ?>
                  <option value="<?= e($s) ?>"><?= e($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label" for="note">Anything you want included?</label>
              <input class="form-control" type="text" id="note" name="note" maxlength="190"
                     placeholder="e.g. Bengali-medium questions, current affairs">
            </div>

            <button class="btn btn-primary btn-block" type="submit">
              <?= $status === 'soon' ? 'Notify me at launch' : 'Count me in' ?>
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
