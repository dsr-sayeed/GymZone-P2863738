<?php
// Sign-out works only as a POST with the CSRF token, so another site cannot sign people out with a link.
require __DIR__ . '/app/bootstrap.php';
if (!Http::isPost()) Http::redirect('index.php');
Auth::verifyCsrf();
// End the session, then start a new empty one, so the "signed out" message can be shown on the sign-in page.
Auth::logout();
Auth::startSession();
View::flash('success', 'You have signed out.');
Http::redirect('login.php');
