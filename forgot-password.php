<?php
// Forgotten password: asks for an email and sends a reset link (see Account::requestPasswordReset).
require __DIR__ . '/app/bootstrap.php';
$sent = false;
$error = '';
$email = '';
// The form was sent: check the CSRF token and request the link.
if (Http::isPost()) {
    Auth::verifyCsrf();
    $email = trim(Http::str('email'));
    try {
        Account::requestPasswordReset($email, Http::clientIp());
        // Show the same "check your email" answer whether or not the email has an account.
        $sent = true;
    } catch (UserError $e) {
        $error = $e->getMessage();
    }
}
View::header('Forgot password', '', ['guest' => true]);
?>
<section class="card auth-card">
  <?php // After sending: the neutral answer. Before sending: the form. ?>
  <?php if ($sent): ?>
    <h1>Check your email</h1>
    <p>If <strong><?= View::e($email) ?></strong> belongs to an active GymZone account, we have sent a link to reset the password. The link works for <?= Tokens::RESET_MINUTES ?> minutes.</p>
    <p class="muted small">No email after a few minutes? Check your spam folder, try again, or contact the housing office.</p>
    <a class="btn btn-primary btn-block" href="login.php">Back to sign in</a>
  <?php else: ?>
    <h1>Forgot your password?</h1>
    <p class="muted">Enter the email address you use for GymZone and we will send you a link to choose a new password.</p>
    <?php if ($error): ?><div class="alert alert-error" role="alert"><?= View::e($error) ?></div><?php endif; ?>
    <form method="post" class="form">
      <?= Auth::csrfField() ?>
      <div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="email" required autofocus value="<?= View::e($email) ?>"></div>
      <button class="btn btn-primary btn-block" type="submit">Send reset link</button>
    </form>
    <p class="auth-foot"><a href="login.php">Back to sign in</a></p>
  <?php endif; ?>
</section>
<?php View::footer();
