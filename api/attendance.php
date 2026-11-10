<?php
/**
 * Check-in and check-out (JSON, POST):
 *   checkin (booking_id) · walkin (zone_id, minutes) · checkout
 *   report_checkout (attendance_id, time HH:MM) · confirm_auto (attendance_id)
 */
require __DIR__ . '/../app/bootstrap.php';
// Only signed-in residents, with the CSRF check.
$user = Auth::requireApi('resident');
// Only POST is allowed.
if (!Http::isPost()) Http::fail('Method not allowed.', 405);
Http::api(function () use ($user) {
    $uid = (int) $user['id'];
    // Each action calls one method in app/services/Attendance.php.
    switch (Http::str('action')) {
        case 'checkin':
            $b = Attendance::checkIn($uid, Http::int('booking_id'));
            Http::ok(['message' => "You are checked in to {$b['zone']}. Enjoy your workout!"]);
        case 'walkin':
            // An empty minutes value means: use the suggested length.
            $minutes = Http::str('minutes') === '' ? null : Http::str('minutes');
            $b = Attendance::walkIn($uid, Http::int('zone_id'), $minutes);
            Http::ok(['message' => "You are checked in to {$b['zone']} ({$b['time']}). Enjoy your workout!"]);
        case 'checkout':
            // The result is kept in the session for the thank-you page (checked-out.php).
            $_SESSION['checked_out'] = Attendance::checkOut($uid);
            Http::ok(['redirect' => Http::url('checked-out.php')]);
        case 'report_checkout':
            $s = Attendance::reportCheckOut($uid, Http::int('attendance_id'), Http::str('time'));
            Http::ok(['message' => "Thank you. Your session is recorded as {$s['actual']} ({$s['actual_duration']})."]);
        case 'confirm_auto':
            Attendance::confirmAutoTime($uid, Http::int('attendance_id'));
            Http::ok(['message' => 'Thank you. The check-out time is confirmed.']);
        default:
            throw new UserError('Unknown action.', 400);
    }
});
