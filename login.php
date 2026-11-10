<?php
// Sign-in page. A GET shows the form; a POST checks the email and password.
require __DIR__ . '/app/bootstrap.php';
// Already signed in in this browser: explain that all tabs share one sign-in, instead of switching account.
if ($u = Auth::user()) {
    // Browsers share one sign-in between all tabs. Explain this instead of silently switching pages.
    View::header('Already signed in', '', ['guest' => true]);
    ?>
<section class="card auth-card">
  <h1>You are already signed in</h1>
  <p>This browser is signed in as <strong><?= View::e($u['full_name']) ?></strong> (<?= View::e($u['email']) ?>).</p>
  <p class="muted small">All tabs in a browser share the same sign-in, so one browser can only use one GymZone account at a time. To use a second account at the same time, open a private (incognito) window or another browser.</p>
  <div class="stack">
    <a class="btn btn-primary btn-block" href="<?= View::e(Http::url(Auth::home($u))) ?>">Continue as <?= View::e(strtok($u['full_name'], ' ')) ?></a>
    <form method="post" action="logout.php"><?= Auth::csrfField() ?><button class="btn btn-block" type="submit">Sign out to use another account</button></form>
  </div>
</section>
<?php
    View::footer();
    exit;
}
// Pre-fill the email after activation (set by activate.php), then forget it.
$email = $_SESSION['prefill_email'] ?? '';
unset($_SESSION['prefill_email']);
$error = '';
// The form was sent: check the CSRF token, then the email and password.
if (Http::isPost()) {
    Auth::verifyCsrf();
    $email = trim(Http::str('email'));
    try {
        // Auth::attempt() checks the password and the rate limits, and throws a UserError with a message if it fails.
        $user = Auth::attempt($email, Http::str('password'), Http::clientIp());
        $next = $_SESSION['after_login'] ?? null;
        // Start the signed-in session.
        Auth::login($user);
        $home = Auth::home($user);
        // Return to the page the person originally asked for, if it belongs to their area.
        if (is_string($next) && str_starts_with($next, Http::basePath()) && !str_contains($next, '//')
            && ($user['role'] === 'admin') === str_contains($next, '/admin/') && !str_contains($next, '/api/')) {
            Http::redirect(Http::absoluteUrl(substr($next, strlen(Http::basePath()))));
        }
        Http::redirect($home);
    } catch (UserError $e) {
        // Wrong password and similar: show the message above the form.
        $error = $e->getMessage();
    }
}
// Show the sign-in form. Every value is escaped with View::e().
View::header('Sign in', '', ['guest' => true]);
?>
<section class="card auth-card">
  <h1>Welcome to GymZone</h1>
  <p class="muted">Sign in to see gym availability and book your sessions.</p>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= View::e($error) ?></div><?php endif; ?>
<?php // The form posts back to login.php with the hidden CSRF token. ?>
  <form method="post" class="form" novalidate>
    <?= Auth::csrfField() ?>
    <div class="field">
      <label for="email">Email address</label>
      <input id="email" name="email" type="email" autocomplete="username" required value="<?= View::e($email) ?>" <?= $email ? '' : 'autofocus' ?>>
    </div>
    <?= View::passwordField('password', 'Password', 'current-password', '', $email !== '') ?>
    <button class="btn btn-primary btn-block" type="submit">Sign in</button>
  </form>
  <?php // Link to the forgotten-password page. ?>
  <p class="auth-foot"><a href="forgot-password.php">Forgot your password?</a></p>
</section>
<p class="auth-foot muted small">GymZone accounts are created by the housing office.<br>New residents receive an invitation by email.</p>
<?php View::footer();
