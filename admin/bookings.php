<?php
// Admin: the bookings of one day, filtered by zone and status; cancel with a reason, or check someone out.
require __DIR__ . '/../app/bootstrap.php';
$admin = Auth::requirePage('admin');
// The date from the address, or today. The pattern check refuses anything that is not a date.
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : Clock::today()->format('Y-m-d');
$d = new DateTimeImmutable($date);
$zoneId = (int) ($_GET['zone'] ?? 0) ?: null;
$status = (string) ($_GET['status'] ?? '');
$rows = Bookings::adminList($date, $zoneId, $status);
$active = Attendance::activeAll();
$zones = Zones::all();
// $q builds a link to this page that keeps the current filters but changes one value.
$q = fn(array $p) => '?' . http_build_query(array_filter(['date' => $date, 'zone' => $zoneId, 'status' => $status] + $p, fn($v) => $v !== null && $v !== ''));
View::header('Bookings', 'bookings');
?>
<div class="page-head">
  <div><h1>Bookings</h1><p class="muted">Bookings and check-ins by date and zone.</p></div>
</div>

<?php // Who is checked in right now, with a Check out button. ?>
<?php if ($active): ?>
<section class="card section-tight" aria-labelledby="now-title">
  <div class="card-title"><h2 id="now-title">Checked in now (<?= count($active) ?>)</h2></div>
  <div class="table-wrap card-flat"><table class="table-responsive">
    <thead><tr><th>Resident</th><th>Zone</th><th>Booked</th><th>Checked in</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody><?php foreach ($active as $a): ?>
      <tr><td data-label="Resident"><?= View::e($a['full_name']) ?><span class="sub">Apt <?= View::e($a['apartment']) ?></span></td><td data-label="Zone"><?= View::e($a['zone_name']) ?></td>
          <td data-label="Booked"><?= View::e(View::timeRange($a['start_at'], $a['end_at'])) ?></td><td data-label="Checked in"><?= View::e(View::date($a['checked_in_at'], 'H:i')) ?></td>
          <td class="row-actions-cell"><div class="row-actions"><button class="btn btn-small" type="button" data-checkout="<?= (int) $a['booking_id'] ?>" data-name="<?= View::e($a['full_name']) ?>">Check out</button></div></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</section>
<?php endif; ?>

