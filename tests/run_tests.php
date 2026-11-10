<?php
/**
 * GymZone automated tests.
 *
 *   php tests/run_tests.php
 *
 * Uses a separate database (gymzone_test, or GYM_TEST_DB) that is rebuilt on every run, a fixed
 * clock (Monday 2 November 2026, 10:05), PHP's built-in web server for the HTTP tests, and Mailpit
 * at http://127.0.0.1:8025 for the email tests. If Mailpit is not running, emails are read from the
 * mail log instead and the Mailpit-specific checks are reported as skipped.
 * Results are written to tests/TEST_RESULTS.md.
 */
// The test database and the fixed "now". putenv() passes them on to bootstrap.php and Clock.
$testDb = getenv('GYM_TEST_DB') ?: 'gymzone_test';
$fakeNow = '2026-11-02 10:05:00';
putenv("GYM_DB_NAME=$testDb");
putenv("GYM_FAKE_NOW=$fakeNow");
// GZ_CLI: no session and no headers.
define('GZ_CLI', true);
require __DIR__ . '/../app/bootstrap.php';

ini_set('error_log', sys_get_temp_dir() . '/gymzone-test-errors.log');   // expected warnings (e.g. Mailpit offline test) stay out of the output
// The PHP web server for the HTTP tests runs on this port.
$port = 8765;
$baseUrl = "http://127.0.0.1:$port";
App::override(['app_url' => $baseUrl]);

/* ---------- tiny test framework ---------- */
$results = [];
// test(): runs one test and records PASS, SKIP or FAIL with details.
function test(string $area, string $name, callable $fn): void
{
    global $results;
    try {
        $detail = $fn();
        $results[] = [$area, $name, 'PASS', is_string($detail) ? $detail : ''];
        echo "  PASS  $name\n";
    } catch (SkipTest $e) {
        $results[] = [$area, $name, 'SKIP', $e->getMessage()];
        echo "  SKIP  $name ({$e->getMessage()})\n";
    } catch (Throwable $e) {
        $results[] = [$area, $name, 'FAIL', $e->getMessage() . ' @ line ' . $e->getLine()];
        echo "  FAIL  $name\n        {$e->getMessage()} (line {$e->getLine()})\n";
    }
}
final class SkipTest extends Exception {}
// check() fails the test if a condition is false; same() fails it if two values differ.
function check(bool $cond, string $message): void { if (!$cond) throw new RuntimeException($message); }
function same($expected, $actual, string $what): void
{
    if ($expected !== $actual) throw new RuntimeException("$what: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
}
/** Runs $fn and returns the UserError message it throws; fails if it does not throw. */
function refused(callable $fn): string
{
    try { $fn(); } catch (UserError $e) { return $e->getMessage(); }
    throw new RuntimeException('Expected the action to be refused, but it was allowed.');
}
function section(string $title): void { echo "\n$title\n"; }

/* ---------- mail helpers (Mailpit API, or the last message as a fallback) ---------- */
$mailpitUrl = App::config('mail.mailpit_url');
// http_json(): a small HTTP client used to talk to the Mailpit API.
function http_json(string $method, string $url, ?array $body = null): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => $body ? json_encode($body) : null]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res === false ? null : json_decode($res, true);
}
// Is Mailpit running? If not, emails go to the log and are read from Mailer::$sent instead.
$mailpitUp = http_json('GET', "$mailpitUrl/api/v1/info") !== null;
if (!$mailpitUp) App::override(['mail' => ['transport' => 'log']]);

/** The newest email to $to: ['subject', 'html', 'source' => 'mailpit'|'log']. */
function latest_mail(string $to): array
{
    global $mailpitUp, $mailpitUrl;
    if ($mailpitUp) {
        $list = http_json('GET', "$mailpitUrl/api/v1/search?query=" . rawurlencode("to:$to") . '&limit=1');
        check(!empty($list['messages']), "No email for $to found in Mailpit");
        $m = http_json('GET', "$mailpitUrl/api/v1/message/" . $list['messages'][0]['ID']);
        return ['subject' => $m['Subject'], 'html' => $m['HTML'], 'source' => 'mailpit'];
    }
    foreach (array_reverse(Mailer::$sent) as $m) {
        if ($m['to'] === $to) return ['subject' => $m['subject'], 'html' => $m['html'], 'source' => 'log'];
    }
    throw new RuntimeException("No email for $to was sent");
}
// link_token(): finds the 64-character token in a link inside an email.
function link_token(string $html, string $page): string
{
    check((bool) preg_match('#' . preg_quote($page, '#') . '\?(?:type=\w+&(?:amp;)?)?token=([a-f0-9]{64})#', $html, $m), "No $page link in the email");
    return $m[1];
}

/* ---------- fresh database ---------- */
echo "GymZone tests · database $testDb · clock $fakeNow · Mailpit " . ($mailpitUp ? "at $mailpitUrl" : 'not running (using mail log)') . "\n";
// Build a fresh database from schema.sql and create the test administrator.
Installer::createDatabase();
Db::use(Db::connect());
Installer::installSchema(true);
$admin = Installer::createAdmin('Test Admin', 'admin@test.local', 'AdminPass2026!', 'AdminPass2026!');
if ($mailpitUp) http_json('DELETE', "$mailpitUrl/api/v1/messages", ['IDs' => []]) ?: null;
$zones = [];
foreach (Zones::all() as $z) $zones[$z['name']] = (int) $z['id'];

/** Creates an active resident directly (for tests that are not about activation). */
function resident(string $name, string $apt): int
{
    $id = Residents::create($name, $apt, strtolower(str_replace(' ', '.', $name)) . '@test.local', 1)['id'];
    Db::run("UPDATE users SET status = 'active', password_hash = ?, activated_at = NOW() WHERE id = ?", [password_hash('Resident2026!', PASSWORD_DEFAULT), $id]);
    return $id;
}
// $at('2026-11-02 10:21') moves the test clock; Clock::now() then returns that time.
$at = fn(string $t) => Clock::set(new DateTimeImmutable($t));
$at($fakeNow);

