<?php
/**
 * Loaded by every page, API endpoint and script.
 * Constants a caller may define first:
 *   GZ_CLI          command-line script or test: no session, no headers
 */
// Every entry point starts with require '.../app/bootstrap.php'; after that, all classes are
// available, the session is open and the database is known to work.
// It prepares the app before any page code runs, in this order:
//   1. autoloader (finds classes in app/core, app/services, app/views)
//   2. loads Db.php directly (it also defines dt())
//   3. time zone (Europe/Copenhagen)
//   4. error settings (always logged; shown only in debug mode)
//   Web requests only (skipped when GZ_CLI is defined):
//   5. security headers (CSP, no framing, nosniff, referrer policy)
//   6. last-resort error handler (friendly page or JSON for /api/)
//   Steps 7-9 are also skipped when GZ_NO_DB_CHECK is defined (installer):
//   7. database check: connect, load settings, run upgrades
//   8. start the session
//   9. maintenance: release unused bookings, auto check-out forgotten visits

// STEP 1. Autoloader: when the code uses a class name (for example Bookings), PHP calls this function.
// It looks for app/core/Bookings.php, app/services/Bookings.php or app/views/Bookings.php and loads it.
// This works because each class lives in a file with the same name.
spl_autoload_register(function (string $class): void {
    foreach (['core', 'services', 'views'] as $dir) {
        $file = __DIR__ . "/$dir/$class.php";
        if (is_file($file)) { require $file; return; }
    }
});
// STEP 2. Db.php is loaded directly, because it also defines the helper function dt().
// (The autoloader only loads classes, not plain functions.)
require_once __DIR__ . '/core/Db.php';   // also defines dt()

// STEP 3. All dates and times use the gym's time zone (Europe/Copenhagen, set in config/config.php).
date_default_timezone_set(App::config('timezone'));
// STEP 4. Errors are always written to the log; they are shown in the browser only in debug mode.
// On a live site, error details could reveal file paths or SQL to visitors.
ini_set('display_errors', App::config('debug') ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Command-line scripts and tests define GZ_CLI, so they skip the web-only steps below.
$isCli = defined('GZ_CLI') || PHP_SAPI === 'cli';

// Everything inside this if-block runs only for web requests (pages and API endpoints).
if (!$isCli) {
    // STEP 5. Security headers, sent with every response:
    // (headers must be sent before any output, which is why bootstrap.php is included first)
    // remove X-Powered-By, so visitors do not see which PHP version runs the site,
    header_remove('X-Powered-By');
    // nosniff: the browser must not guess file types,
    header('X-Content-Type-Options: nosniff');
    // DENY: GymZone may not be shown inside a frame on another site (stops clickjacking),
    header('X-Frame-Options: DENY');
    // same-origin: other sites do not receive the full address of GymZone pages,
    header('Referrer-Policy: same-origin');
    // the browser may not use the camera, microphone or location,
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    // Content-Security-Policy: scripts, styles and requests only from this site, and no inline scripts.
    // This blocks injected <script> tags (XSS); it is why all JavaScript lives in assets/js/.
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");

    // STEP 6. Last-resort error handler: any unexpected error is logged, and the visitor sees a friendly
    // message instead of technical details.
    // PHP calls it only for exceptions that no try/catch handled.
    set_exception_handler(function (Throwable $e): void {
        error_log('GymZone: ' . $e);
        if (!headers_sent()) http_response_code(500);
        // API calls (the address contains /api/) get a JSON answer that JavaScript can show.
        // Same {ok, error} shape as normal API errors, so assets/js/ handles both the same way.
        if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
            if (!headers_sent()) header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => App::config('debug') ? $e->getMessage() : 'Something went wrong. Please try again.']);
            return;
        }
        // Pages get a small, friendly HTML error page.
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Something went wrong</title>'
           . '<link rel="stylesheet" href="' . htmlspecialchars(Http::url('assets/css/app.css')) . '">'
           . '<main class="page narrow"><section class="card empty-card"><h1>Something went wrong</h1><p class="muted">Please try again in a moment. If the problem continues, contact the housing office.</p>'
           . (App::config('debug') ? '<pre class="debug">' . htmlspecialchars((string) $e) . '</pre>' : '')
           . '<p><a class="btn btn-primary" href="' . htmlspecialchars(Http::url('index.php')) . '">Back to GymZone</a></p></section></main>';
    });

    // STEP 7. Check the database (only the installer skips this, by defining GZ_NO_DB_CHECK).
    if (!defined('GZ_NO_DB_CHECK')) {
        try {
            // Connect, load the settings and bring an older database up to date if needed.
            Db::pdo();
            Settings::all();
            Upgrade::run();
        } catch (PDOException $e) {
            // The database cannot be reached: show a short message with status 503 and stop.
            error_log('GymZone database: ' . $e->getMessage());
            http_response_code(503);
            $installer = Http::url('install.php');
            exit('<!doctype html><meta charset="utf-8"><title>GymZone</title><p>GymZone is not set up yet, or the database cannot be reached. '
               . 'An administrator should check <code>config/config.php</code> or run <a href="' . htmlspecialchars($installer) . '">install.php</a>.</p>');
        }
        // STEP 8. Start (or continue) the visitor's session; see Auth::startSession().
        // The login checks in Auth read $_SESSION, so this must run before any page code.
        Auth::startSession();
        // STEP 9. Time-based housekeeping before any page reads data: release unused bookings and
        // check out residents who forgot (see app/services/Maintenance.php).
        // Runs on every web request, so no scheduled job is required (scripts/maintenance.php is optional).
        Maintenance::run();
    }
}
