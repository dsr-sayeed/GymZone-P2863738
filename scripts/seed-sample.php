<?php
/**
 * Adds realistic sample data (apartments, residents, past and upcoming sessions, a closure).
 *   php scripts/seed-sample.php
 * Asks for the password all sample residents will use (or reads GYM_SAMPLE_PASSWORD).
 * Sample residents use @example.com addresses, e.g. anna.jensen@example.com.
 */
require __DIR__ . '/cli.php';
try {
    // Sample data needs an administrator (used as the creator of the sample closure).
    $adminId = (int) Db::value("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
    if (!$adminId) cli_fail('Install GymZone and create an administrator first.');
    $pw = getenv('GYM_SAMPLE_PASSWORD') ?: cli_secret('Password for the sample residents (min. 10 characters)');
    $r = Installer::seedSampleData($pw, $adminId);
    echo "Sample data added: {$r['residents']} residents, {$r['bookings']} sessions.\n";
} catch (UserError $e) {
    cli_fail($e->getMessage());
}