/* ======================================================================== */
section('1. Resident account creation');
test('Accounts', 'An administrator creates a pending resident with an apartment', function () {
    $r = Residents::create('Anna Jensen', '1a', 'Anna.Jensen@Test.local', 1);
    $u = Residents::find($r['id']);
    same('pending', $u['status'], 'status');
    same('1A', $u['apartment'], 'apartment');
    same('anna.jensen@test.local', $u['email'], 'email');
    same('anna.jensen', $u['username'], 'username');
    check(!$u['has_password'], 'pending account must not have a password');
    return "Pending account, apartment 1A, username anna.jensen, invitation sent: " . ($r['email_sent'] ? 'yes' : 'no');
});
test('Accounts', 'Duplicate email addresses and invalid input are refused', function () {
    $dup = refused(fn() => Residents::create('Anna Other', '2B', 'anna.jensen@test.local', 1));
    $bad = refused(fn() => Residents::create('Bad', '2B', 'not-an-email', 1));
    $apt = refused(fn() => Residents::create('Bad Apt', '##', 'bad.apt@test.local', 1));
    return "$dup / $bad / " . substr($apt, 0, 40) . '…';
});
test('Accounts', 'Several residents can share one apartment', function () {
    Residents::create('Mikkel Jensen', '1A', 'mikkel.jensen@test.local', 1);
    same(2, (int) Db::value("SELECT COUNT(*) FROM users u JOIN apartments a ON a.id = u.apartment_id WHERE a.number = '1A'"), 'residents in 1A');
    same(1, (int) Db::value("SELECT COUNT(*) FROM apartments WHERE number = '1A'"), 'apartment rows');
});
test('Accounts', 'There is no public registration page', function () {
    check(!is_file(App::root() . '/register.php') && !is_file(App::root() . '/signup.php'), 'a registration page exists');
});

/* ======================================================================== */
section('2. Account activation through Mailpit');
$annaId = (int) Db::value("SELECT id FROM users WHERE email = 'anna.jensen@test.local'");
// Activation: read the real invitation email from Mailpit and follow its link.
test('Activation', 'The invitation email reaches Mailpit with a single-use activation link', function () use ($mailpitUp) {
    if (!$mailpitUp) throw new SkipTest('Mailpit is not running');
    $m = latest_mail('anna.jensen@test.local');
    same('Welcome to GymZone', $m['subject'], 'subject');
    $token = link_token($m['html'], 'activate.php');
    $stored = Db::value("SELECT v.token_hash FROM verification_tokens v JOIN users u ON u.id = v.user_id WHERE v.purpose = 'activation' AND u.email = 'anna.jensen@test.local' ORDER BY v.id DESC LIMIT 1");
    same(hash('sha256', $token), $stored, 'stored value is the SHA-256 hash of the link token');
    check(!Db::value('SELECT 1 FROM verification_tokens WHERE token_hash = ?', [$token]), 'raw token must not be stored');
    return 'Subject "' . $m['subject'] . '", token stored only as a hash';
});
test('Activation', 'Resending an invitation invalidates the earlier link', function () use ($annaId) {
    $old = link_token(latest_mail('anna.jensen@test.local')['html'], 'activate.php');
    Residents::resendInvitation($annaId, 1);
    $new = link_token(latest_mail('anna.jensen@test.local')['html'], 'activate.php');
    check($old !== $new, 'a new token should be issued');
    same('used', Tokens::lookup($old, 'activation')['state'], 'old link state');
    same('valid', Tokens::lookup($new, 'activation')['state'], 'new link state');
});
// Move the clock 24 hours and 1 minute ahead: the link must be refused.
test('Activation', 'Expired links (after 24 hours) are refused', function () use ($at, $fakeNow) {
    $token = link_token(latest_mail('anna.jensen@test.local')['html'], 'activate.php');
    $at('2026-11-03 10:06:00');
    $msg = refused(fn() => Account::activate($token, 'AnnaGym2026!', 'AnnaGym2026!'));
    $at($fakeNow);
    return $msg;
});
test('Activation', 'The resident sets a password through the link; the link then stops working', function () {
    $token = link_token(latest_mail('anna.jensen@test.local')['html'], 'activate.php');
    $weak = refused(fn() => Account::activate($token, 'short', 'short'));
    $mismatch = refused(fn() => Account::activate($token, 'AnnaGym2026!', 'AnnaGym2026?'));
    Account::activate($token, 'AnnaGym2026!', 'AnnaGym2026!');
    same('active', Db::value("SELECT status FROM users WHERE email = 'anna.jensen@test.local'"), 'status');
    $again = refused(fn() => Account::activate($token, 'AnnaGym2026!', 'AnnaGym2026!'));
    return "Weak: $weak | Mismatch: $mismatch | Reuse: $again";
});

/* ======================================================================== */
section('3. Resident login and logout');
test('Login', 'Correct password signs in; wrong password, pending and deactivated accounts are refused', function () {
    $ok = Auth::attempt('anna.jensen@test.local', 'AnnaGym2026!', '10.0.0.1');
    same('anna.jensen@test.local', $ok['email'], 'signed-in user');
    $wrong = refused(fn() => Auth::attempt('anna.jensen@test.local', 'wrong-password', '10.0.0.1'));
    $pending = refused(fn() => Auth::attempt('mikkel.jensen@test.local', 'anything-at-all', '10.0.0.1'));
    $id = resident('Victor Kristensen', '2A');
    Residents::setStatus($id, 'inactive', 1);
    $inactive = refused(fn() => Auth::attempt('victor.kristensen@test.local', 'Resident2026!', '10.0.0.1'));
    return "Wrong: $wrong | Pending: " . substr($pending, 0, 45) . "… | Deactivated: $inactive";
});
// Rate limit: after 5 wrong passwords even the right one is refused, until 15 minutes have passed.
test('Login', 'Five wrong passwords block sign-in for 15 minutes (rate limiting)', function () use ($at, $fakeNow) {
    $id = resident('Lucas Hansen', '2A');
    for ($i = 0; $i < 5; $i++) { try { Auth::attempt('lucas.hansen@test.local', 'nope', '10.0.0.2'); } catch (UserError $e) {} }
    $blocked = refused(fn() => Auth::attempt('lucas.hansen@test.local', 'Resident2026!', '10.0.0.2'));
    $at('2026-11-02 10:21:00');
    $ok = Auth::attempt('lucas.hansen@test.local', 'Resident2026!', '10.0.0.2');
    $at($fakeNow);
    return "Blocked: $blocked | After 16 minutes: signed in";
});

