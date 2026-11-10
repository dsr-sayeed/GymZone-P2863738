<?php
// The thank-you screen after check-out, with the real time in the gym.
require __DIR__ . '/app/bootstrap.php';
Auth::requirePage('resident');
// api/attendance.php puts the session in $_SESSION['checked_out']; show it once, then remove it.
$s = $_SESSION['checked_out'] ?? null;
if (!is_array($s)) Http::redirect('dashboard.php');
unset($_SESSION['checked_out']);
View::header('Checked out', 'dashboard');
?>
<section class="farewell">
  <div>
    <h1>You have checked out.</h1>
    <p>Thank you for using GymZone!</p>
    <p class="farewell-time"><?= View::e($s['zone']) ?> · <strong><?= View::e($s['actual']) ?></strong> (<?= View::e($s['actual_duration']) ?>)</p>
  </div>
</section>
<?php View::footer();
