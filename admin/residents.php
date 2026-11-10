<?php
// Admin: list and search residents, and add a new resident.
require __DIR__ . '/../app/bootstrap.php';
$admin = Auth::requirePage('admin');
// The search text and status filter from the address (?q=...&status=...).
$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$rows = Residents::search($q, $status);
$counts = Residents::counts();
$now = Clock::now();
View::header('Residents', 'residents');
?>
<div class="page-head">
  <div><h1>Residents</h1><p class="muted"><?= $counts['active'] ?> active · <?= $counts['pending'] ?> waiting for activation · <?= $counts['inactive'] ?> deactivated</p></div>
  <button class="btn btn-primary" type="button" data-open-modal="add-resident"><?= View::icon('plus') ?>Add resident</button>
</div>

<?php // The search form uses GET, so a search can be bookmarked. ?>
<form method="get" class="filters" role="search">
  <div class="field grow"><label for="q">Search</label>
    <div class="search-input"><?= View::icon('search') ?><input id="q" type="search" name="q" value="<?= View::e($q) ?>" placeholder="Name, email, username or apartment"></div></div>
  <div class="field"><label for="status">Status</label>
    <select id="status" name="status" data-autosubmit>
      <option value="">All residents</option>
      <?php foreach (['active' => 'Active', 'pending' => 'Waiting for activation', 'inactive' => 'Deactivated'] as $k => $l): ?>
        <option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select></div>
  <button class="btn" type="submit">Search</button>
</form>

<?php if (!$rows): ?>
  <div class="card empty-state"><?= View::icon('users') ?><p><?= $q !== '' || $status !== '' ? 'No residents match this search.' : 'No residents yet. Add the first resident to send them an invitation.' ?></p>
  <?php if ($q === '' && $status === ''): ?><button class="btn btn-primary" type="button" data-open-modal="add-resident">Add resident</button><?php endif; ?></div>
<?php else: ?>
<div class="table-wrap">
<table class="table-responsive">
  <thead><tr><th>Resident</th><th>Apartment</th><th>Email</th><th>Status</th><th>Upcoming</th><th><span class="sr-only">Actions</span></th></tr></thead>
  <tbody>
  <?php // One row per resident; a pending invitation shows when it expires. ?>
  <?php foreach ($rows as $r): $expired = $r['status'] === 'pending' && (!$r['invite_expires'] || new DateTimeImmutable($r['invite_expires']) <= $now); ?>
    <tr>
      <td data-label="Resident"><a href="resident.php?id=<?= (int) $r['id'] ?>"><strong><?= View::e($r['full_name']) ?></strong></a><span class="sub">@<?= View::e($r['username']) ?></span></td>
      <td data-label="Apartment"><?= View::e($r['apartment']) ?></td>
      <td data-label="Email"><?= View::e($r['email']) ?></td>
      <td data-label="Status"><?= View::statusPill($r['status']) ?><?php if ($r['status'] === 'pending'): ?><span class="sub"><?= $expired ? 'Invitation expired' : 'Invitation valid until ' . View::e(View::date($r['invite_expires'], 'j M H:i')) ?></span><?php endif; ?></td>
      <td data-label="Upcoming"><?= (int) $r['upcoming'] ?></td>
      <td class="row-actions-cell"><div class="row-actions">
        <?php if ($r['status'] === 'pending'): ?><button class="btn btn-small" type="button" data-action="resend" data-id="<?= (int) $r['id'] ?>">Resend invitation</button><?php endif; ?>
        <a class="btn btn-small" href="resident.php?id=<?= (int) $r['id'] ?>">View</a>
      </div></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php // The Add resident dialog, sent by admin-residents.js to api/admin/residents.php. ?>
<dialog class="modal" id="add-resident" aria-labelledby="add-title">
  <div class="modal-head"><h2 id="add-title">Add resident</h2><button class="modal-close" type="button" data-close aria-label="Close"><?= View::icon('x') ?></button></div>
  <div class="modal-body">
    <p class="small">The resident receives an email with a link to choose a password. The link works once and expires after <?= Tokens::ACTIVATION_HOURS ?> hours.</p>
    <form class="form" id="add-form" data-ajax novalidate>
      <div class="alert alert-error modal-error" role="alert" hidden></div>
      <div class="field"><label for="full_name">Full name</label><input id="full_name" name="full_name" required maxlength="100" autocomplete="off"></div>
      <div class="field"><label for="apartment">Apartment number</label><input id="apartment" name="apartment" required maxlength="20" list="apartments" autocomplete="off" placeholder="For example 2B"><span class="hint">Several residents can share an apartment.</span></div>
      <div class="field"><label for="new_email">Email address</label><input id="new_email" name="email" type="email" required autocomplete="off"></div>
      <div class="modal-actions"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Add and send invitation</button></div>
    </form>
  </div>
</dialog>
<datalist id="apartments"><?php foreach (Residents::apartmentNumbers() as $n): ?><option value="<?= View::e($n) ?>"><?php endforeach; ?></datalist>
<?php View::footer(['admin-residents.js']);
