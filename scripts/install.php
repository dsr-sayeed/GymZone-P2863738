<?php
/**
 * Installs GymZone from the command line: creates the database and tables, the default zones,
 * opening hours and sessions, and the first administrator account.
 *
 *   php scripts/install.php
 *   php scripts/install.php --name="Housing Office" --email=office@example.com [--sample-data]
 *
 * The administrator password is always typed in (or read from the GYM_ADMIN_PASSWORD environment
 * variable); it is never stored in the source code. --sample-data also asks for a password that all
 * sample residents will use.
 */
require __DIR__ . '/cli.php';
// Read --name, --email and --sample-data.
$args = cli_args();
try {
    // Create the database and tables (refused if an administrator exists, unless --force is given).
    Installer::createDatabase();
    Db::use(Db::connect());
    if (Installer::isInstalled() && !isset($args['force'])) cli_fail('GymZone is already installed. Use scripts/create-admin.php to add another administrator.');
    Installer::installSchema();
    echo "Database and tables are ready.\n";
    // Ask for the administrator's details; passwords can come from environment variables for automation.
    $name = cli_ask('Administrator full name', $args['name'] ?? null);
    $email = cli_ask('Administrator email', $args['email'] ?? null);
    $pw = getenv('GYM_ADMIN_PASSWORD') ?: cli_secret('Administrator password (min. 10 characters)');
    $pw2 = getenv('GYM_ADMIN_PASSWORD') ?: cli_secret('Repeat password');
    $adminId = Installer::createAdmin($name, $email, $pw, $pw2);
    echo "Administrator account created for $email.\n";
    if (isset($args['sample-data'])) {
        $rp = getenv('GYM_SAMPLE_PASSWORD') ?: cli_secret('Password for all sample residents');
        $r = Installer::seedSampleData($rp, $adminId);
        echo "Sample data added: {$r['residents']} residents, {$r['bookings']} sessions.\n";
    }
    // Lock the web installer too.
    @file_put_contents(App::root() . '/storage/install.lock', 'Installed ' . date('c') . PHP_EOL);
    echo "Done. Open GymZone in your browser and sign in.\n";
} catch (UserError $e) {
    cli_fail($e->getMessage());
} catch (PDOException $e) {
    cli_fail('Database problem: ' . $e->getMessage() . "\nCheck the db settings in config/config.php.");
}
