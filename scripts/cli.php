<?php
/** Shared helpers for the command-line scripts. */
// These scripts may only run from the command line; a browser gets 403.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }
// GZ_CLI tells bootstrap.php to skip sessions and headers.
define('GZ_CLI', true);
require __DIR__ . '/../app/bootstrap.php';

// cli_args(): reads options such as --name=Anna into an array.
function cli_args(): array
{
    $out = [];
    foreach (array_slice($GLOBALS['argv'], 1) as $a) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $out[$m[1]] = $m[2] ?? true;
    }
    return $out;
}

// cli_ask(): uses the option if it was given; otherwise asks in the terminal.
function cli_ask(string $label, ?string $given = null): string
{
    if ($given !== null && $given !== '') return $given;
    echo $label, ': ';
    return trim((string) fgets(STDIN));
}

/** Reads a password without showing it (where the terminal allows). */
function cli_secret(string $label): string
{
    echo $label, ': ';
    // On Linux and macOS, "stty -echo" hides the characters while a password is typed.
    $hide = DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && posix_isatty_safe();
    if ($hide) shell_exec('stty -echo');
    $value = rtrim((string) fgets(STDIN), "\r\n");
    if ($hide) { shell_exec('stty echo'); echo PHP_EOL; }
    return $value;
}

function posix_isatty_safe(): bool
{
    return function_exists('posix_isatty') ? @posix_isatty(STDIN) : false;
}

// cli_fail(): prints an error and stops with exit code 1.
function cli_fail(string $message): never
{
    fwrite(STDERR, "Error: $message" . PHP_EOL);
    exit(1);
}
