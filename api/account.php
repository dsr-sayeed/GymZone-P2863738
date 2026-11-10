<?php
/** The resident's own account (JSON, POST): action=username, email_change, password_change or delete. */
require __DIR__ . '/../app/bootstrap.php';
// Only signed-in residents; requireApi() also checks the CSRF token on POST.
$user = Auth::requireApi('resident');
// Only POST is allowed (405 otherwise).
if (!Http::isPost()) Http::fail('Method not allowed.', 405);
// Http::api() turns any UserError thrown below into a JSON error message.
Http::api(function () use ($user) {
    $uid = (int) $user['id'];
    // The action decides what to do. Each case answers with Http::ok(), which also ends the script.
    switch (Http::str('action')) {
        case 'username':
            $name = Account::updateUsername($uid, Http::str('username'));
            Http::ok(['username' => $name, 'message' => 'Your username has been saved.']);
        case 'email_change':
            $current = Account::requestEmailChange($uid, Http::str('email'), Http::str('email_repeat'), Http::str('current_password'));
            Http::ok(['new_email' => Validator::email(Http::str('email')),
                      'message' => "We have sent a confirmation link to your current address, $current. Your email changes when you open it."]);
        case 'password_change':
            Account::requestPasswordChange($uid, Http::str('current_password'), Http::str('new_password'), Http::str('new_password_confirm'));
            Http::ok(['message' => "We have sent a confirmation link to {$user['email']}. Your new password starts working when you open it."]);
        case 'delete':
            Account::deleteOwn($uid, Http::str('delete_password'), Http::str('confirm_word'));
            // After deleting: sign out, and send the browser to the sign-in page.
            Auth::logout();
            Auth::startSession();
            View::flash('success', 'Your account has been deleted. We have sent you a confirmation email.');
            Http::ok(['redirect' => Http::url('login.php')]);
        default:
            throw new UserError('Unknown action.', 400);
    }
});
