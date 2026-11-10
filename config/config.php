<?php
/**
 * GymZone configuration.
 * Every value can be overridden with the environment variable shown, which keeps
 * passwords out of this file on servers that support it.
 */
// The file returns one array; App::config() reads it. getenv() reads an environment variable,
// and ?: means "use the value on the right if the left one is empty".
return [
    'app_name' => 'GymZone',

    // Public address of the app, without a trailing slash, used in links inside emails.
    // Example for XAMPP: http://localhost/gymzone. Leave empty to detect it from the browser request.
    'app_url'  => getenv('GYM_APP_URL') ?: '',

    // All session times are local to the gym. Use a PHP time zone name.
    'timezone' => getenv('GYM_TIMEZONE') ?: 'Europe/Copenhagen',

    // Show detailed errors in the browser. Keep false outside development.
    'debug'    => getenv('GYM_DEBUG') === '1',

    'db' => [
        // Database connection. XAMPP's defaults are the user root with an empty password.
        'host' => getenv('GYM_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('GYM_DB_PORT') ?: '3306',
        'name' => getenv('GYM_DB_NAME') ?: 'gymzone',
        'user' => getenv('GYM_DB_USER') ?: 'root',
        'pass' => getenv('GYM_DB_PASS') !== false ? getenv('GYM_DB_PASS') : '',
    ],

    'mail' => [
        // 'mailpit' sends through Mailpit's HTTP API (local testing).
        // 'log' writes each email to storage/logs/mail.log instead (no email server needed).
        'transport'   => getenv('GYM_MAIL_TRANSPORT') ?: 'mailpit',
        // Mailpit must listen on localhost only. Never expose port 8025 to the internet.
        'mailpit_url' => getenv('GYM_MAILPIT_URL') ?: 'http://127.0.0.1:8025',
        'from_email'  => getenv('GYM_MAIL_FROM') ?: 'no-reply@gymzone.local',
        'from_name'   => 'GymZone',
        'timeout'     => 5,   // seconds to wait for Mailpit
    ],
];
