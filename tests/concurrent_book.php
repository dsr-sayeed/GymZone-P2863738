<?php
/** Worker for the concurrency test: waits until a shared moment, then tries to book. Prints OK or the refusal. */
// Called by run_tests.php with: user id, zone id, start time, the fake "now" and the moment to start.
[$_, $userId, $zoneId, $start, $now, $at] = $argv;
// Use the same fixed time as the main test.
putenv('GYM_FAKE_NOW=' . $now);
define('GZ_CLI', true);
require __DIR__ . '/../app/bootstrap.php';
// Wait until the shared moment, so all 8 processes try to book at the same time.
while (microtime(true) < (float) $at) usleep(500);
// Print OK or the refusal; run_tests.php counts the OKs and expects exactly 3.
try {
    Bookings::create((int) $userId, (int) $zoneId, $start, 60);
    echo 'OK';
} catch (UserError $e) {
    echo 'REFUSED: ', $e->getMessage();
}
