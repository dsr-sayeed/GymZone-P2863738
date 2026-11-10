<?php
// Admin: zones and capacities, opening hours and rules, and closures, in three tabs.
require __DIR__ . '/../app/bootstrap.php';
$admin = Auth::requirePage('admin');
// Everything the three tabs need.
$zones = Zones::all();
$hours = Schedule::hours();
$closures = Schedule::upcomingClosures();
$set = Settings::all();
// Which tab is open.
$tab = in_array($_GET['tab'] ?? '', ['zones', 'hours', 'closures'], true) ? $_GET['tab'] : 'zones';
$tabs = ['zones' => 'Zones', 'hours' => 'Hours and rules', 'closures' => 'Closures'];
// Shows 06:00:00 as 06:00.
$hhmm = fn($t) => substr($t, 0, 5);
View::header('Manage gym', 'gym');
?>
<div class="page-head"><div><h1>Manage gym</h1><p class="muted">Zones and capacities, opening hours, booking rules and closures. Changes apply immediately.</p></div></div>

<div data-tabs>
  <div class="tabs admin-tabs" role="tablist" aria-label="Gym settings">
    <?php foreach ($tabs as $k => $l): ?><button type="button" role="tab" id="tab-<?= $k ?>" aria-controls="panel-<?= $k ?>" aria-selected="<?= $k === $tab ? 'true' : 'false' ?>"><?= $l ?></button><?php endforeach; ?>
  </div>

  <section role="tabpanel" id="panel-zones" aria-labelledby="tab-zones"<?= $tab === 'zones' ? '' : ' hidden' ?>>
    <div class="stack">
    <?php // One card per zone, with the most people booked at the same time (the capacity cannot go below it). ?>
    <?php foreach ($zones as $z): $peak = Zones::peakAllocation((int) $z['id']); ?>
      <article class="card">
        <div class="section-head"><h2><?= View::e($z['name']) ?></h2><?= $z['is_open'] ? '<span class="pill pill-available">Open</span>' : '<span class="pill pill-closed">Temporarily closed</span>' ?></div>
        <form class="zone-edit" data-zone-form data-ajax>
          <input type="hidden" name="id" value="<?= (int) $z['id'] ?>">
          <div class="field"><label for="zn-<?= $z['id'] ?>">Name</label><input id="zn-<?= $z['id'] ?>" name="name" value="<?= View::e($z['name']) ?>" required maxlength="60"></div>
          <div class="field"><label for="zd-<?= $z['id'] ?>">Description</label><input id="zd-<?= $z['id'] ?>" name="description" value="<?= View::e($z['description']) ?>" maxlength="255"></div>
          <div class="field"><label for="zc-<?= $z['id'] ?>">Capacity</label><input id="zc-<?= $z['id'] ?>" name="capacity" type="number" min="1" max="500" value="<?= (int) $z['capacity'] ?>" required></div>
          <button class="btn btn-primary" type="submit">Save</button>
        </form>
        <p class="small muted">Most people booked at the same time from now on: <?= $peak['count'] ?><?= $peak['start'] ? ' (' . View::e(View::relDate($peak['start']) . ' ' . View::date($peak['start'], 'H:i')) . ')' : '' ?>. The capacity cannot go below this.</p>
        <div class="settings-item">
          <?php if ($z['is_open']): ?>
            <p class="muted small">Close the zone if it cannot be used, for example during repairs. Residents cannot book it until you reopen it.</p>
            <button class="btn btn-danger" type="button" data-zone-close="<?= (int) $z['id'] ?>" data-name="<?= View::e($z['name']) ?>">Close temporarily</button>
          <?php else: ?>
            <p class="small">Closed<?= $z['closed_note'] ? ': ' . View::e($z['closed_note']) : '' ?></p>
            <button class="btn btn-primary" type="button" data-zone-open="<?= (int) $z['id'] ?>">Reopen zone</button>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
    </div>
  </section>

  <?php // Tab 2: weekly opening hours and the booking rules. ?>
  <section role="tabpanel" id="panel-hours" aria-labelledby="tab-hours"<?= $tab === 'hours' ? '' : ' hidden' ?>>
    <form class="card form" id="hours-form" data-ajax>
      <h2>Weekly opening hours</h2>
      <div class="table-wrap card-flat"><table class="hours-table">
        <thead><tr><th>Day</th><th>Open</th><th>From</th><th>Until</th></tr></thead>
        <tbody>
        <?php foreach (Schedule::WEEKDAYS as $wd => $name): $h = $hours[$wd]; ?>
          <tr data-day="<?= $wd ?>">
            <td><strong><?= $name ?></strong></td>
            <td><label class="check"><input type="checkbox" data-f="is_open" <?= $h['is_open'] ? 'checked' : '' ?> aria-label="<?= $name ?> open"> Open</label></td>
            <td><input type="text" data-f="open" value="<?= $hhmm($h['open_time']) ?>" pattern="([01]\d|2[0-3]):[0-5]\d" inputmode="numeric" required aria-label="<?= $name ?> opening time" class="time-text"></td>
            <td><input type="text" data-f="close" value="<?= $hhmm($h['close_time']) ?>" pattern="([01]\d|2[0-3]):[0-5]\d|24:00" inputmode="numeric" required aria-label="<?= $name ?> closing time" class="time-text"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <p class="hint small">Times use the 24-hour clock. A day from 00:00 to 24:00 is open around the clock. If upcoming bookings fall outside new hours, you are asked before they are cancelled; residents are notified by email.</p>
      <div><button class="btn btn-primary" type="submit">Save opening hours</button></div>
    </form>

    <?php // The rules (check-in times, lengths, automatic check-out) go to api/admin/gym.php, action "rules". ?>
    <form class="card form section" id="rules-form" data-ajax>
      <h2>Booking and check-in rules</h2>
      <div class="form-row">
        <div class="field"><label for="session_minutes">Suggested session length (minutes)</label><input id="session_minutes" name="session_minutes" type="number" min="15" max="360" step="5" value="<?= (int) $set['session_minutes'] ?>">
          <span class="hint">Pre-selected when booking and for walk-ins. Residents can choose another length.</span></div>
        <div class="field"><label for="max_session_minutes">Longest session (minutes)</label><input id="max_session_minutes" name="max_session_minutes" type="number" min="15" max="360" step="15" value="<?= (int) $set['max_session_minutes'] ?>"></div>
        <div class="field"><label for="checkin_early_minutes">Check-in opens before the start (minutes)</label><input id="checkin_early_minutes" name="checkin_early_minutes" type="number" min="0" max="60" value="<?= (int) $set['checkin_early_minutes'] ?>"></div>
        <div class="field"><label for="checkin_grace_minutes">Check-in closes after the start (minutes)</label><input id="checkin_grace_minutes" name="checkin_grace_minutes" type="number" min="5" max="60" value="<?= (int) $set['checkin_grace_minutes'] ?>">
          <span class="hint">A booking not checked in by then is released so others can use the place. This has no other effect on the resident.</span></div>
        <div class="field"><label for="auto_checkout_hours">Automatic check-out after (hours)</label><input id="auto_checkout_hours" name="auto_checkout_hours" type="number" min="1" max="12" value="<?= (int) $set['auto_checkout_hours'] ?>">
          <span class="hint">Residents still checked in after this long are checked out and asked when they really left.</span></div>
      </div>
      <div><button class="btn btn-primary" type="submit">Save rules</button></div>
    </form>
  </section>

  <?php // Tab 3: plan a closure, and the list of upcoming closures. ?>
  <section role="tabpanel" id="panel-closures" aria-labelledby="tab-closures"<?= $tab === 'closures' ? '' : ' hidden' ?>>
    <form class="card form" id="closure-form" data-ajax>
      <h2>Plan a closure</h2>
      <p class="muted small">Close the whole gym or one zone for a period, for example for maintenance or a holiday. Upcoming bookings in that period are cancelled after you confirm, and the residents are emailed.</p>
      <div class="form-row">
        <div class="field"><label for="c-zone">What is closed</label><select id="c-zone" name="zone_id"><option value="">The whole gym</option><?php foreach ($zones as $z): ?><option value="<?= (int) $z['id'] ?>"><?= View::e($z['name']) ?> only</option><?php endforeach; ?></select></div>
        <div class="field"><label for="c-reason">Reason (shown to residents)</label><input id="c-reason" name="reason" maxlength="255" required placeholder="For example: Floor cleaning"></div>
        <div class="field"><label for="c-start">From</label><input id="c-start" name="starts_at" type="datetime-local" required></div>
        <div class="field"><label for="c-end">Until</label><input id="c-end" name="ends_at" type="datetime-local" required></div>
      </div>
      <div><button class="btn btn-primary" type="submit">Add closure</button></div>
    </form>
    <section class="section">
      <div class="section-head"><h2>Upcoming closures</h2></div>
      <?php if (!$closures): ?><div class="card empty-state"><p>No closures are planned.</p></div><?php else: ?>
      <div class="table-wrap"><table class="table-responsive">
        <thead><tr><th>Area</th><th>From</th><th>Until</th><th>Reason</th><th><span class="sr-only">Actions</span></th></tr></thead>
        <tbody><?php foreach ($closures as $c): ?>
          <tr><td data-label="Area"><?= View::e($c['zone_name'] ?? 'Whole gym') ?></td><td data-label="From"><?= View::e(View::date($c['starts_at'], 'D j M Y, H:i')) ?></td>
              <td data-label="Until"><?= View::e(View::date($c['ends_at'], 'D j M Y, H:i')) ?></td><td data-label="Reason"><?= View::e($c['reason']) ?></td>
              <td class="row-actions-cell"><div class="row-actions"><button class="btn btn-small btn-danger" type="button" data-closure-delete="<?= (int) $c['id'] ?>">Remove</button></div></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <?php endif; ?>
    </section>
  </section>
</div>

<?php // The dialog that asks for the reason when a zone is closed. ?>
<dialog class="modal" id="close-modal" aria-labelledby="close-title">
  <div class="modal-head"><h2 id="close-title">Close zone</h2><button class="modal-close" type="button" data-close aria-label="Close"><?= View::icon('x') ?></button></div>
  <div class="modal-body">
    <form class="form" id="close-form" data-ajax>
      <div class="alert alert-error modal-error" role="alert" hidden></div>
      <input type="hidden" name="id">
      <p class="small">The zone stays closed until you reopen it. Residents see the reason on the dashboard.</p>
      <div class="field"><label for="close-note">Reason</label><input id="close-note" name="note" required maxlength="255" placeholder="For example: Treadmill repairs"></div>
      <div class="modal-actions"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-danger-solid" type="submit">Close zone</button></div>
    </form>
  </div>
</dialog>
<?php View::footer(['admin-gym.js']);
