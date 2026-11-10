<?php
// Admin: one resident's details, with editing, invitation, deactivation and deletion.
require __DIR__ . '/../app/bootstrap.php';
$admin = Auth::requirePage('admin');
// The resident from ?id=..., or a "not found" page.
$r = Residents::find((int) ($_GET['id'] ?? 0));
if (!$r) View::errorPage('Resident not found', 'This resident does not exist.', 'admin/residents.php');
$bookings = Residents::recentBookings((int) $r['id']);
// Is the latest invitation still valid?
$inviteValid = $r['invite_expires'] && new DateTimeImmutable($r['invite_expires']) > Clock::now();
View::header($r['full_name'], 'residents');
?>
<p><a href="residents.php" class="small">← All residents</a></p>
<div class="page-head">
  <div><h1><?= View::e($r['full_name']) ?></h1><p class="muted">Apartment <?= View::e($r['apartment']) ?> · <?= View::statusPill($r['status']) ?></p></div>
  <?php // The buttons depend on the status: Resend for pending accounts; Reactivate or Deactivate. ?>
  <div class="button-row">
    <?php if ($r['status'] === 'pending'): ?><button class="btn" type="button" data-action="resend" data-id="<?= (int) $r['id'] ?>"><?= View::icon('mail') ?>Resend invitation</button><?php endif; ?>
    <?php if ($r['status'] === 'inactive'): ?>
      <button class="btn btn-primary" type="button" data-action="activate" data-id="<?= (int) $r['id'] ?>">Reactivate account</button>
    <?php else: ?>
      <button class="btn btn-danger" type="button" data-action="deactivate" data-id="<?= (int) $r['id'] ?>" data-name="<?= View::e($r['full_name']) ?>">Deactivate</button>
    <?php endif; ?>
  </div>
</div>

<div class="grid grid-2">
  <section class="card">
    <h2>Account</h2>
    <dl class="info-list">
      <div class="info-row"><div><dt>Email</dt><dd><?= View::e($r['email']) ?></dd></div></div>
      <div class="info-row"><div><dt>Username</dt><dd>@<?= View::e($r['username']) ?></dd></div></div>
      <div class="info-row"><div><dt>Added</dt><dd><?= View::e(View::date($r['created_at'], 'j M Y')) ?></dd></div></div>
      <?php if ($r['status'] === 'pending'): ?>
      <div class="info-row"><div><dt>Invitation</dt><dd><?= $inviteValid ? 'Valid until ' . View::e(View::date($r['invite_expires'], 'j M Y, H:i')) : 'Expired or not sent. Send a new invitation.' ?></dd></div></div>
      <?php else: ?>
      <div class="info-row"><div><dt>Activated</dt><dd><?= $r['activated_at'] ? View::e(View::date($r['activated_at'], 'j M Y')) : '–' ?></dd></div></div>
      <div class="info-row"><div><dt>Last sign-in</dt><dd><?= $r['last_login_at'] ? View::e(View::date($r['last_login_at'], 'j M Y, H:i')) : 'Not yet' ?></dd></div></div>
      <?php endif; ?>
    </dl>
  </section>
  <section class="card">
    <?php // Edit the name and apartment (and the email while the account is pending). ?>
    <h2>Edit details</h2>
    <form class="form" id="edit-form" data-ajax data-id="<?= (int) $r['id'] ?>">
      <div class="field"><label for="full_name">Full name</label><input id="full_name" name="full_name" required maxlength="100" value="<?= View::e($r['full_name']) ?>"></div>
      <div class="field"><label for="apartment">Apartment number</label><input id="apartment" name="apartment" required maxlength="20" list="apartments" value="<?= View::e($r['apartment']) ?>"></div>
      <?php if ($r['status'] === 'pending'): ?>
      <div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" required value="<?= View::e($r['email']) ?>"><span class="hint">Can be corrected until the account is activated. Send a new invitation after changing it.</span></div>
      <?php endif; ?>
      <div><button class="btn btn-primary" type="submit">Save changes</button></div>
    </form>
  </section>
</div>

<section class="section">
  <div class="section-head"><h2>Recent sessions</h2></div>
  <?php if (!$bookings): ?><div class="card empty-state"><p>No sessions yet.</p></div><?php else: ?>
  <div class="table-wrap"><table class="table-responsive">
    <thead><tr><th>Date</th><th>Time</th><th>Zone</th><th>Type</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($bookings as $b): ?>
      <tr><td data-label="Date"><?= View::e(View::date($b['start_at'], 'D j M Y')) ?></td><td data-label="Time"><?= View::e(View::timeRange($b['start_at'], $b['end_at'])) ?></td>
          <td data-label="Zone"><?= View::e($b['zone_name']) ?></td><td data-label="Type"><?= $b['source'] === 'walk_in' ? 'Walk-in' : 'Booking' ?></td><td data-label="Status"><?= View::statusPill($b['status']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</section>
<?php // Delete is hidden in a collapsed section. ?>
<details class="danger-zone">
  <summary>Delete this account</summary>
  <div class="danger-body">
    <p class="small">Removes the resident, their bookings and workout history permanently. The resident receives an email. Use this when someone moves out; to pause access, use Deactivate instead.</p>
    <button class="btn btn-danger btn-small" type="button" data-action="delete" data-id="<?= (int) $r['id'] ?>" data-name="<?= View::e($r['full_name']) ?>">Delete account…</button>
  </div>
</details>
<datalist id="apartments"><?php foreach (Residents::apartmentNumbers() as $n): ?><option value="<?= View::e($n) ?>"><?php endforeach; ?></datalist>
<?php View::footer(['admin-residents.js']);
