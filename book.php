<?php
// Booking page. The page itself is mostly empty boxes; booking.js fills them from the API.
require __DIR__ . '/app/bootstrap.php';
$user = Auth::requirePage('resident');
// How many days can be booked (21), used in the text below.
$days = Settings::int('booking_window_days');
View::header('Book a session', 'book');
?>
<div class="page-head">
  <div><h1>Book a session</h1><p class="muted">Choose a day, a zone, the time you want to start and how long you will train. You can book today and up to <?= $days - 1 ?> days ahead.</p></div>
</div>

<?php // A zone and a date can be passed in the link (from a zone card's Book button). ?>
<div id="booking" data-zone="<?= (int) ($_GET['zone'] ?? 0) ?>" data-date="<?= View::e(preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : '') ?>">
  <?php // The four steps at the top; booking.js marks the current one. ?>
  <ol class="stepper" aria-label="Booking steps">
    <li class="step" data-step="1"><b>1</b>Date</li>
    <li class="step" data-step="2"><b>2</b>Zone</li>
    <li class="step" data-step="3"><b>3</b>Time</li>
    <li class="step" data-step="4"><b>4</b>Confirm</li>
  </ol>

  <div class="booking-layout" id="booking-main">
    <div class="stack">
      <?php // Step 1: the 21-day calendar, split into weeks. ?>
      <section class="card" aria-labelledby="h-date">
        <h2 id="h-date">1. Choose a date</h2>
        <div class="week-tabs" role="tablist" id="week-tabs" aria-label="Weeks"></div>
        <div class="calendar" id="calendar" aria-live="polite"><div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div></div>
        <div class="legend" aria-hidden="true"><span><i class="l-open"></i>Open</span><span><i class="l-closed"></i>Closed or over</span><span><i class="l-mine"></i>You have a session</span></div>
      </section>
      <?php // Step 2: one button per zone. ?>
      <section class="card" aria-labelledby="h-zone">
        <h2 id="h-zone">2. Choose a zone</h2>
        <div class="zone-picker" id="zone-picker"></div>
      </section>
      <?php // Step 3: start time (any minute) and length, the live check result, and the hour-by-hour grid. ?>
      <section class="card" aria-labelledby="h-time">
        <h2 id="h-time">3. Choose a time</h2>
        <p class="muted small" id="day-caption">Choose a date and a zone first.</p>
        <div class="form-row time-row">
          <div class="field"><label for="start-time">Start time</label><input id="start-time" type="time" step="60" required></div>
          <div class="field"><label for="minutes">Length</label><select id="minutes"><?= Components::durationOptions() ?></select></div>
        </div>
        <div class="check-result" id="check-result" aria-live="polite" hidden></div>
        <h3 class="hours-title">Free places through the day</h3>
        <p class="muted small">Tap an hour to start then. The number is the lowest number of free places during that hour.</p>
        <div class="hour-grid" id="hours" aria-live="polite"></div>
      </section>
    </div>

    <?php // Step 4: the summary and the Confirm button (enabled only when the live check passes). ?>
    <aside class="card summary" id="summary" aria-labelledby="h-summary">
      <h2 id="h-summary">4. Review and confirm</h2>
      <dl>
        <dt>Date</dt><dd id="s-date">–</dd>
        <dt>Zone</dt><dd id="s-zone">–</dd>
        <dt>Time</dt><dd id="s-time">–</dd>
        <dt>Length</dt><dd id="s-length">–</dd>
        <dt>Free places</dt><dd id="s-left">–</dd>
      </dl>
      <button class="btn btn-primary btn-block" type="button" id="confirm" disabled>Confirm booking</button>
      <p class="muted small" id="summary-hint">Choose a start time to continue.</p>
    </aside>
  </div>

  <?php // Shown after a successful booking. ?>
  <section class="card confirmation" id="confirmation" hidden tabindex="-1" aria-live="polite">
    <div class="tick"><?= View::icon('check') ?></div>
    <h2>Booking confirmed</h2>
    <p class="muted" id="c-details"></p>
    <p class="small muted">Check in when you arrive: check-in opens <?= Settings::int('checkin_early_minutes') ?> minutes before your start time. If you do not check in within <?= Settings::int('checkin_grace_minutes') ?> minutes of the start, the place is given to someone else.</p>
    <div class="button-row" id="c-actions">
      <a class="btn btn-primary" href="bookings.php">View my bookings</a>
      <a class="btn" href="book.php">Book another session</a>
    </div>
  </section>
</div>
<?php View::footer(['booking.js']);
