<?php
// Resident home page: live availability per zone, the resident's own session and their next booking.
require __DIR__ . '/app/bootstrap.php';
// Only signed-in residents; $user is the signed-in resident.
$user = Auth::requirePage('resident');
$uid = (int) $user['id'];
// Live numbers for every zone (Availability::overview).
$ov = Availability::overview();
// Is the resident in the gym right now? (null if not)
$active = Attendance::activeFor($uid);
// The next booking that is still to come.
$next = Bookings::nextFor($uid);
// An automatic check-out that still needs the real leaving time.
$report = Attendance::pendingReport($uid);
// A greeting that fits the time of day.
$now = Clock::now();
$hour = (int) $now->format('G');
$greeting = $hour < 5 ? 'Good evening' : ($hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening'));
// Walk-in buttons are shown only when the gym is open and the resident is not already inside.
$canWalkIn = $ov['open_now'] && !$active;
View::header('Dashboard', 'dashboard');
?>
<div class="welcome">
  <div>
    <h1><?= $greeting ?>, <?= View::e(strtok($user['full_name'], ' ')) ?></h1>
    <p class="muted">Apartment <?= View::e($user['apartment']) ?></p>
  </div>
  <a class="btn btn-primary" href="book.php"><?= View::icon('plus') ?>Book a session</a>
</div>

<?php // After an automatic check-out: ask when the resident really left (sent by resident.js). ?>
<?php if ($report): $autoAt = new DateTimeImmutable($report['checked_out_at']); ?>
<section class="card report-card" aria-labelledby="report-title">
  <h2 id="report-title">When did you leave the gym?</h2>
  <p>You were still checked in to <?= View::e($report['zone_name']) ?> <?= Settings::int('auto_checkout_hours') ?> hours after checking in at <?= View::e(View::date($report['checked_in_at'], 'H:i')) ?>
    <?= View::e(strtolower(View::relDate($report['checked_in_at'])) === 'today' ? '' : '(' . View::date($report['checked_in_at'], 'D j M') . ')') ?>, so GymZone checked you out automatically at <?= View::e($autoAt->format('H:i')) ?>. Please tell us when you really left, so the records are correct.</p>
  <form class="inline-form report-form" id="report-form" data-ajax data-id="<?= (int) $report['id'] ?>">
    <label class="sr-only" for="report-time">Time you left</label>
    <input id="report-time" name="time" type="time" required value="<?= View::e($autoAt->format('H:i')) ?>">
    <button class="btn btn-primary" type="submit">Save</button>
  </form>
  <button class="link-btn small" type="button" data-confirm-auto="<?= (int) $report['id'] ?>">I left at <?= View::e($autoAt->format('H:i')) ?></button>
</section>
<?php endif; ?>

<?php // Two cards: the active session (with Check out) and the next booking (with Check in when it is time). ?>
<div class="quick-grid">
  <?php if ($active): $over = $now > new DateTimeImmutable($active['end_at']); ?>
  <section class="card highlight-card active-session" aria-label="Current workout session">
    <div class="hc-main">
      <div class="hc-icon green"><?= View::icon('gym', 'icon icon-lg') ?></div>
      <div><div class="hc-label">Checked in</div><div class="hc-title"><?= View::e($active['zone_name']) ?> · since <?= View::e(View::date($active['checked_in_at'], 'H:i')) ?></div>
        <div class="muted small"><?= $over ? 'Planned until ' . View::e(View::date($active['end_at'], 'H:i')) . '. Please check out when you leave.' : 'Planned until ' . View::e(View::date($active['end_at'], 'H:i')) ?></div></div>
    </div>
    <button class="btn btn-primary" type="button" data-attendance="checkout">Check out</button>
  </section>
  <?php endif; ?>

  <?php if ($next): ?>
  <section class="card highlight-card" aria-label="Next booking">
    <div class="hc-main">
      <div class="hc-icon"><?= View::icon('calendar', 'icon icon-lg') ?></div>
      <div><div class="hc-label">Next booking</div><div class="hc-title"><?= View::e($next['zone']) ?> · <?= View::e($next['time']) ?></div>
        <div class="muted small"><?= View::e($next['date']) ?><?= $next['can_check_in'] ? '' : ' · check-in opens ' . View::e(lcfirst($next['check_in_opens_label'])) ?></div></div>
    </div>
    <?php if ($next['can_check_in'] && !$active): ?>
      <button class="btn btn-success" type="button" data-attendance="checkin" data-booking="<?= (int) $next['id'] ?>">Check in</button>
    <?php else: ?>
      <a class="btn" href="bookings.php">My bookings</a>
    <?php endif; ?>
  </section>
  <?php elseif (!$active): ?>
  <section class="card highlight-card" aria-label="Next booking">
    <div class="hc-main">
      <div class="hc-icon"><?= View::icon('calendar', 'icon icon-lg') ?></div>
      <div><div class="hc-label">Next booking</div><div class="hc-title">No upcoming bookings</div><div class="muted small">Book any time, up to three weeks ahead.</div></div>
    </div>
    <a class="btn" href="book.php">Book a session</a>
  </section>
  <?php endif; ?>
</div>

<?php // The zone cards. dashboard.js refreshes them every 15 seconds from api/availability.php. ?>
<section aria-labelledby="gym-now">
  <div class="section-head"><h2 id="gym-now"><?= $ov['open_now'] ? 'Gym right now' : 'The gym is closed now' ?></h2></div>
  <div class="session-banner">
    <span class="live-dot<?= $ov['open_now'] ? '' : ' off' ?>" aria-hidden="true"></span>
    <span><?= $ov['open_now'] ? 'Live · updated <span id="updated">' . View::e($ov['updated']) . '</span>' : 'Opens ' . View::e($ov['next_opening'] ?? 'later') ?></span>
  </div>
  <div class="grid grid-3" id="zones" data-refresh="<?= View::e(Http::url('api/availability.php?view=overview')) ?>">
    <?php foreach ($ov['zones'] as $z) echo Components::zoneCard($z, $canWalkIn); ?>
  </div>
</section>

<?php // The walk-in dialog: the session starts now, and the resident chooses how long. ?>
<dialog class="modal" id="walkin-modal" aria-labelledby="walkin-title">
  <div class="modal-head"><h2 id="walkin-title">Check in now</h2><button class="modal-close" type="button" data-close aria-label="Close"><?= View::icon('x') ?></button></div>
  <div class="modal-body">
    <form class="form" id="walkin-form" data-ajax>
      <div class="alert alert-error modal-error" role="alert" hidden></div>
      <input type="hidden" name="zone_id">
      <p>Your session starts now, at <strong id="walkin-start"><?= $now->format('H:i') ?></strong>.</p>
      <div class="field"><label for="walkin-minutes">How long will you train?</label>
        <select id="walkin-minutes" name="minutes"><?= Components::durationOptions() ?></select>
        <span class="hint">You can check out earlier at any time.</span></div>
      <div class="modal-actions"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-success" type="submit">Check in</button></div>
    </form>
  </div>
</dialog>
<?php // Load resident.js (the buttons) and dashboard.js (the live refresh). ?>
<?php View::footer(['resident.js', 'dashboard.js']);