/* ======================================================================== */
section('4. Password recovery');
// Password reset: follow the link in the email, set a new password, and check the link cannot be reused.
test('Recovery', 'Forgot password sends a reset link; the new password works and the link is single use', function () {
    Account::requestPasswordReset('anna.jensen@test.local', '10.0.0.3');
    $m = latest_mail('anna.jensen@test.local');
    same('Reset your GymZone password', $m['subject'], 'subject');
    $token = link_token($m['html'], 'reset-password.php');
    Account::resetPassword($token, 'AnnaReset2026!', 'AnnaReset2026!');
    Auth::attempt('anna.jensen@test.local', 'AnnaReset2026!', '10.0.0.3');
    refused(fn() => Auth::attempt('anna.jensen@test.local', 'AnnaGym2026!', '10.0.0.3'));
    $reuse = refused(fn() => Account::resetPassword($token, 'Another2026!', 'Another2026!'));
    return "Delivered via {$m['source']}; old password refused; reuse: $reuse";
});
test('Recovery', 'Unknown emails get the same answer and no email is sent', function () {
    $before = count(Mailer::$sent);
    Account::requestPasswordReset('nobody@test.local', '10.0.0.3');
    same($before, count(Mailer::$sent), 'emails sent');
});
test('Recovery', 'Reset links expire after 60 minutes', function () use ($at, $fakeNow) {
    Account::requestPasswordReset('lucas.hansen@test.local', '10.0.0.4');
    $token = link_token(latest_mail('lucas.hansen@test.local')['html'], 'reset-password.php');
    $at('2026-11-02 11:06:00');
    $msg = refused(fn() => Account::resetPassword($token, 'Lucas2026!new', 'Lucas2026!new'));
    $at($fakeNow);
    return $msg;
});

/* ======================================================================== */
section('5. Email change verification');
// Email change: a wrong password, a typo and a taken address are refused; the link goes to the current address.
test('Email change', 'The confirmation goes to the CURRENT address; the email changes only after it is opened', function () use ($annaId) {
    $wrongPw = refused(fn() => Account::requestEmailChange($annaId, 'anna.new@test.local', 'anna.new@test.local', 'bad-password'));
    $mismatch = refused(fn() => Account::requestEmailChange($annaId, 'anna.new@test.local', 'anna.neu@test.local', 'AnnaReset2026!'));
    $taken = refused(fn() => Account::requestEmailChange($annaId, 'lucas.hansen@test.local', 'lucas.hansen@test.local', 'AnnaReset2026!'));
    Account::requestEmailChange($annaId, 'anna.new@test.local', 'anna.new@test.local', 'AnnaReset2026!');
    same('anna.jensen@test.local', Db::value('SELECT email FROM users WHERE id = ?', [$annaId]), 'email before confirmation');
    $m = latest_mail('anna.jensen@test.local');                       // sent to the current address
    same('Confirm the change of your GymZone email', $m['subject'], 'subject');
    $token = link_token($m['html'], 'verify.php');
    Account::confirmEmailChange($token);
    same('anna.new@test.local', Db::value('SELECT email FROM users WHERE id = ?', [$annaId]), 'email after confirmation');
    $old = latest_mail('anna.jensen@test.local')['subject'];
    $new = latest_mail('anna.new@test.local')['subject'];
    $reuse = refused(fn() => Account::confirmEmailChange($token));
    return "Wrong password: $wrongPw | Typo: $mismatch | Taken: $taken | Notices: old \"$old\", new \"$new\" | Reuse: $reuse";
});

section('6. Password change verification');
// Password change: the new password works only after the link in the email is opened.
test('Password change', 'The new password starts working only after email confirmation; other sessions end', function () use ($annaId) {
    $wrong = refused(fn() => Account::requestPasswordChange($annaId, 'bad', 'AnnaNext2026!', 'AnnaNext2026!'));
    $ver = (int) Db::value('SELECT session_version FROM users WHERE id = ?', [$annaId]);
    Account::requestPasswordChange($annaId, 'AnnaReset2026!', 'AnnaNext2026!', 'AnnaNext2026!');
    Auth::attempt('anna.new@test.local', 'AnnaReset2026!', '10.0.0.5');   // old password still works
    refused(fn() => Auth::attempt('anna.new@test.local', 'AnnaNext2026!', '10.0.0.5'));
    $m = latest_mail('anna.new@test.local');
    same('Confirm your new GymZone password', $m['subject'], 'subject');
    check(!str_contains($m['html'], 'AnnaNext2026!'), 'the password must never appear in an email');
    Account::confirmPasswordChange(link_token($m['html'], 'verify.php'));
    Auth::attempt('anna.new@test.local', 'AnnaNext2026!', '10.0.0.5');
    refused(fn() => Auth::attempt('anna.new@test.local', 'AnnaReset2026!', '10.0.0.5'));
    same($ver + 1, (int) Db::value('SELECT session_version FROM users WHERE id = ?', [$annaId]), 'session version (signs out other devices)');
    return "Wrong current password: $wrong";
});
// Point the mailer at a port where nothing listens, as if Mailpit were down.
test('Mail', 'When Mailpit is unavailable, accounts are still created and nothing is half-changed', function () use ($annaId) {
    $saved = App::config('mail');
    App::override(['mail' => ['transport' => 'mailpit', 'mailpit_url' => 'http://127.0.0.1:9', 'timeout' => 2]]);
    try {
        $r = Residents::create('Offline Person', '9Z', 'offline.person@test.local', 1);
        check($r['email_sent'] === false, 'email_sent should be false');
        $msg = refused(fn() => Account::requestEmailChange($annaId, 'offline.new@test.local', 'offline.new@test.local', 'AnnaNext2026!'));
        same(0, (int) Db::value("SELECT COUNT(*) FROM verification_tokens WHERE purpose = 'email_change' AND consumed_at IS NULL AND user_id = ?", [$annaId]), 'leftover email-change tokens');
    } finally {
        App::override(['mail' => $saved]);
    }
    return 'Account created with "email not sent" flag; email change refused: ' . $msg;
});

/* ======================================================================== */
section('7–10. Booking rules (any start minute, chosen length, open 24 hours)');
$r1 = resident('Sofie Nielsen', '1B');
$r2 = resident('Emma Pedersen', '2C');
$r3 = resident('Oliver Andersen', '3B');
$crowd = [];
for ($i = 1; $i <= 12; $i++) $crowd[] = resident('Crowd Member ' . chr(64 + $i), '7' . chr(64 + $i));
Db::run('UPDATE zones SET capacity = 3 WHERE id = ?', [$zones['Weights']]);

