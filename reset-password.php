<?php
// Choose a new password from the link in a reset email (the link carries the token).
require __DIR__ . '/app/bootstrap.php';
// The token comes from the link (GET) or from the hidden form field (POST).
$token = (string) ($_GET['token'] ?? Http::str('token'));
$error = '';
// The form was sent: check CSRF, then Account::resetPassword() checks the token and saves the new password.
if (Http::isPost()) {
    Auth::verifyCsrf();
    try {
        Account::resetPassword($token, Http::str('password'), Http::str('password_confirm'));
        // Sign out any session in this browser and send the user to sign-in with a success message.
        Auth::logout();
        Auth::startSession();
        View::flash('success', 'Your password has been changed. Please sign in with your new password.');
        Http::redirect('login.php');
    } catch (UserError $e) {
        $error = $e->getMessage();
    }
}
// Check the token again to decide which version of the page to show.
$check = Tokens::lookup($token, '', 'password_resets');
View::header('Choose a new password', '', ['guest' => true]);
?>
<section class="card auth-card">
<?php // A used, expired or wrong link: explain and offer a new one. A valid link: show the form. ?>
<?php if ($check['state'] !== 'valid'): ?>
  <h1>Link not valid</h1>
  <p><?= View::e(Tokens::message($check['state'], 'password reset')) ?></p>
  <a class="btn btn-primary btn-block" href="forgot-password.php">Request a new link</a>
<?php else: ?>
  <h1>Choose a new password</h1>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= View::e($error) ?></div><?php endif; ?>
  <form method="post" class="form">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="token" value="<?= View::e($token) ?>">
    <?= View::passwordField('password', 'New password', 'new-password', View::PASSWORD_HINT, true) ?>
    <?= View::passwordField('password_confirm', 'Repeat new password', 'new-password') ?>
    <button class="btn btn-primary btn-block" type="submit">Save new password</button>
  </form>
<?php endif; ?>
</section>
<?php View::footer();
