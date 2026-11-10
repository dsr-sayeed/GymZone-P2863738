<?php
/**
 * verify.php: confirmation page for email changes and password changes.
 *
 * The resident gets a link by email: verify.php?type=email|password&token=...
 * Opening the link only shows a Confirm button; the change is saved when the
 * button is pressed (a POST). The page shows one of three versions:
 * done, link not valid, or the Confirm button.
 */
require __DIR__ . '/app/bootstrap.php';

// Read the link. Any type other than 'password' is treated as 'email'.
$type = ($_GET['type'] ?? Http::str('type')) === 'password' ? 'password' : 'email';
$token = (string) ($_GET['token'] ?? Http::str('token'));

// Each token is stored with a purpose, so a password link cannot change an email (and vice versa).
$purpose = $type === 'password' ? 'password_change' : 'email_change';
$what = $type === 'password' ? 'password confirmation' : 'email confirmation';

$done = null;   // success message, set when the change is saved
$error = '';    // error message, set when a rule refuses the change

// The resident pressed Confirm: check the CSRF token, then save the change.
if (Http::isPost()) {
    Auth::verifyCsrf();
    try {
        if ($type === 'password') {
            // Activates the new password chosen earlier and signs out other devices.
            Account::confirmPasswordChange($token);
            $done = 'Your new password is now active. Other devices have been signed out.';
        } else {
            // Replaces the email address with the new one stored with the token.
            $r = Account::confirmEmailChange($token);
            $done = 'Your email address is now ' . $r['email'] . '. Use it the next time you sign in.';
        }
    } catch (UserError $e) {
        // A rule refused the change (for example, the link was already used): show the message.
        $error = $e->getMessage();
    }
}

// Check the link: 'valid', 'used', 'expired' or 'invalid'. Skipped after a successful change.
$check = $done ? ['state' => 'done', 'row' => null] : Tokens::lookup($token, $purpose);

// The link may be opened on a device where the resident is not signed in.
$signedIn = (bool) Auth::user();
View::header($type === 'password' ? 'Confirm password change' : 'Confirm email address', '', ['guest' => !$signedIn]);
?>
<section class="card auth-card">
<?php // 1. Done: the change was saved. ?>
<?php if ($done): ?>
  <h1><?= $type === 'password' ? 'Password changed' : 'Email address confirmed' ?></h1>
  <div class="alert alert-success"><?= View::e($done) ?></div>
  <a class="btn btn-primary btn-block" href="<?= $signedIn ? ($type === 'password' ? 'settings.php' : 'profile.php') : 'login.php' ?>"><?= $signedIn ? 'Continue' : 'Go to sign in' ?></a>
<?php // 2. Link not valid: wrong, used or expired. ?>
<?php elseif ($check['state'] !== 'valid'): ?>
  <h1>Link not valid</h1>
  <p><?= View::e($error ?: Tokens::message($check['state'], $what)) ?> You can start the change again from your <?= $type === 'password' ? 'Settings' : 'Profile' ?> page.</p>
  <a class="btn btn-primary btn-block" href="<?= $signedIn ? ($type === 'password' ? 'settings.php' : 'profile.php') : 'login.php' ?>">Continue</a>
<?php // 3. Valid link: explain the change and show the Confirm button. ?>
<?php else: ?>
  <h1><?= $type === 'password' ? 'Confirm your new password' : 'Confirm your new email address' ?></h1>
  <?php if ($type === 'email'): ?>
    <p>Use <strong><?= View::e($check['row']['new_email']) ?></strong> as the email address for your GymZone account?</p>
  <?php else: ?>
    <p>Confirm to start using the new password you chose. You will be signed out on other devices.</p>
  <?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= View::e($error) ?></div><?php endif; ?>
<?php // Posts back to this page with the CSRF token, the link token and the type. ?>
  <form method="post" class="form">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="token" value="<?= View::e($token) ?>">
    <input type="hidden" name="type" value="<?= View::e($type) ?>">
    <button class="btn btn-primary btn-block" type="submit"><?= $type === 'password' ? 'Confirm password change' : 'Confirm email address' ?></button>
  </form>
<?php endif; ?>
</section>
<?php View::footer();