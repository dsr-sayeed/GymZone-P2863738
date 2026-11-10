<?php
/** Administrator booking management (JSON, POST): action=cancel (id, reason) or checkout (id). */
require __DIR__ . '/../../app/bootstrap.php';
// Only administrators (residents get 403), with the CSRF check.
$admin = Auth::requireApi('admin');
if (!Http::isPost()) Http::fail('Method not allowed.', 405);
Http::api(function () use ($admin) {
    switch (Http::str('action')) {
        case 'cancel':
            // Cancel with a reason; the resident is emailed.
            Bookings::adminCancel(Http::int('id'), (int) $admin['id'], Http::str('reason'));
            View::flash('success', 'The booking was cancelled and the resident has been notified by email.');
            Http::ok();
        case 'checkout':
            // Check out a resident who forgot.
            Attendance::adminCheckOut(Http::int('id'));
            View::flash('success', 'The resident has been checked out.');
            Http::ok();
        default:
            throw new UserError('Unknown action.', 400);
    }
});
