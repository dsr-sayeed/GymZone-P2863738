<?php
/** A resident sends a message to the housing office (JSON, POST: subject, message). */
require __DIR__ . '/../app/bootstrap.php';
// Only signed-in residents, with the CSRF check.
$user = Auth::requireApi('resident');
if (!Http::isPost()) Http::fail('Method not allowed.', 405);
Http::api(function () use ($user) {
    // Store and email the message; the answer says whether the email went out.
    $sent = Contact::send($user, Http::str('subject'), Http::str('message'));
    Http::ok(['message' => $sent ? 'Your message has been sent to the housing office.' : 'Your message has been saved. The housing office will see it in GymZone.']);
});
