<?php
// My bookings: the Upcoming, Completed and Cancelled tabs.
require __DIR__ . '/app/bootstrap.php';
$user = Auth::requirePage('resident');
$uid = (int) $user['id'];
// The three lists for this resident.
$lists = Bookings::forResident($uid);
// For each upcoming booking, work out whether the Check in button should be shown now.
$now = Clock::now();
foreach ($lists['upcoming'] as &$b) {
    [$opens, $closes] = Attendance::window(['start_at' => $b['start'], 'end_at' => $b['end']]);
    $b['can_check_in'] = $b['status'] === 'booked' && $now >= $opens && $now < $closes;
}
// unset() is needed after a foreach by reference (&$b).
unset($b);
// Which tab is open (from ?tab=...), with titles and empty-list messages.
$tab = in_array($_GET['tab'] ?? '', ['upcoming', 'completed', 'cancelled'], true) ? $_GET['tab'] : 'upcoming';
$titles = ['upcoming' => 'Upcoming', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
$empty = ['upcoming' => 'You have no upcoming bookings.', 'completed' => 'Completed sessions will appear here.', 'cancelled' => 'You have no cancelled bookings.'];
View::header('My bookings', 'bookings');
?>
<div class="page-head">
  <div><h1>My bookings</h1><p class="muted">Upcoming sessions, your workout history and cancellations.</p></div>
  <a class="btn btn-primary" href="book.php"><?= View::icon('plus') ?>Book a session</a>
</div>
<?php // Tabs: core.js shows the chosen tab's panel without reloading the page. ?>
<div data-tabs>
  <div class="tabs" role="tablist" aria-label="Booking lists">
    <?php foreach ($titles as $key => $label): ?>
      <button type="button" role="tab" id="tab-<?= $key ?>" aria-controls="panel-<?= $key ?>" aria-selected="<?= $key === $tab ? 'true' : 'false' ?>"><?= $label ?><span class="count"><?= count($lists[$key]) ?></span></button>
    <?php endforeach; ?>
  </div>
  <?php foreach ($titles as $key => $label): ?>
  <section role="tabpanel" id="panel-<?= $key ?>" aria-labelledby="tab-<?= $key ?>"<?= $key === $tab ? '' : ' hidden' ?>>
    <?php if (!$lists[$key]): ?>
      <?= Components::emptyState('calendar', $empty[$key], $key === 'upcoming' ? ['Book a session', 'book.php'] : null) ?>
    <?php else: ?>
      <div class="booking-list"><?php foreach ($lists[$key] as $b) echo Components::bookingItem($b, $key); ?></div>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>
</div>
<?php View::footer(['resident.js']);
