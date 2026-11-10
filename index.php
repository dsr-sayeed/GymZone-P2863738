<?php
// Entry point. Signed-in users go to their home page (resident dashboard or admin overview); others to sign-in.
require __DIR__ . '/app/bootstrap.php';
$user = Auth::user();
Http::redirect($user ? Auth::home($user) : 'login.php');
