<?php
/** Reusable pieces of resident and admin pages. */
final class Components
{
    // The labels shown on the zone cards for each status.
    public const STATUS_LABELS = ['available' => 'Available', 'limited' => 'Few places left', 'full' => 'Full', 'closed' => 'Closed'];

    /** A zone availability card. $canWalkIn: show "Check in now" (gym open, resident not checked in). */
    public static function zoneCard(array $z, bool $canWalkIn, bool $showActions = true): string
    {
        // The percentage of places taken, for the coloured bar.
        $used = $z['capacity'] ? (int) round(100 * ($z['capacity'] - $z['available']) / $z['capacity']) : 0;
        // ob_start() collects the HTML below into a string instead of sending it; ob_get_clean() returns it.
        ob_start(); ?>
<?php // One card per zone. The data-field attributes let dashboard.js update the numbers every 15 seconds. ?>
<article class="card zone-card status-<?= View::e($z['status']) ?>" data-zone-id="<?= (int) $z['id'] ?>">
  <div class="zone-head">
    <div><h3><?= View::e($z['name']) ?></h3><p class="zone-desc"><?= View::e($z['description']) ?></p></div>
    <span class="pill pill-<?= View::e($z['status']) ?>" data-field="pill"><?= View::e(self::STATUS_LABELS[$z['status']]) ?></span>
  </div>
  <?php // The zone notice, for example "1 treadmill is out of order" (hidden when there is none). ?>
  <p class="zone-notice" data-field="notice"<?= empty($z['notice']) ? ' hidden' : '' ?>><?= View::e($z['notice'] ?? '') ?></p>
  <div class="zone-count"><strong data-field="available"><?= (int) $z['available'] ?></strong><span class="muted">of <span data-field="capacity"><?= (int) $z['capacity'] ?></span> places available</span></div>
  <div class="meter" role="img" aria-label="<?= $used ?>% of places taken"><span data-field="bar" data-pct="<?= $used ?>"></span></div>
  <div class="zone-meta"><span><b data-field="checked_in"><?= (int) $z['checked_in'] ?></b> in the gym</span><span><b data-field="booked"><?= (int) $z['booked'] ?></b> booked now, not yet arrived</span></div>
  <p class="zone-closed-note" data-field="closed"<?= $z['closed'] ? '' : ' hidden' ?>><?= View::e($z['closed'] ?? '') ?></p>
  <?php // Buttons: Book, and Check in (a walk-in), which is disabled when the zone is full or closed. ?>
  <?php if ($showActions): ?>
  <div class="zone-actions">
    <a class="btn" href="<?= View::e(Http::url('book.php?zone=' . (int) $z['id'])) ?>"><?= View::icon('calendar') ?>Book</a>
    <?php if ($canWalkIn): ?><button class="btn btn-success" type="button" data-walkin data-zone="<?= (int) $z['id'] ?>" data-zone-name="<?= View::e($z['name']) ?>" aria-label="Check in to <?= View::e($z['name']) ?> now"<?= $z['status'] === 'full' || $z['status'] === 'closed' ? ' disabled' : '' ?>><?= View::icon('check') ?>Check in</button><?php endif; ?>
  </div>
  <?php endif; ?>
</article>
<?php return ob_get_clean();
    }

    /** A booking row for "My bookings" and the dashboard. */
    public static function bookingItem(array $b, string $mode): string
    {
        // $mode is the tab: upcoming, completed or cancelled.
        $start = new DateTimeImmutable($b['start']);
        ob_start(); ?>
<?php // One row in My bookings: date, zone, time, the real time in the gym, status and buttons. ?>
<article class="card booking-item" data-booking-id="<?= (int) $b['id'] ?>">
  <div class="booking-date" aria-hidden="true"><div class="bm"><?= $start->format('M') ?></div><div class="bd"><?= $start->format('j') ?></div></div>
  <div class="booking-info">
    <strong><?= View::e($b['zone']) ?> · <?= View::e($b['time']) ?></strong>
    <span class="muted"><?= View::e($b['date']) ?> · <?= View::e($b['duration']) ?><?= isset($b['note']) ? ' · ' . View::e($b['note']) : '' ?></span>
    <?php if (!empty($b['actual'])): ?>
      <span class="actual">In the gym <?= View::e($b['actual']) ?> (<?= View::e($b['actual_duration']) ?>)<?= !empty($b['auto']) ? ($b['reported'] ? ' · check-out time reported by you' : ' · checked out automatically') : '' ?></span>
    <?php elseif ($b['status'] === 'checked_in' && !empty($b['checked_in'])): ?>
      <span class="actual">Checked in at <?= View::e($b['checked_in']) ?></span>
    <?php endif; ?>
  </div>
  <div class="actions">
    <?= View::statusPill($b['status']) ?>
<?php // Booked and upcoming: Check in (only inside the check-in window) and Cancel. ?>
    <?php if ($mode === 'upcoming' && $b['status'] === 'booked'): ?>
      <?php if (!empty($b['can_check_in'])): ?><button class="btn btn-success btn-small" type="button" data-attendance="checkin" data-booking="<?= (int) $b['id'] ?>">Check in</button><?php endif; ?>
      <button class="btn btn-danger btn-small" type="button" data-cancel="<?= (int) $b['id'] ?>" data-label="<?= View::e($b['zone'] . ', ' . $b['date'] . ' at ' . $b['time']) ?>">Cancel</button>
    <?php // Checked in now: Check out. ?>
    <?php elseif ($mode === 'upcoming' && $b['status'] === 'checked_in'): ?>
      <button class="btn btn-primary btn-small" type="button" data-attendance="checkout">Check out</button>
    <?php endif; ?>
  </div>
</article>
<?php return ob_get_clean();
    }

    /** <option> elements for session lengths: every 15 minutes up to the maximum. */
    public static function durationOptions(?int $selected = null): string
    {
        // From 15 minutes up to the longest session, in 15-minute steps.
        $selected ??= Settings::int('session_minutes');
        $html = '';
        for ($m = Bookings::MIN_MINUTES; $m <= Settings::int('max_session_minutes'); $m += 15) {
            $html .= '<option value="' . $m . '"' . ($m === $selected ? ' selected' : '') . '>' . View::e(View::duration($m)) . '</option>';
        }
        return $html;
    }

    // emptyState(): a friendly message, with an optional button, when a list is empty.
    public static function emptyState(string $icon, string $message, ?array $action = null): string
    {
        return '<div class="card empty-state">' . View::icon($icon) . '<p>' . View::e($message) . '</p>'
            . ($action ? '<a class="btn btn-primary" href="' . View::e(Http::url($action[1])) . '">' . View::e($action[0]) . '</a>' : '') . '</div>';
    }
}