// Any start minute: 13:52 + 60 minutes gives 13:52–14:52; 10 and 300 minutes are refused.
test('Booking', 'Sessions start at any minute and last the chosen length', function () use ($r1, $zones) {
    $b = Bookings::create($r1, $zones['Cardio'], '2026-11-03 13:52', 60);
    same('13:52–14:52', $b['time'], 'booked time');
    $b2 = Bookings::create($r1, $zones['Machines'], '2026-11-03 07:07', 35);
    same('07:07–07:42', $b2['time'], 'booked time');
    $short = refused(fn() => Bookings::create($r1, $zones['Weights'], '2026-11-03 20:00', 10));
    $long = refused(fn() => Bookings::create($r1, $zones['Weights'], '2026-11-03 20:00', 300));
    return "13:52 + 60 min → {$b['time']}; 07:07 + 35 min → {$b2['time']} | Too short: $short | Too long: $long";
});
test('Booking', 'The gym is open 24 hours by default; a session may continue past midnight', function () use ($r2, $zones) {
    $b = Bookings::create($r2, $zones['Weights'], '2026-11-03 23:30', 90);
    same('23:30–01:00', $b['time'], 'booked time');
    same('2026-11-04 01:00:00', $b['end'], 'end on the next day');
});
// Capacity: three overlapping sessions fill the 3 places from 18:45 to 19:00 (see Availability::peakOf()).
test('Capacity', 'A zone is never over capacity at any moment, also with partly overlapping sessions', function () use ($crowd, $zones, $r1) {
    // Weights holds 3. Three residents at 18:00–19:00, 18:30–19:30 and 18:45–19:15 overlap from 18:45 to 19:00.
    Bookings::create($crowd[0], $zones['Weights'], '2026-11-03 18:00', 60);
    Bookings::create($crowd[1], $zones['Weights'], '2026-11-03 18:30', 60);
    Bookings::create($crowd[2], $zones['Weights'], '2026-11-03 18:45', 30);
    $peak = Availability::peak($zones['Weights'], new DateTimeImmutable('2026-11-03 18:00'), new DateTimeImmutable('2026-11-03 20:00'));
    same(3, $peak, 'most people at the same time');
    $full = refused(fn() => Bookings::create($r1, $zones['Weights'], '2026-11-03 18:50', 30));
    $partial = refused(fn() => Bookings::create($r1, $zones['Weights'], '2026-11-03 18:20', 60));   // fine until 18:45
    $b = Bookings::create($r1, $zones['Weights'], '2026-11-03 19:15', 30);                           // after the crowd thins out
    return "Full: $full | Partly full: $partial | 19:15–19:45 allowed";
});
// Concurrency: 8 separate PHP processes try to book the same 3 places at the same moment.
test('Capacity', 'Simultaneous requests cannot overbook (8 processes, 3 places)', function () use ($crowd, $zones, $fakeNow) {
    $start = '2026-11-04 07:13';
    // Every process waits until this moment, 1.5 seconds from now, and then they all book together.
    $go = microtime(true) + 1.5;
    $procs = [];
    foreach (array_slice($crowd, 3, 8) as $u) {
        $p = proc_open([PHP_BINARY, __DIR__ . '/concurrent_book.php', (string) $u, (string) $zones['Weights'], $start, $fakeNow, (string) $go], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[] = [$p, $pipes];
    }
    $out = [];
    foreach ($procs as [$p, $pipes]) { $out[] = trim(stream_get_contents($pipes[1]) . stream_get_contents($pipes[2])); fclose($pipes[1]); fclose($pipes[2]); proc_close($p); }
    // Exactly 3 must succeed, and the database must never hold more than 3 people at the same time.
    $ok = count(array_filter($out, fn($o) => $o === 'OK'));
    $stored = Availability::peak($zones['Weights'], new DateTimeImmutable($start), new DateTimeImmutable('2026-11-04 08:13'));
    same(3, $ok, 'successful bookings');
    same(3, $stored, 'people at the same time');
    return "$ok succeeded, " . (count($out) - $ok) . ' refused, 3 stored';
});
// One per zone per day: refused by PHP first, then a direct SQL INSERT proves the database refuses it too.
test('Duplicates', 'One session per zone per resident per day (also enforced by a unique key)', function () use ($r3, $zones) {
    Bookings::create($r3, $zones['Cardio'], '2026-11-05 07:00', 60);
    $msg = refused(fn() => Bookings::create($r3, $zones['Cardio'], '2026-11-05 19:00', 60));
    $db = 'not refused';
    // Skip the PHP rules and insert directly: the unique key uq_bookings_one_zone_per_day must refuse the row.
    try { Db::run("INSERT INTO bookings (user_id, zone_id, booking_date, start_at, end_at, status, created_at) VALUES (?, ?, '2026-11-05', '2026-11-05 20:00', '2026-11-05 21:00', 'booked', NOW())", [$r3, $zones['Cardio']]); }
    catch (PDOException $e) { $db = Db::isDuplicate($e, 'uq_bookings_one_zone_per_day') ? 'unique key refused the row' : $e->getMessage(); }
    same('unique key refused the row', $db, 'database constraint');
    return "$msg | Direct SQL insert: $db";
});
test('Duplicates', 'All three zones can be booked on the same day at different times', function () use ($r3, $zones) {
    Bookings::create($r3, $zones['Weights'], '2026-11-05 08:00', 45);
    Bookings::create($r3, $zones['Machines'], '2026-11-05 08:45', 30);   // starts exactly when the previous one ends
    same(3, (int) Db::value("SELECT COUNT(*) FROM bookings WHERE user_id = ? AND booking_date = '2026-11-05' AND status = 'booked'", [$r3]), 'bookings that day');
});
// Overlap: Cardio 17:00–18:00, then Machines from 17:55 must be refused.
test('Overlap', 'Sessions cannot overlap, even by a few minutes', function () use ($r2, $zones) {
    Bookings::create($r2, $zones['Cardio'], '2026-11-06 17:00', 60);
    return refused(fn() => Bookings::create($r2, $zones['Machines'], '2026-11-06 17:55', 30));
});
// 21-day window: today and day 21 are allowed; the past, now and day 22 are refused.
test('Window', 'Bookings are possible from today up to 20 days ahead (21 days), not before or after', function () use ($r3, $zones) {
    Bookings::create($r3, $zones['Cardio'], '2026-11-02 11:00', 60);                 // today, later
    Bookings::create($r3, $zones['Cardio'], '2026-11-22 22:10', 60);                 // day 21, late evening
    $past = refused(fn() => Bookings::create($r3, $zones['Machines'], '2026-11-02 09:00', 60));
    $now = refused(fn() => Bookings::create($r3, $zones['Machines'], '2026-11-02 10:05', 60));
    $after = refused(fn() => Bookings::create($r3, $zones['Machines'], '2026-11-23 10:00', 60));
    $days = Availability::calendar($r3);
    same(21, count($days), 'calendar days');
    same('2026-11-22', end($days)['date'], 'last calendar day');
    return "Past: $past | Now: $now | Day 22: $after";
});
// Opening hours and closures: Saturday closed and Tuesday 06–22, then times outside them are tried.
test('Window', 'Closed days, times outside the opening hours and closures cannot be booked', function () use ($r3, $zones) {
    $hours = Schedule::hours();
    $days = [];
    foreach ($hours as $wd => $h) $days[$wd] = ['is_open' => $wd !== 6, 'open' => $wd === 2 ? '06:00' : '00:00', 'close' => $wd === 2 ? '22:00' : '24:00'];
    Schedule::saveHours($days, true, 1);
    $closedDay = refused(fn() => Bookings::create($r3, $zones['Cardio'], '2026-11-07 10:00', 60));
    $early = refused(fn() => Bookings::create($r3, $zones['Cardio'], '2026-11-03 05:30', 60));
    $late = refused(fn() => Bookings::create($r3, $zones['Cardio'], '2026-11-03 21:30', 60));
    foreach ($days as $wd => $d) $days[$wd] = ['is_open' => true, 'open' => '00:00', 'close' => '24:00'];
    Schedule::saveHours($days, true, 1);
    Schedule::addClosure($zones['Machines'], new DateTimeImmutable('2026-11-03 06:00'), new DateTimeImmutable('2026-11-03 10:00'), 'Machine service', true, 1);
    $closure = refused(fn() => Bookings::create($r3, $zones['Machines'], '2026-11-03 09:30', 60));
    return "Closed day: $closedDay | Before opening: $early | Past closing: $late | Closure: $closure";
});

/* ======================================================================== */
section('11. Booking cancellation');
// Cancelling frees the place: the peak drops by one, and another resident can take the place.
test('Cancellation', 'Cancelling releases the place; only upcoming bookings of your own can be cancelled', function () use ($crowd, $zones, $r2) {
    $id = (int) Db::value("SELECT id FROM bookings WHERE user_id = ? AND start_at = '2026-11-03 18:45:00'", [$crowd[2]]);
    $span = [new DateTimeImmutable('2026-11-03 18:45'), new DateTimeImmutable('2026-11-03 19:00')];
    $before = Availability::peak($zones['Weights'], ...$span);
    $notOwn = refused(fn() => Bookings::cancel($r2, $id));
    Bookings::cancel($crowd[2], $id);
    $after = Availability::peak($zones['Weights'], ...$span);
    same($before - 1, $after, 'people at the same time after cancelling');
    Bookings::create($crowd[3], $zones['Weights'], '2026-11-03 18:45', 15);   // the freed place can be booked
    $twice = refused(fn() => Bookings::cancel($crowd[2], $id));
    return "Places taken $before → $after, then rebooked by another resident | Someone else's booking: $notOwn | Twice: $twice";
});
test('Cancellation', 'A cancelled booking does not block booking the same zone again that day', function () use ($crowd, $zones) {
    Bookings::create($crowd[2], $zones['Weights'], '2026-11-03 21:00', 60);
});

/* ======================================================================== */
section('12. Check-in and checkout');
// Check-in window: refused at 10:45 for an 11:00 booking, allowed at 10:52.
test('Check-in', 'Check-in with a booking is possible from 10 minutes before to 15 minutes after the start', function () use ($r3, $at, $fakeNow) {
    $id = (int) Db::value("SELECT id FROM bookings WHERE user_id = ? AND start_at = '2026-11-02 11:00:00'", [$r3]);
    $at('2026-11-02 10:45:00');
    $early = refused(fn() => Attendance::checkIn($r3, $id));
    $at('2026-11-02 10:52:00');
    Attendance::checkIn($r3, $id);
    same('checked_in', Db::value('SELECT status FROM bookings WHERE id = ?', [$id]), 'status');
    same('2026-11-02 10:52:00', Db::value('SELECT checked_in_at FROM attendance WHERE booking_id = ?', [$id]), 'check-in time recorded');
    $twice = refused(fn() => Attendance::checkIn($r3, $id));
    $at($fakeNow);
    return "Early: $early | Twice: $twice";
});
test('Check-in', 'Checking in with a booking does not count the resident twice', function () use ($r3, $zones) {
    same(1, Availability::peak($zones['Cardio'], new DateTimeImmutable('2026-11-02 11:00'), new DateTimeImmutable('2026-11-02 12:00')), 'people in the session');
});
// Walk-ins start at the exact minute of check-in (13:52:37 becomes 13:52).
test('Walk-in', 'A walk-in at 13:52 runs 13:52–14:52, from the exact minute of check-in', function () use ($r2, $zones, $at, $fakeNow) {
    $at('2026-11-02 13:52:37');
    $b = Attendance::walkIn($r2, $zones['Machines']);
    same('13:52–14:52', $b['time'], 'walk-in session');
    Attendance::checkOut($r2);
    $at('2026-11-02 15:20:00');
    $b2 = Attendance::walkIn($r2, $zones['Weights'], 45);
    same('15:20–16:05', $b2['time'], 'walk-in with a chosen length');
    $at($fakeNow);
    return "Default length: {$b['time']} | Chosen 45 min: {$b2['time']}";
});
// Walk-in refusals: a second check-in, the same zone again, and a full zone; a shorter walk-in that fits is allowed.
test('Walk-in', 'Refused for a second active check-in, a zone used today, a full zone; suggests a shorter session', function () use ($r2, $crowd, $zones, $at, $fakeNow) {
    $at('2026-11-02 15:30:00');
    $second = refused(fn() => Attendance::walkIn($r2, $zones['Cardio']));
    Attendance::checkOut($r2);
    $same = refused(fn() => Attendance::walkIn($r2, $zones['Machines']));
    Db::run('UPDATE zones SET capacity = 1 WHERE id = ?', [$zones['Weights']]);
    Bookings::create($crowd[4], $zones['Weights'], '2026-11-02 16:00', 60);   // the only place is booked from 16:00
    $shorter = refused(fn() => Attendance::walkIn($crowd[5], $zones['Weights'], 60));
    $b = Attendance::walkIn($crowd[5], $zones['Weights'], 30);                   // 15:30–16:00 fits
    $full = refused(fn() => Attendance::walkIn($crowd[6], $zones['Weights'], 15));
    Attendance::checkOut($crowd[5]);
    Db::run('UPDATE zones SET capacity = 3 WHERE id = ?', [$zones['Weights']]);
    $at($fakeNow);
    return "Second check-in: $second | Same zone again: $same | Until the next booking: $shorter | Full: $full";
});
// Check-out records the real time: booked 11:00–12:00, in the gym 10:52–11:40.
test('Check-out', 'Check-out records the exact time; leaving early is shown with the real times, also to administrators', function () use ($r3, $zones, $at, $fakeNow) {
    $at('2026-11-02 11:40:00');
    $s = Attendance::checkOut($r3);
    same('10:52–11:40', $s['actual'], 'actual time in the gym');
    same('48 min', $s['actual_duration'], 'duration');
    $admin = Db::row("SELECT b.end_at, t.checked_out_at FROM bookings b JOIN attendance t ON t.booking_id = b.id WHERE b.user_id = ? AND b.start_at = '2026-11-02 11:00:00'", [$r3]);
    check($admin['checked_out_at'] < $admin['end_at'], 'admin data shows the early check-out');
    same(0, Availability::peak($zones['Cardio'], new DateTimeImmutable('2026-11-02 11:40'), new DateTimeImmutable('2026-11-02 12:00')), 'place freed after check-out');
    $again = refused(fn() => Attendance::checkOut($r3));
    $at($fakeNow);
    return "Booked 11:00–12:00, in the gym {$s['actual']} ({$s['actual_duration']}) | Second checkout: $again";
});
test('Check-out', 'Leaving early has no effect on future bookings (no penalties)', function () use ($r3, $zones) {
    Bookings::create($r3, $zones['Weights'], '2026-11-04 09:00', 60);
});
// Automatic check-out: still inside at 18:09, checked out at 18:11, then asked for the real time.
test('Check-out', 'After 6 hours a resident is checked out automatically and asked for the real time', function () use ($crowd, $zones, $at, $fakeNow) {
    $u = $crowd[7];
    $at('2026-11-02 12:10:00');
    Attendance::walkIn($u, $zones['Machines'], 90);
    $at('2026-11-02 18:09:00');
    Maintenance::run();
    check(Attendance::activeFor($u) !== null, 'still checked in before 6 hours');
    $at('2026-11-02 18:11:00');
    Maintenance::run();
    check(Attendance::activeFor($u) === null, 'checked out after 6 hours');
    $p = Attendance::pendingReport($u);
    same('2026-11-02 18:10:00', $p['checked_out_at'], 'automatic check-out time');
    $bad = refused(fn() => Attendance::reportCheckOut($u, (int) $p['id'], '19:30'));
    $s = Attendance::reportCheckOut($u, (int) $p['id'], '13:45');
    same('12:10–13:45', $s['actual'], 'reported time');
    check(Attendance::pendingReport($u) === null, 'no further question');
    $at($fakeNow);
    return "Auto check-out at 18:10 | Out of range: $bad | Reported: {$s['actual']}";
});
// Release: an unused 07:00 booking is 'released' at 07:16.
test('Check-in', 'Unused bookings are released after the check-in period', function () use ($r3, $zones, $at, $fakeNow) {
    $id = (int) Db::value("SELECT id FROM bookings WHERE user_id = ? AND start_at = '2026-11-05 07:00:00'", [$r3]);
    $at('2026-11-05 07:16:00');
    Maintenance::run();
    same('released', Db::value('SELECT status FROM bookings WHERE id = ?', [$id]), 'unused booking');
    Bookings::create($r3, $zones['Cardio'], '2026-11-05 20:00', 60);   // a released booking does not block the zone that day
    $at($fakeNow);
});

/* ======================================================================== */
section('Administrator operations and account emails');
// The capacity cannot be cut below the 3 people already booked at the same time.
test('Admin', 'Capacity cannot be reduced below the most people booked at the same time', function () use ($zones, $crowd) {
    foreach (array_slice($crowd, 8, 3) as $i => $u) Bookings::create($u, $zones['Weights'], '2026-11-20 18:' . (10 + $i * 5), 60);
    $msg = refused(fn() => Zones::update($zones['Weights'], 'Weights', '', 2));
    Zones::update($zones['Weights'], 'Weights', 'Free weights', 6);
    return "$msg | Raising it to 6 is accepted";
});
// Closing a zone first asks for confirmation (nothing changes), then cancels the bookings and emails residents.
test('Admin', 'Closing a zone asks for confirmation, then cancels affected bookings and emails residents', function () use ($zones) {
    $n = 0;
    try { Zones::setOpen($zones['Machines'], false, 'Repairs', false, 1); } catch (ConfirmationRequired $e) { $n = $e->affected; }
    check($n > 0, 'confirmation expected');
    check(Zones::find($zones['Machines'])['is_open'] == 1, 'change must be rolled back until confirmed');
    $cancelled = Zones::setOpen($zones['Machines'], false, 'Repairs', true, 1);
    same($n, $cancelled, 'cancelled bookings');
    same('Your Machines booking was cancelled', latest_mail(Mailer::$last['to'])['subject'], 'notification');
    Zones::setOpen($zones['Machines'], true, '', true, 1);
    return "$n booking(s) cancelled after confirmation; residents notified";
});
test('Accounts', 'Deactivating and reactivating a resident sends an email each time', function () use ($r1) {
    Residents::setStatus($r1, 'inactive', 1);
    same(0, (int) Db::value("SELECT COUNT(*) FROM bookings WHERE user_id = ? AND status = 'booked'", [$r1]), 'upcoming bookings');
    same('Your GymZone account has been deactivated', latest_mail('sofie.nielsen@test.local')['subject'], 'deactivation email');
    refused(fn() => Auth::attempt('sofie.nielsen@test.local', 'Resident2026!', '10.0.0.9'));
    same('active', Residents::setStatus($r1, 'active', 1), 'status after reactivation');
    same('Your GymZone account is active again', latest_mail('sofie.nielsen@test.local')['subject'], 'reactivation email');
});
test('Accounts', 'A resident can delete their own account (password and DELETE required); a confirmation email is sent', function () use ($r2) {
    $wrong = refused(fn() => Account::deleteOwn($r2, 'wrong', 'DELETE'));
    $word = refused(fn() => Account::deleteOwn($r2, 'Resident2026!', 'delete me'));
    Account::deleteOwn($r2, 'Resident2026!', 'DELETE');
    same(0, (int) Db::value('SELECT COUNT(*) FROM users WHERE id = ?', [$r2]), 'account rows');
    same(0, (int) Db::value('SELECT COUNT(*) FROM bookings WHERE user_id = ?', [$r2]), 'booking rows');
    same('Your GymZone account has been deleted', latest_mail('emma.pedersen@test.local')['subject'], 'deletion email');
    return "Wrong password: $wrong | Missing DELETE: $word";
});
test('Accounts', 'An administrator can delete a resident; the resident is emailed', function () use ($crowd) {
    Residents::delete($crowd[11], false);
    same(0, (int) Db::value('SELECT COUNT(*) FROM users WHERE id = ?', [$crowd[11]]), 'account rows');
    same('Your GymZone account has been deleted', latest_mail('crowd.member.l@test.local')['subject'], 'deletion email');
});
test('Contact', 'A resident message is stored for the admin and emailed to the contact address with Reply-To', function () use ($r3, $mailpitUp, $mailpitUrl) {
    $u = Db::row('SELECT u.*, a.number AS apartment FROM users u JOIN apartments a ON a.id = u.apartment_id WHERE u.id = ?', [$r3]);
    $short = refused(fn() => Contact::send($u, 'Booking or check-in', 'Hi'));
    $sent = Contact::send($u, 'Equipment or cleaning', 'The rowing machine in Cardio is making a loud noise.');
    check($sent, 'email sent');
    same(1, Contact::openCount(), 'open messages for the admin');
    $m = latest_mail('admin@test.local');
    same('GymZone message: Equipment or cleaning', $m['subject'], 'subject');
    if ($mailpitUp) {
        $list = http_json('GET', "$mailpitUrl/api/v1/search?query=" . rawurlencode('to:admin@test.local') . '&limit=1');
        $full = http_json('GET', "$mailpitUrl/api/v1/message/" . $list['messages'][0]['ID']);
        same('oliver.andersen@test.local', $full['ReplyTo'][0]['Address'] ?? null, 'Reply-To');
    }
    return "Too short: $short | Sent to the contact address with Reply-To";
});

section('13. Administrator authorization (HTTP)');
// Start PHP's built-in web server for the HTTP tests, with the test database and the fixed time.
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', App::root()], [1 => ['file', sys_get_temp_dir() . '/gymzone-test-server.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/gymzone-test-server.log', 'a']], $pipes,
    App::root(), array_merge(getenv(), ['GYM_DB_NAME' => $testDb, 'GYM_FAKE_NOW' => $fakeNow, 'GYM_APP_URL' => $baseUrl, 'GYM_MAIL_TRANSPORT' => $mailpitUp ? 'mailpit' : 'log']));
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);