<?php // Filters: date, zone and status, plus buttons for the previous day, today and the next day. ?>
<form method="get" class="filters">
  <div class="field"><label for="date">Date</label><input id="date" type="date" name="date" value="<?= View::e($date) ?>" data-autosubmit></div>
  <div class="field"><label for="zone">Zone</label><select id="zone" name="zone" data-autosubmit><option value="">All zones</option><?php foreach ($zones as $z): ?><option value="<?= (int) $z['id'] ?>"<?= $zoneId === (int) $z['id'] ? ' selected' : '' ?>><?= View::e($z['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label for="status">Status</label><select id="status" name="status" data-autosubmit><option value="">All</option>
    <?php foreach (['booked' => 'Upcoming', 'checked_in' => 'Checked in', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'released' => 'Released'] as $k => $l): ?><option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="button-row">
    <a class="btn" href="<?= View::e($q(['date' => $d->modify('-1 day')->format('Y-m-d')])) ?>" aria-label="Previous day"><?= View::icon('back') ?></a>
    <a class="btn" href="<?= View::e($q(['date' => Clock::today()->format('Y-m-d')])) ?>">Today</a>
    <a class="btn" href="<?= View::e($q(['date' => $d->modify('+1 day')->format('Y-m-d')])) ?>" aria-label="Next day"><?= View::icon('chevron') ?></a>
  </div>
</form>

<h2><?= View::e($d->format('l j F Y')) ?> <span class="muted small">· <?= count($rows) ?> <?= count($rows) === 1 ? 'entry' : 'entries' ?></span></h2>
<?php if (!$rows): ?>
  <div class="card empty-state"><?= View::icon('calendar') ?><p>No bookings match these filters.</p></div>
<?php else: ?>
<div class="table-wrap"><table class="table-responsive">
  <thead><tr><th>Booked</th><th>Resident</th><th>Zone</th><th>Type</th><th>Status</th><th>In the gym</th><th><span class="sr-only">Actions</span></th></tr></thead>
  <tbody><?php foreach ($rows as $r): ?>
    <tr>
      <td data-label="Booked" class="nowrap"><?= View::e(View::timeRange($r['start_at'], $r['end_at'])) ?><span class="sub"><?= View::e(View::duration(View::minutesBetween($r['start_at'], $r['end_at']))) ?></span></td>
      <td data-label="Resident"><?= View::e($r['full_name']) ?><span class="sub">Apt <?= View::e($r['apartment']) ?></span></td>
      <td data-label="Zone"><?= View::e($r['zone_name']) ?></td>
      <td data-label="Type"><?= $r['source'] === 'walk_in' ? 'Walk-in' : 'Booking' ?></td>
      <td data-label="Status"><?= View::statusPill($r['status']) ?><?php if ($r['status'] === 'cancelled' && $r['cancel_reason']): ?><span class="sub"><?= View::e($r['cancel_reason']) ?></span><?php endif; ?></td>
      <?php // The real time in the gym compared with the booked time: left early, stayed longer, or automatic. ?>
      <td data-label="In the gym"><?php if (!$r['checked_in_at']): ?>–<?php else: ?>
        <span class="nowrap"><?= View::e(View::date($r['checked_in_at'], 'H:i')) ?> – <?= $r['checked_out_at'] ? View::e(View::date($r['checked_out_at'], 'H:i')) : 'now' ?></span>
        <?php if ($r['checked_out_at']): $mins = View::minutesBetween($r['checked_in_at'], $r['checked_out_at']); ?>
          <span class="sub"><?= View::e(View::duration($mins)) ?><?php
            if ($r['auto_checked_out']) echo $r['checkout_reported_at'] ? ' · auto check-out, time reported by resident' : ' · automatic check-out, waiting for resident';
            elseif ($r['checked_out_at'] < $r['end_at']) echo ' · left early';
            elseif ($r['checked_out_at'] > $r['end_at']) echo ' · stayed longer';
          ?></span>
        <?php endif; endif; ?></td>
      <td class="row-actions-cell"><div class="row-actions">
        <?php if ($r['status'] === 'booked'): ?><button class="btn btn-small btn-danger" type="button" data-cancel-booking="<?= (int) $r['id'] ?>" data-label="<?= View::e($r['full_name'] . ' · ' . $r['zone_name'] . ' · ' . View::timeRange($r['start_at'], $r['end_at'])) ?>">Cancel</button><?php endif; ?>
        <?php if ($r['status'] === 'checked_in'): ?><button class="btn btn-small" type="button" data-checkout="<?= (int) $r['id'] ?>" data-name="<?= View::e($r['full_name']) ?>">Check out</button><?php endif; ?>
      </div></td>
    </tr>
  <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>

<?php // The dialog for cancelling with a reason, which is emailed to the resident. ?>
<dialog class="modal" id="cancel-modal" aria-labelledby="cancel-title">
  <div class="modal-head"><h2 id="cancel-title">Cancel booking</h2><button class="modal-close" type="button" data-close aria-label="Close"><?= View::icon('x') ?></button></div>
  <div class="modal-body">
    <form class="form" id="cancel-form" data-ajax>
      <div class="alert alert-error modal-error" role="alert" hidden></div>
      <input type="hidden" name="id">
      <p class="small" id="cancel-label"></p>
      <div class="field"><label for="cancel-reason">Reason (sent to the resident)</label><input id="cancel-reason" name="reason" required maxlength="255" placeholder="For example: Equipment out of order"></div>
      <div class="modal-actions"><button class="btn" type="button" data-close>Keep booking</button><button class="btn btn-danger-solid" type="submit">Cancel booking</button></div>
    </form>
  </div>
</dialog>
<?php View::footer(['admin-bookings.js']);
