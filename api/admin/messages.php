<?php
/** Administrator: contact messages and contact details (JSON, POST): action=handled (id, handled) or contact (email, phone, hours). */
require __DIR__ . '/../../app/bootstrap.php';
// Only administrators, with the CSRF check.
$admin = Auth::requireApi('admin');
if (!Http::isPost()) Http::fail('Method not allowed.', 405);
Http::api(function () use ($admin) {
    switch (Http::str('action')) {
        case 'handled':
            // Mark a message handled, or open again.
            Contact::setHandled(Http::int('id'), !empty(Http::input()['handled']), (int) $admin['id']);
            Http::ok();
        case 'contact':
            // Save the office's contact details.
            Contact::saveDetails(Http::str('email'), Http::str('phone'), Http::str('hours'));
            Http::ok(['message' => 'Contact details saved.']);
        default:
            throw new UserError('Unknown action.', 400);
    }
});