// Browser: a tiny HTTP client with a cookie jar, so it keeps a session like a real browser.
final class Browser
{
    private string $jar;
    public function __construct(private string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'gz'); }
    public function request(string $method, string $path, array $data = [], bool $json = false, array $headers = []): array
    {
        $ch = curl_init($this->base . '/' . ltrim($path, '/'));
        $h = $headers;
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 15];
        if ($method === 'POST') {
            if ($json) { $h[] = 'Content-Type: application/json'; $opts[CURLOPT_POSTFIELDS] = json_encode($data); }
            else $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
        }
        $opts[CURLOPT_HTTPHEADER] = $h;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $head = substr((string) $raw, 0, $hs);
        preg_match('/^Location:\s*(\S+)/mi', $head, $loc);
        return ['code' => $code, 'body' => substr((string) $raw, $hs), 'location' => $loc[1] ?? '', 'head' => $head];
    }
    // csrf(): reads the CSRF token from a page's meta tag, as core.js does.
    public function csrf(string $body): string
    {
        preg_match('/name="csrf-token" content="([^"]+)"/', $body, $m);
        return $m[1] ?? '';
    }
    public function login(string $email, string $password): array
    {
        $page = $this->request('GET', 'login.php');
        return $this->request('POST', 'login.php', ['csrf' => $this->csrf($page['body']), 'email' => $email, 'password' => $password]);
    }
}

