<?php
// Settings: username, email change, password change, sign-out and account deletion.
require __DIR__ . '/app/bootstrap.php';
$user = Auth::requirePage('resident');
// An email change that is still waiting for confirmation, if there is one.
$pending = Account::pendingEmailChange((int) $user['id']);
View::header('Settings', 'settings', ['mainClass' => 'narrow-wide']);
?>
<div class="page-head"><div><h1>Settings</h1><p class="muted">Your username, email address and password.</p></div></div>

<section class="settings-group" aria-labelledby="account-title">
  <h2 id="account-title">Account</h2>
  <div class="card stack">
    <?php // Username: saved straight away by account.js (api/account.php, action "username"). ?>
    <form class="inline-form-block" id="username-form" data-ajax novalidate>
      <label for="username"><strong>Username</strong></label>
      <div class="inline-form">
        <input id="username" name="username" value="<?= View::e($user['username']) ?>" required minlength="3" maxlength="30" autocomplete="nickname" aria-describedby="username-hint">
        <button class="btn btn-primary" type="submit">Save</button>
      </div>
      <span class="hint small muted" id="username-hint">3 to 30 characters: letters, digits, dots, hyphens and underscores.</span>
    </form>
    <hr class="divider">
    <?php // Email change opens a dialog; the change waits for the link sent to the current address. ?>
    <div class="settings-item">
      <div><strong>Email address</strong><p class="muted small" id="email-value"><?= View::e($user['email']) ?></p>
        <p class="small muted" id="email-pending"<?= $pending ? '' : ' hidden' ?>>Change to <strong id="email-pending-value"><?= View::e($pending ?? '') ?></strong> is waiting for your confirmation. Open the link we sent to <?= View::e($user['email']) ?>.</p></div>
      <button class="btn" type="button" data-open-modal="email-modal"><?= View::icon('mail') ?>Change email</button>
    </div>
  </div>
</section>

<?php // Password change: also confirmed by email before the new password works. ?>
<section class="settings-group section" aria-labelledby="security-title">
  <h2 id="security-title">Security</h2>
  <div class="card">
    <div class="settings-item">
      <div><strong>Change password</strong><p class="muted small">We email a confirmation link to <?= View::e($user['email']) ?> before the new password is saved.</p></div>
      <button class="btn" type="button" data-open-modal="password-modal"><?= View::icon('lock') ?>Change password</button>
    </div>
  </div>
</section>

<section class="settings-group section" aria-labelledby="more-title">
  <h2 id="more-title">Help and session</h2>
  <div class="card stack">
    <a class="settings-link" href="help.php"><span><strong>Help and contact</strong><span class="muted small">How GymZone works, and how to reach the housing office</span></span><?= View::icon('chevron') ?></a>
    <hr class="divider">
    <form class="settings-item" method="post" action="logout.php">
      <?= Auth::csrfField() ?>
      <div><strong>Sign out</strong><p class="muted small">You are also signed out after <?= Auth::IDLE_MINUTES / 60 ?> hours without activity.</p></div>
      <button class="btn" type="submit"><?= View::icon('logout') ?>Sign out</button>
    </form>
  </div>
</section>

<?php // Account deletion, hidden in a collapsed section to avoid accidents. ?>
<details class="danger-zone">
  <summary>Delete my account</summary>
  <div class="danger-body">
    <p class="small">Deleting your account removes your details, bookings and workout history from GymZone for good. Upcoming bookings are cancelled. To use the gym again later you would need a new invitation from the housing office.</p>
    <button class="btn btn-danger btn-small" type="button" data-open-modal="delete-modal">Delete my account…</button>
  </div>
</details>

<?php // The three dialogs used above: email, password and delete. ?>
<dialog class="modal" id="email-modal" aria-labelledby="email-title">
  <div class="modal-head"><h2 id="email-title">Change email address</h2><button class="modal-close" type="button" data-close aria-label="Close"><?= View::icon('x') ?></button></div>
  <div class="modal-body">
    <form class="form" id="email-form" data-ajax novalidate>
      <p class="small">We send a confirmation link to your current address, <strong><?= View::e($user['email']) ?></strong>. Your email changes only after you open that link.</p>
      <div class="alert alert-error modal-error" role="alert" hidden></div>
      <div class="field"><label for="new-email">New email address</label><input id="new-email" name="email" type="email" required autocomplete="email"></div>
      <div class="field"><label for="new-email-2">Repeat new email address</label><input id="new-email-2" name="email_repeat" type="email" required autocomplete="off"></div>
      <?= View::passwordField('current_password', 'Current password', 'current-password', 'For your security, confirm it is you.') ?>
      <div class="modal-actions"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Send link</button></div>
    </form>
    <div class="modal-done" hidden><div class="alert alert-success" role="status"></div><div class="modal-actions"><button class="btn btn-primary" type="button" data-close>Done</button></div></div>
  </div>
</dialog>

<dialog class="modal" id="password-modal" aria-labelledby="password-title">
  <div class="modal-head"><h2 id="password-title">Change password</h2><button class="modal-close" type="button" data-close aria-label="Close"><?= View::icon('x') ?></button></div>
  <div class="modal-body">
    <form class="form" id="password-form" data-ajax novalidate>
      <div class="alert alert-error modal-error" role="alert" hidden></div>
      <input type="email" name="username_hint" value="<?= View::e($user['email']) ?>" autocomplete="username" hidden>
      <?= View::passwordField('current_password', 'Current password', 'current-password') ?>
      <?= View::passwordField('new_password', 'New password', 'new-password', View::PASSWORD_HINT) ?>
      <?= View::passwordField('new_password_confirm', 'Repeat new password', 'new-password') ?>
      <div class="modal-actions"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Send link</button></div>
    </form>
    <div class="modal-done" hidden><div class="alert alert-success" role="status"></div><div class="modal-actions"><button class="btn btn-primary" type="button" data-close>Done</button></div></div>
  </div>
</dialog>

<dialog class="modal" id="delete-modal" aria-labelledby="delete-title">
  <div class="modal-head"><h2 id="delete-title">Delete your account?</h2><button class="modal-close" type="button" data-close aria-label="Close"><?= View::icon('x') ?></button></div>
  <div class="modal-body">
    <form class="form" id="delete-form" data-ajax novalidate>
      <p class="small">This cannot be undone. Your bookings and history are removed, and you will receive a confirmation email.</p>
      <div class="alert alert-error modal-error" role="alert" hidden></div>
      <?= View::passwordField('delete_password', 'Your password', 'current-password') ?>
      <div class="field"><label for="delete-word">Type DELETE to confirm</label><input id="delete-word" name="confirm_word" required autocomplete="off" autocapitalize="characters"></div>
      <div class="modal-actions"><button class="btn" type="button" data-close>Keep my account</button><button class="btn btn-danger-solid" type="submit">Delete account</button></div>
    </form>
  </div>
</dialog>
<?php View::footer(['account.js']);
