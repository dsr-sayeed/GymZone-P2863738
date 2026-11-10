<?php
/** Administrator actions on resident accounts (JSON). */
require __DIR__ . '/../../app/bootstrap.php';
// Only administrators, with the CSRF check.
$admin = Auth::requireApi('admin');
if (!Http::isPost()) Http::fail('Method not allowed.', 405);

Http::api(function () use ($admin) {
    // Most actions work on one resident id.
    $id = Http::int('id');
    switch (Http::str('action')) {
        case 'create':
            // Add a resident and send the invitation; warn if the email could not be sent.
            $r = Residents::create(Http::str('full_name'), Http::str('apartment'), Http::str('email'), (int) $admin['id']);
            if ($r['email_sent']) {
                View::flash('success', 'Resident added. An activation email has been sent to ' . Validator::email(Http::str('email')) . '.');
            } else {
                View::flash('warning', 'Resident added, but the activation email could not be sent because the email service is not reachable. Start Mailpit, then use "Resend invitation".');
            }
            Http::ok(['id' => $r['id'], 'email_sent' => $r['email_sent']]);
        case 'update':
            // The email is only changed when it was in the form (pending accounts).
            Residents::update($id, Http::str('full_name'), Http::str('apartment'), array_key_exists('email', Http::input()) ? Http::str('email') : null);
            View::flash('success', 'Resident details saved.');
            Http::ok();
        case 'resend':
            // Send a new invitation link.
            $r = Residents::resendInvitation($id, (int) $admin['id']);
            if (!$r['email_sent']) throw new UserError('The email service is not reachable, so the invitation was not sent. Start Mailpit and try again.', 503);
            Http::ok(['message' => 'A new activation link has been sent. Earlier links no longer work.']);
        case 'deactivate':
            // Deactivate the account.
            Residents::setStatus($id, 'inactive', (int) $admin['id']);
            View::flash('success', 'The account has been deactivated. Upcoming bookings were cancelled.');
            Http::ok();
        case 'activate':
            // Reactivate the account.
            $new = Residents::setStatus($id, 'active', (int) $admin['id']);
            View::flash('success', $new === 'active' ? 'The account is active again.' : 'The account is waiting for activation again. Send a new invitation so the resident can set a password.');
            Http::ok(['status' => $new]);
        case 'delete':
            // Delete the account, then go back to the list.
            Residents::delete($id, false);
            View::flash('success', 'The account was deleted and the resident has been notified by email.');
            Http::ok(['redirect' => Http::url('admin/residents.php')]);
        default:
            throw new UserError('Unknown action.', 400);
    }
});
