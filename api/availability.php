<?php
/**
 * Availability for residents (JSON, GET).
 *   ?view=overview                              who is in each zone now, plus the resident's own session and next booking
 *   ?view=calendar                              the 21-day booking calendar and the zones
 *   ?view=day&date=Y-m-d&zone=N                 hour-by-hour free places for one day and zone
 *   ?view=check&zone=N&start=Y-m-d H:i&minutes=M  whether this exact session can be booked
 */
require __DIR__ . '/../app/bootstrap.php';
// Only signed-in residents. These are GET requests that change nothing, so no CSRF check is needed.
$user = Auth::requireApi('resident');
$uid = (int) $user['id'];
Http::api(function () use ($uid) {
    // ?view=... chooses the data; overview is the default.
    switch ($_GET['view'] ?? 'overview') {
        case 'overview':
            $active = Attendance::activeFor($uid);
            // Live zones, plus the resident's own session and next booking (dashboard.js asks every 15 seconds).
            Http::ok(Availability::overview() + [
                'active' => $active ? ['zone' => $active['zone_name'], 'since' => View::date($active['checked_in_at'], 'H:i'), 'planned_end' => View::date($active['end_at'], 'H:i')] : null,
                'next'   => Bookings::nextFor($uid),
            ]);
        case 'calendar':
            // The 21 days, the zones and the session lengths (booking.js asks when the page opens).
            Http::ok(['days' => Availability::calendar($uid),
                      'zones' => array_map(fn($z) => ['id' => (int) $z['id'], 'name' => $z['name'], 'description' => $z['description'], 'capacity' => (int) $z['capacity'], 'open' => (bool) $z['is_open'], 'notice' => $z['notice'] ?? null], Zones::all()),
                      'default_minutes' => Settings::int('session_minutes'), 'max_minutes' => Settings::int('max_session_minutes'),
                      'now' => Clock::now()->format('Y-m-d H:i')]);
        case 'day':
            // Hour-by-hour free places for one day and zone.
            $date = Validator::date((string) ($_GET['date'] ?? ''));
            Http::ok(Availability::dayOverview($date, (int) ($_GET['zone'] ?? 0), $uid));
        case 'check':
            // Check one exact session without booking it (booking.js asks while the resident types).
            Http::ok(['check' => Bookings::check($uid, (int) ($_GET['zone'] ?? 0), (string) ($_GET['start'] ?? ''), $_GET['minutes'] ?? 0)]);
        default:
            throw new UserError('Unknown view.', 400);
    }
});
