<?php
/**
 * Scheduled housekeeping (Windows Task Scheduler or cron, every 5 minutes):
 *   php scripts/maintenance.php
 * Releases unused bookings after the check-in period, checks out residents still checked in after
 * 6 hours, and removes old rate-limit records. The app also does the first two on every page load.
 */
require __DIR__ . '/cli.php';
// Bring an older database up to date, release no-shows, do automatic check-outs and remove old rate-limit rows.
Upgrade::run();
Maintenance::run();
RateLimiter::purge();
echo '[' . Clock::now()->format('Y-m-d H:i:s') . "] GymZone maintenance done.\n";
