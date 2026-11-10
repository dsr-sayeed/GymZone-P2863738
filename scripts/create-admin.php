<?php
/**
 * Adds another housing administrator.
 *   php scripts/create-admin.php --name="Jane Doe" --email=jane@example.com
 * The password is typed in (hidden) or read from GYM_ADMIN_PASSWORD.
 */
require __DIR__ . '/cli.php';
$args = cli_args();
try {
    // Ask for the details (or take them from the options) and create the account.
    $name = cli_ask('Full name', $args['name'] ?? null);
    $email = cli_ask('Email', $args['email'] ?? null);
    $pw = getenv('GYM_ADMIN_PASSWORD') ?: cli_secret('Password (min. 10 characters)');
    $pw2 = getenv('GYM_ADMIN_PASSWORD') ?: cli_secret('Repeat password');
    Installer::createAdmin($name, $email, $pw, $pw2);
    echo "Administrator account created for $email.\n";
} catch (UserError $e) {
    cli_fail($e->getMessage());
}