// HTTP sign-in: the right redirect, the security headers and the HttpOnly cookie, then sign-out.
test('Login', 'Sign-in and sign-out over HTTP (session cookie, redirects, security headers)', function () use ($baseUrl) {
    $b = new Browser($baseUrl);
    $r = $b->login('lucas.hansen@test.local', 'Resident2026!');
    same(302, $r['code'], 'sign-in response');
    check(str_ends_with($r['location'], 'dashboard.php'), 'redirect to dashboard, got ' . $r['location']);
    $dash = $b->request('GET', 'dashboard.php');
    same(200, $dash['code'], 'dashboard');
    check(str_contains($dash['head'], 'Content-Security-Policy'), 'CSP header');
    check((bool) preg_match('/Set-Cookie: gymzone_sid=[^;]+;.*HttpOnly/i', $r['head']), 'HttpOnly session cookie');
    $out = $b->request('POST', 'logout.php', ['csrf' => $b->csrf($dash['body'])]);
    same(302, $out['code'], 'sign-out');
    $after = $b->request('GET', 'dashboard.php');
    check(str_ends_with($after['location'], 'login.php'), 'dashboard after sign-out should redirect to sign-in');
    return 'Signed in → dashboard 200 → signed out → dashboard redirects to sign-in';
});
// A resident tries an admin page and an admin API call: both must answer 403 and change nothing.
test('Authorization', 'Residents cannot open admin pages or call admin APIs', function () use ($baseUrl, $zones) {
    $b = new Browser($baseUrl);
    $b->login('lucas.hansen@test.local', 'Resident2026!');
    $page = $b->request('GET', 'admin/residents.php');
    $csrf = $b->csrf($b->request('GET', 'dashboard.php')['body']);
    $api = $b->request('POST', 'api/admin/gym.php', ['action' => 'zone_update', 'id' => $zones['Cardio'], 'name' => 'Hacked', 'description' => '', 'capacity' => 99], true, ["X-CSRF-Token: $csrf"]);
    same(403, $page['code'], 'admin page');
    same(403, $api['code'], 'admin API');
    same('Cardio', Zones::find($zones['Cardio'])['name'], 'zone name unchanged');
    return 'Admin page: 403, admin API: 403, nothing changed';
});
// Not signed in: pages redirect to sign-in, and the API answers 401.
test('Authorization', 'Visitors who are not signed in are sent to sign-in; APIs answer 401', function () use ($baseUrl) {
    $b = new Browser($baseUrl);
    $p = $b->request('GET', 'admin/index.php');
    $a = $b->request('GET', 'api/availability.php?view=overview');
    check(str_ends_with($p['location'], 'login.php'), 'redirect to sign-in');
    same(401, $a['code'], 'API status');
});
// An admin request without the X-CSRF-Token header must be refused with 419.
test('Authorization', 'Requests without the CSRF token are rejected', function () use ($baseUrl, $zones) {
    $b = new Browser($baseUrl);
    $b->login('admin@test.local', 'AdminPass2026!');
    $r = $b->request('POST', 'api/admin/gym.php', ['action' => 'zone_update', 'id' => $zones['Cardio'], 'name' => 'X', 'description' => '', 'capacity' => 5], true);
    same(419, $r['code'], 'status without token');
    same('Cardio', Zones::find($zones['Cardio'])['name'], 'zone name unchanged');
});
test('Authorization', 'Administrators can use the admin pages and API', function () use ($baseUrl) {
    $b = new Browser($baseUrl);
    $b->login('admin@test.local', 'AdminPass2026!');
    foreach (['admin/index.php', 'admin/residents.php', 'admin/gym.php', 'admin/bookings.php', 'admin/messages.php'] as $p) same(200, $b->request('GET', $p)['code'], $p);
    $csrf = $b->csrf($b->request('GET', 'admin/index.php')['body']);
    $r = $b->request('POST', 'api/admin/residents.php', ['action' => 'create', 'full_name' => 'Http Created', 'apartment' => '8C', 'email' => 'http.created@test.local'], true, ["X-CSRF-Token: $csrf"]);
    same(200, $r['code'], 'create resident via API');
    $res = $b->request('GET', 'dashboard.php');
    same(403, $res['code'], 'resident dashboard for an admin');
});
// The whole booking flow over HTTP, as booking.js does it: day view, live check, create and cancel.
test('Booking', 'A resident checks a time, books and cancels through the JSON API', function () use ($baseUrl, $zones) {
    $b = new Browser($baseUrl);
    $b->login('lucas.hansen@test.local', 'Resident2026!');
    $csrf = $b->csrf($b->request('GET', 'book.php')['body']);
    $day = json_decode($b->request('GET', 'api/availability.php?view=day&date=2026-11-10&zone=' . $zones['Cardio'])['body'], true);
    same(24, count($day['hours']), 'hours in the day overview');
    $chk = json_decode($b->request('GET', 'api/availability.php?view=check&zone=' . $zones['Cardio'] . '&start=' . rawurlencode('2026-11-10 07:23') . '&minutes=40')['body'], true);
    check($chk['check']['ok'] ?? false, 'check: ' . json_encode($chk));
    $r = json_decode($b->request('POST', 'api/bookings.php', ['action' => 'create', 'zone_id' => $zones['Cardio'], 'start' => '2026-11-10 07:23', 'minutes' => 40], true, ["X-CSRF-Token: $csrf"])['body'], true);
    check($r['ok'] ?? false, 'booking created: ' . json_encode($r));
    same('07:23–08:03', $r['booking']['time'], 'booked time');
    $c = json_decode($b->request('POST', 'api/bookings.php', ['action' => 'cancel', 'id' => $r['booking']['id']], true, ["X-CSRF-Token: $csrf"])['body'], true);
    check($c['ok'] ?? false, 'booking cancelled');
    return "Booked {$r['booking']['zone']} {$r['booking']['date']} {$r['booking']['time']}, then cancelled";
});
test('Login', 'A second sign-in in the same browser explains that tabs share one account', function () use ($baseUrl) {
    $b = new Browser($baseUrl);
    $b->login('lucas.hansen@test.local', 'Resident2026!');
    $r = $b->request('GET', 'login.php');
    same(200, $r['code'], 'login page');
    check(str_contains($r['body'], 'You are already signed in') && str_contains($r['body'], 'private'), 'explanation shown');
});
test('Pages', 'Every page responds without errors', function () use ($baseUrl) {
    $b = new Browser($baseUrl);
    foreach (['login.php', 'forgot-password.php', 'reset-password.php?token=x', 'activate.php?token=x'] as $p) same(200, $b->request('GET', $p)['code'], $p);
    // No contact form for people who are not signed in (residents use Help after signing in).
    foreach (['login.php', 'forgot-password.php'] as $p) check(!str_contains($b->request('GET', $p)['body'], 'contact.php'), "$p has no link to a contact page");
    same(404, $b->request('GET', 'contact.php')['code'], 'contact.php');
    $b->login('lucas.hansen@test.local', 'Resident2026!');
    foreach (['dashboard.php', 'book.php', 'bookings.php', 'profile.php', 'settings.php', 'help.php'] as $p) {
        $r = $b->request('GET', $p);
        same(200, $r['code'], $p);
        check(str_contains($r['body'], 'name="viewport"'), "$p has a responsive viewport");
    }
    return 'Sign-in and forgot-password pages have no contact link; contact.php: 404 / Resident pages: 200 with responsive viewport';
});
// Stop the web server.
proc_terminate($server);
proc_close($server);

