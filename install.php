<?php
/**
 * One-time web installer: creates the database, tables, default gym set-up and the first
 * administrator. It writes storage/install.lock when finished and then refuses to run again.
 * Delete this file after installation. (Command-line alternative: php scripts/install.php)
 */
// The installer runs before the database exists, so bootstrap.php must skip its database check.
define('GZ_NO_DB_CHECK', true);
require __DIR__ . '/app/bootstrap.php';
Auth::startSession();
// After a successful installation this file is written; from then on the installer refuses to run.
$lock = App::root() . '/storage/install.lock';

// install_page(): shows a short page and stops.
function install_page(string $title, string $html): never
{
    View::header($title, '', ['guest' => true]);
    echo '<section class="card auth-card"><h1>' . View::e($title) . '</h1>' . $html . '</section>';
    View::footer();
    exit;
}

// Already installed: refuse with status 403.
if (is_file($lock)) {
    http_response_code(403);
    install_page('Already installed', '<p>GymZone has already been set up. For security, delete <code>install.php</code> from the server.</p><a class="btn btn-primary btn-block" href="login.php">Go to sign in</a>');
}
// Create the database if needed, and connect to it.
try {
    Installer::createDatabase();
    Db::use(Db::connect());
} catch (PDOException $e) {
    error_log('GymZone install: ' . $e->getMessage());
    install_page('Database not reachable', '<div class="alert alert-error">GymZone could not connect to MySQL: ' . View::e($e->getMessage()) . '</div>'
        . '<p>Start MySQL in the XAMPP Control Panel and check the <code>db</code> settings in <code>config/config.php</code>, then reload this page.</p>');
}
// An administrator already exists: lock the installer.
if (Installer::isInstalled()) {
    @file_put_contents($lock, 'Installed (existing administrator found) ' . date('c') . PHP_EOL);
    http_response_code(403);
    install_page('Already installed', '<p>This database already has an administrator account, so the installer has been locked.</p><a class="btn btn-primary btn-block" href="login.php">Go to sign in</a>');
}

// The form values (sample data is ticked by default).
$in = ['name' => '', 'email' => '', 'sample' => true];
$error = '';
// The form was sent: create the tables, the first administrator and, if ticked, sample data.
if (Http::isPost()) {
    Auth::verifyCsrf();
    $in = ['name' => Http::str('name'), 'email' => Http::str('email'), 'sample' => (bool) Http::str('sample')];
    try {
        Installer::installSchema();
        $adminId = Db::transaction(fn() => Installer::createAdmin($in['name'], $in['email'], Http::str('password'), Http::str('password_confirm')));
        $note = '';
        if ($in['sample']) {
            try {
                $r = Installer::seedSampleData(Http::str('sample_password'), $adminId);
                $note = "<p>Sample data added: {$r['residents']} residents (for example <code>anna.jensen@example.com</code>) with the sample password you chose, and {$r['bookings']} sessions.</p>";
            } catch (UserError $e) {
                $note = '<div class="alert alert-warning">Sample data was not added: ' . View::e($e->getMessage()) . ' You can add it later with <code>php scripts/seed-sample.php</code>.</div>';
            }
        }
        // Write the lock file so the installer cannot run again.
        @file_put_contents($lock, 'Installed ' . date('c') . PHP_EOL);
        install_page('GymZone is ready', '<div class="alert alert-success">Your administrator account has been created.</div>' . $note
            . '<p class="muted">Next: delete <code>install.php</code>, then sign in to add residents and check the gym opening hours.</p>'
            . '<a class="btn btn-primary btn-block" href="login.php">Go to sign in</a>');
    } catch (UserError $e) {
        $error = $e->getMessage();
    }
}

// Show the installation form.
View::header('Set up GymZone', '', ['guest' => true]);
?>
<section class="card auth-card">
  <h1>Set up GymZone</h1>
  <p class="muted">Connected to the database <code><?= View::e(App::config('db.name')) ?></code>. Create the first housing administrator account. No default password exists; you choose it here.</p>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= View::e($error) ?></div><?php endif; ?>
  <form method="post" class="form">
    <?= Auth::csrfField() ?>
    <div class="field"><label for="name">Administrator full name</label><input id="name" name="name" required maxlength="100" value="<?= View::e($in['name']) ?>" autocomplete="name"></div>
    <div class="field"><label for="email">Administrator email</label><input id="email" name="email" type="email" required value="<?= View::e($in['email']) ?>" autocomplete="username"></div>
    <?= View::passwordField('password', 'Password', 'new-password', View::PASSWORD_HINT) ?>
    <?= View::passwordField('password_confirm', 'Repeat password', 'new-password') ?>
    <label class="check"><input type="checkbox" name="sample" value="1" <?= $in['sample'] ? 'checked' : '' ?>> <span>Add sample data for demonstrations: apartments, 12 residents, past and upcoming sessions and a planned closure.</span></label>
    <div class="field">
      <label for="sample_password">Password for the sample residents</label>
      <div class="password-wrap"><input id="sample_password" name="sample_password" type="password" autocomplete="new-password"><button type="button" class="password-toggle">Show</button></div>
      <span class="hint">Only needed with sample data. All sample residents use this password.</span>
    </div>
    <button class="btn btn-primary btn-block" type="submit">Install GymZone</button>
  </form>
</section>
<?php View::footer();
