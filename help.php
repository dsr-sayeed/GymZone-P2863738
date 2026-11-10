<?php
// Help: contact the housing office, the resident's recent messages, and common questions.
require __DIR__ . '/app/bootstrap.php';
$user = Auth::requirePage('resident');
$c = Contact::details();
$mine = Contact::forUser((int) $user['id']);
// All settings, so the answers below show the real numbers (for example 15 minutes).
$s = Settings::all();
View::header('Help', 'help', ['mainClass' => 'narrow-wide']);
?>
<div class="page-head"><div><h1>Help and contact</h1><p class="muted">Answers to common questions, and how to reach the housing office.</p></div></div>

<section class="card contact-card" aria-labelledby="contact-title">
  <h2 id="contact-title">Contact the housing office</h2>
  <ul class="contact-list">
    <?php if ($c['email']): ?><li><?= View::icon('mail') ?><a href="mailto:<?= View::e($c['email']) ?>"><?= View::e($c['email']) ?></a></li><?php endif; ?>
    <?php if ($c['phone']): ?><li><?= View::icon('user') ?><a href="tel:<?= View::e(preg_replace('/[^0-9+]/', '', $c['phone'])) ?>"><?= View::e($c['phone']) ?></a></li><?php endif; ?>
    <?php if ($c['hours']): ?><li><?= View::icon('clock') ?><span><?= View::e($c['hours']) ?></span></li><?php endif; ?>
  </ul>
  <?php // The message form is sent by help.js to api/contact.php. ?>
  <form class="form" id="contact-form" data-ajax novalidate>
    <div class="field"><label for="subject">What is it about?</label>
      <select id="subject" name="subject"><?php foreach (Contact::SUBJECTS as $sub): ?><option><?= View::e($sub) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="message">Your message</label><textarea id="message" name="message" rows="5" maxlength="2000" required placeholder="Write your question or report here"></textarea>
      <span class="hint">The housing office sees your name, apartment and email, and usually replies by email.</span></div>
    <div><button class="btn btn-primary" type="submit"><?= View::icon('mail') ?>Send message</button></div>
  </form>
</section>

<?php if ($mine): ?>
<section class="section" aria-labelledby="mine-title">
  <h2 id="mine-title">Your recent messages</h2>
  <ul class="plain-list card">
    <?php foreach ($mine as $m): ?>
      <li><strong><?= View::e($m['subject']) ?></strong> <span class="pill pill-<?= $m['handled_at'] ? 'completed' : 'pending' ?>"><?= $m['handled_at'] ? 'Handled' : 'Sent' ?></span>
        <span class="sub"><?= View::e(View::date($m['created_at'], 'D j M, H:i')) ?> · <?= View::e(mb_strimwidth($m['message'], 0, 90, '…')) ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php // Questions and answers; each one opens when it is clicked. ?>
<section class="section faq" aria-labelledby="faq-title">
  <h2 id="faq-title">Questions and answers</h2>
  <details><summary>How do I book a session?</summary><p>Open <a href="book.php">Book</a>, pick a day (today or up to <?= (int) $s['booking_window_days'] - 1 ?> days ahead), a zone, the minute you want to start and how long you will train (up to <?= View::e(View::duration((int) $s['max_session_minutes'])) ?>). GymZone shows how many places are free for exactly that time before you confirm.</p></details>
  <details><summary>Can I just walk in?</summary><p>Yes. On the dashboard, tap <strong>Check in</strong> on a zone. Your session starts at that minute and lasts as long as you choose. You can check out earlier at any time.</p></details>
  <details><summary>When can I check in to a booking?</summary><p>From <?= (int) $s['checkin_early_minutes'] ?> minutes before your start time until <?= (int) $s['checkin_grace_minutes'] ?> minutes after it. If you do not check in by then, the place is released for others. This has no other effect on you.</p></details>
  <details><summary>What if I forget to check out?</summary><p>If you are still checked in after <?= (int) $s['auto_checkout_hours'] ?> hours, GymZone checks you out automatically. Next time you open the app, it asks when you really left so your record is correct.</p></details>
  <details><summary>How many sessions can I book?</summary><p>One session per zone each day, so you can use Cardio, Weights and Machines on the same day at different times. Your sessions cannot overlap.</p></details>
  <details><summary>How do I cancel?</summary><p>Go to <a href="bookings.php">My bookings</a> and tap <strong>Cancel</strong> on an upcoming booking. The place becomes free for others straight away.</p></details>
  <details><summary>Can I use two accounts at the same time?</summary><p>One browser keeps one signed-in account, shared by all its tabs, like most websites. To use a second account at the same time, open a private (incognito) window or another browser.</p></details>
  <details><summary>How do I change my email or password?</summary><p>In <a href="settings.php">Settings</a>. For your security, we email a confirmation link to your current address first, and the change happens when you open it.</p></details>
  <details><summary>My name or apartment is wrong</summary><p>These are set by the housing office. Send a message above and they will correct it.</p></details>
</section>
<?php View::footer(['help.js']);