/* ---------- report ---------- */
$pass = count(array_filter($results, fn($r) => $r[2] === 'PASS'));
$fail = count(array_filter($results, fn($r) => $r[2] === 'FAIL'));
$skip = count(array_filter($results, fn($r) => $r[2] === 'SKIP'));
// Write the results table to tests/TEST_RESULTS.md.
$md = "# GymZone automated test results\n\nRun " . date('Y-m-d H:i') . " with `php tests/run_tests.php`.\n\n"
    . "- Test database: `$testDb` (rebuilt for every run)\n- Fixed clock: Monday 2 November 2026, 10:05\n"
    . '- Email: ' . ($mailpitUp ? "Mailpit at $mailpitUrl (messages read back through the Mailpit API)" : 'Mailpit not running; messages read from the mail log') . "\n\n"
    . "**Result: $pass passed, $fail failed, $skip skipped (" . count($results) . " tests).**\n\n| Area | Test | Result | Details |\n|---|---|---|---|\n";
foreach ($results as [$area, $name, $res, $detail]) $md .= "| $area | $name | $res | " . str_replace(['|', "\n"], ['/', ' '], $detail) . " |\n";
file_put_contents(__DIR__ . '/TEST_RESULTS.md', $md);
echo "\n$pass passed, $fail failed, $skip skipped. Results written to tests/TEST_RESULTS.md\n";
// Exit code 1 if any test failed (useful for automation).
exit($fail ? 1 : 0);
