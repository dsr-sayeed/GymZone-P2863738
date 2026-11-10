<?php
/** Resident bookings (JSON, POST): action=create (zone_id, start "Y-m-d H:i", minutes) or action=cancel (id). */
require __DIR__ . '/../app/bootstrap.php';
// Only signed-in residents; the CSRF token is checked on POST.
$user = Auth::requireApi('resident');
// Only POST is allowed.
if (!Http::isPost()) Http::fail('Method not allowed.', 405);
Http::api(function () use ($user) {
    switch (Http::str('action')) {
        case 'create':
            // Create a booking: every rule is checked in Bookings::create().
            Http::ok(['booking' => Bookings::create((int) $user['id'], Http::int('zone_id'), Http::str('start'), Http::str('minutes'))]);
        case 'cancel':
            // Cancel one of the resident's own bookings.
            Bookings::cancel((int) $user['id'], Http::int('id'));
            Http::ok(['message' => 'Your booking has been cancelled and the place released.']);
        default:
            throw new UserError('Unknown action.', 400);
    }
});
