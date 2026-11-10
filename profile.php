<?php
// Profile: the resident's details (read-only) and their last 30 days in the gym.
require __DIR__ . '/app/bootstrap.php';
$user = Auth::requirePage('resident');
// The number of finished visits and the total minutes in the last 30 days, from the attendance table.
$stats = Db::row("SELECT COUNT(*) AS sessions, COALESCE(SUM(TIMESTAMPDIFF(MINUTE, t.checked_in_at, t.checked_out_at)), 0) AS minutes
                    FROM attendance t WHERE t.user_id = ? AND t.checked_out_at IS NOT NULL AND t.checked_in_at >= ?",
    [$user['id'], dt(Clock::today()->modify('-30 days'))]);
View::header('Profile', 'profile', ['mainClass' => 'narrow-wide']);
?>
<div class="page-head"><div><h1>Profile</h1><p class="muted">Your details in GymZone.</p></div></div>

<?php // Name and apartment are set by the housing office and cannot be changed here. ?>
<section class="card profile-card">
  <div class="avatar" aria-hidden="true"><?= View::e(mb_strtoupper(mb_substr($user['full_name'], 0, 1))) ?></div>
  <dl class="info-list">
    <div class="info-row"><div><dt>Full name</dt><dd><?= View::e($user['full_name']) ?></dd></div><span class="lock"><?= View::icon('lock') ?>Set by the housing office</span></div>
    <div class="info-row"><div><dt>Apartment number</dt><dd><?= View::e($user['apartment']) ?></dd></div><span class="lock"><?= View::icon('lock') ?>Set by the housing office</span></div>
    <div class="info-row"><div><dt>Username</dt><dd>@<?= View::e($user['username']) ?></dd></div></div>
    <div class="info-row"><div><dt>Email address</dt><dd><?= View::e($user['email']) ?></dd></div></div>
  </dl>
  <a class="btn btn-block" href="settings.php"><?= View::icon('settings') ?>Change username, email or password</a>
</section>

<section class="card section">
  <h2>Last 30 days</h2>
  <div class="mini-stats">
    <div><span class="n"><?= (int) $stats['sessions'] ?></span><span class="l">sessions</span></div>
    <div><span class="n"><?= View::e(View::duration((int) $stats['minutes'])) ?></span><span class="l">in the gym</span></div>
  </div>
</section>
<p class="muted small">Is your name or apartment wrong? <a href="help.php">Contact the housing office</a>.</p>
<?php View::footer();
