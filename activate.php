<?php
// Account activation from the invitation email: the resident chooses their first password.
require __DIR__ . '/app/bootstrap.php';
// The token comes from the link (GET) or from the hidden form field (POST).
$token = (string) ($_GET['token'] ?? Http::str('token'));
$error = '';
// The form was sent: check CSRF, then activate (this checks the token and the password and saves it).
if (Http::isPost()) {
    Auth::verifyCsrf();
    try {
        $u = Account::activate($token, Http::str('password'), Http::str('password_confirm'));
        // Start a fresh session and send the resident to sign-in with the email filled in.
        Auth::logout();
        Auth::startSession();
        View::flash('success', 'Your account is active. Sign in with your email address and new password.');
        $_SESSION['prefill_email'] = $u['email'];
        Http::redirect('login.php');
    } catch (UserError $e) {
        $error = $e->getMessage();
    }
}
// Look up the token and the resident to decide what to show.
$check = Tokens::lookup($token, 'activation');
$user = $check['row'] ? Db::row('SELECT u.full_name, u.email, u.status, a.number AS apartment FROM users u LEFT JOIN apartments a ON a.id = u.apartment_id WHERE u.id = ?', [$check['row']['user_id']]) : null;
View::header('Activate your account', '', ['guest' => true]);
?>
<section class="card auth-card">
<?php // An error if the link is not valid or the account is already active; otherwise the password form. ?>
<?php if ($check['state'] !== 'valid' || !$user || $user['status'] !== 'pending'): ?>
  <h1><?= $user && $user['status'] === 'active' ? 'Account already active' : 'Link not valid' ?></h1>
  <p><?= $user && $user['status'] === 'active' ? 'This account has already been activated. You can sign in.' : View::e(Tokens::message($check['state'], 'activation')) . ' Activation links work once and expire after ' . Tokens::ACTIVATION_HOURS . ' hours. Please ask the housing office to send you a new invitation.' ?></p>
  <a class="btn btn-primary btn-block" href="login.php">Go to sign in</a>
<?php else: ?>
  <h1>Welcome, <?= View::e(strtok($user['full_name'], ' ')) ?></h1>
  <p class="muted">Choose a password to activate your GymZone account.</p>
  <dl class="info-list card-flat">
    <div class="info-row"><div><dt>Email</dt><dd><?= View::e($user['email']) ?></dd></div></div>
    <div class="info-row"><div><dt>Apartment</dt><dd><?= View::e($user['apartment']) ?></dd></div></div>
  </dl>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= View::e($error) ?></div><?php endif; ?>
<?php // The token travels in a hidden field. The hidden username field helps password managers save the login. ?>
  <form method="post" class="form">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="token" value="<?= View::e($token) ?>">
    <input type="email" name="username" value="<?= View::e($user['email']) ?>" autocomplete="username" hidden>
    <?= View::passwordField('password', 'Password', 'new-password', View::PASSWORD_HINT, true) ?>
    <?= View::passwordField('password_confirm', 'Repeat password', 'new-password') ?>
    <button class="btn btn-primary btn-block" type="submit">Activate account</button>
  </form>
<?php endif; ?>
</section>
<?php View::footer();
