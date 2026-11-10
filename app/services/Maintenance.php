<?php
/**
 * Time-based housekeeping, run at the start of every request and by scripts/maintenance.php:
 *  - bookings not checked in by the end of the check-in period are released, so the place can be used;
 *  - residents still checked in after 6 hours (setting auto_checkout_hours) are checked out
 *    automatically and asked for their real check-out time on their next visit.
 * Neither step limits a resident's future bookings.
 */
// Called by bootstrap.php on every web request, and by scripts/maintenance.php.
final class Maintenance
{
    public static function run(): void
    {
        // The current time and the check-in period (15 minutes by default).
        $now = Clock::now();
        $grace = Settings::int('checkin_grace_minutes');
        // Release no-shows: bookings still 'booked' 15 minutes after their start (or after their end) become
        // 'released', so the place is free again. Their day_key becomes NULL, so the day is free too.
        Db::run("UPDATE bookings SET status = 'released'
                  WHERE status = 'booked' AND (DATE_ADD(start_at, INTERVAL ? MINUTE) <= ? OR end_at <= ?)",
            [$grace, dt($now), dt($now)]);
        // Check out residents who are still inside after 6 hours.
        Attendance::autoCheckOut();
    }
}
