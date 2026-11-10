<?php
// Admin overview: counts, live zone availability, who is in the gym, and closures.
require __DIR__ . '/../app/bootstrap.php';
// Only administrators can open this page.
$admin = Auth::requirePage('admin');
// Totals for the stat boxes.
$counts = Residents::counts();
$ov = Availability::overview();
$today = Clock::today()->format('Y-m-d');
// Sessions today (booked, checked in or completed).
$todayBookings = (int) Db::value("SELECT COUNT(*) FROM bookings WHERE booking_date = ? AND status IN ('booked','checked_in','completed')", [$today]);
$active = Attendance::activeAll();
$closures = array_slice(Schedule::upcomingClosures(), 0, 5);
// Zones that an administrator has closed.
$closedZones = array_filter(Zones::all(), fn($z) => !$z['is_open']);
View::header('Overview', 'overview');
?>
<div class="page-head">
  <div><h1>Overview</h1><p class="muted"><?= View::e(Clock::now()->format('l j F Y')) ?> · signed in as <?= View::e($admin['full_name']) ?></p></div>
  <div class="button-row"><a class="btn" href="residents.php"><?= View::icon('users') ?>Residents</a><a class="btn btn-primary" href="bookings.php"><?= View::icon('calendar') ?>Today's bookings</a></div>
</div>

<?php // Six stat boxes. ?>
<div class="stats">
  <div class="stat"><span class="n"><?= $counts['total'] ?></span><span class="l">Total residents</span></div>
  <div class="stat"><span class="n"><?= $counts['active'] ?></span><span class="l">Active accounts</span></div>
  <div class="stat"><span class="n"><?= $counts['pending'] ?></span><span class="l">Pending activations</span></div>
  <div class="stat"><span class="n"><?= $todayBookings ?></span><span class="l">Sessions booked today</span></div>
  <div class="stat"><span class="n"><?= count($active) ?></span><span class="l">In the gym now</span></div>
  <a class="stat stat-link" href="messages.php"><span class="n"><?= Contact::openCount() ?></span><span class="l">Open messages</span></a>
</div>

<?php // The same zone cards as on the resident dashboard, without buttons. ?>
<section aria-labelledby="occ">
  <div class="section-head"><h2 id="occ">Zone availability</h2></div>
  <div class="session-banner"><span class="live-dot<?= $ov['open_now'] ? '' : ' off' ?>" aria-hidden="true"></span>
    <span><?= $ov['open_now'] ? 'Live · updated ' . View::e($ov['updated']) : 'Closed now · opens ' . View::e($ov['next_opening'] ?? 'later') ?></span></div>
  <div class="grid grid-3"><?php foreach ($ov['zones'] as $z) echo Components::zoneCard($z, false, false); ?></div>
</section>

<?php // Who is checked in now, and planned closures. ?>
<div class="grid grid-2 section">
  <section class="card" aria-labelledby="inside">
    <div class="card-title"><h2 id="inside">Checked in now</h2></div>
    <?php if (!$active): ?><p class="muted">Nobody is checked in.</p><?php else: ?>
    <div class="table-wrap card-flat"><table>
      <thead><tr><th>Resident</th><th>Zone</th><th>Since</th></tr></thead>
      <tbody><?php foreach ($active as $a): ?><tr><td><?= View::e($a['full_name']) ?><span class="sub">Apt <?= View::e($a['apartment']) ?></span></td><td><?= View::e($a['zone_name']) ?><span class="sub"><?= $a['source'] === 'walk_in' ? 'Walk-in' : 'Booked' ?></span></td><td><?= View::e(View::date($a['checked_in_at'], 'H:i')) ?><span class="sub">until <?= View::e(View::date($a['end_at'], 'H:i')) ?></span></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </section>
  <section class="card" aria-labelledby="closed">
    <div class="card-title"><h2 id="closed">Closures</h2></div>
    <?php if (!$closures && !$closedZones): ?><p class="muted">No closures are planned.</p><?php endif; ?>
    <ul class="plain-list">
      <?php foreach ($closedZones as $z): ?><li><strong><?= View::e($z['name']) ?></strong> closed until reopened<?= $z['closed_note'] ? ': ' . View::e($z['closed_note']) : '' ?></li><?php endforeach; ?>
      <?php foreach ($closures as $c): ?><li><strong><?= View::e($c['zone_name'] ?? 'Whole gym') ?></strong> · <?= View::e(View::date($c['starts_at'], 'D j M H:i')) ?> – <?= View::e(View::date($c['ends_at'], (substr($c['starts_at'], 0, 10) === substr($c['ends_at'], 0, 10) ? 'H:i' : 'D j M H:i'))) ?><span class="sub"><?= View::e($c['reason']) ?></span></li><?php endforeach; ?>
    </ul>
    <a class="btn btn-small" href="gym.php?tab=closures">Manage closures</a>
  </section>
</div>
<?php View::footer();
