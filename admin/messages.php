<?php
// Admin: messages from residents, and the contact details shown on the Help page.
require __DIR__ . '/../app/bootstrap.php';
$admin = Auth::requirePage('admin');
// Which messages to show: open (the default), handled or all.
$filter = in_array($_GET['show'] ?? '', ['open', 'handled', 'all'], true) ? $_GET['show'] : 'open';
$rows = Contact::list($filter);
$c = Contact::details();
View::header('Messages', 'messages');
?>
<div class="page-head"><div><h1>Messages</h1><p class="muted">Questions and reports residents send from the Help page. Each message is also emailed to <?= View::e($c['email'] ?: 'the contact address') ?>.</p></div></div>

<nav class="tabs" aria-label="Message filter">
  <?php foreach (['open' => 'Open', 'handled' => 'Handled', 'all' => 'All'] as $k => $l): ?>
    <a href="?show=<?= $k ?>"<?= $filter === $k ? ' aria-current="page"' : '' ?>><?= $l ?><?= $k === 'open' ? '<span class="count">' . Contact::openCount() . '</span>' : '' ?></a>
  <?php endforeach; ?>
</nav>

<?php if (!$rows): ?>
  <div class="card empty-state"><?= View::icon('mail') ?><p><?= $filter === 'open' ? 'No open messages.' : 'No messages here.' ?></p></div>
<?php else: ?>
<div class="stack">
  <?php foreach ($rows as $m): ?>
  <article class="card message-card">
    <div class="section-head">
      <div><strong><?= View::e($m['subject']) ?></strong><span class="sub muted small"><?= View::e($m['name']) ?><?= $m['apartment'] ? ' · Apt ' . View::e($m['apartment']) : '' ?> · <?= View::e(View::date($m['created_at'], 'D j M, H:i')) ?><?= $m['user_id'] ? '' : ' · sent from the sign-in page' ?></span></div>
      <?= $m['handled_at'] ? '<span class="pill pill-completed">Handled</span>' : '<span class="pill pill-pending">Open</span>' ?>
    </div>
    <?php // nl2br() keeps line breaks; View::e() makes sure the message is shown as text, never as HTML. ?>
    <p class="message-text"><?= nl2br(View::e($m['message'])) ?></p>
    <div class="button-row">
      <a class="btn btn-small" href="mailto:<?= View::e($m['email']) ?>?subject=<?= rawurlencode('Re: ' . $m['subject']) ?>"><?= View::icon('mail') ?>Reply by email</a>
      <button class="btn btn-small<?= $m['handled_at'] ? '' : ' btn-primary' ?>" type="button" data-handled="<?= (int) $m['id'] ?>" data-value="<?= $m['handled_at'] ? '0' : '1' ?>"><?= $m['handled_at'] ? 'Mark as open' : 'Mark as handled' ?></button>
      <?php if ($m['handled_at']): ?><span class="muted small">Handled <?= View::e(View::date($m['handled_at'], 'j M H:i')) ?><?= $m['handler'] ? ' by ' . View::e($m['handler']) : '' ?></span><?php endif; ?>
    </div>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php // The contact details form, sent by admin-messages.js. ?>
<form class="card form section" id="contact-details" data-ajax>
  <h2>Contact details shown to residents</h2>
  <div class="form-row">
    <div class="field"><label for="c-email">Email (messages are sent here)</label><input id="c-email" name="email" type="email" required value="<?= View::e($c['email']) ?>"></div>
    <div class="field"><label for="c-phone">Phone (optional)</label><input id="c-phone" name="phone" type="tel" value="<?= View::e($c['phone']) ?>"></div>
  </div>
  <div class="field"><label for="c-hours">Office hours (optional)</label><input id="c-hours" name="hours" maxlength="120" value="<?= View::e($c['hours']) ?>" placeholder="For example: Monday–Friday 9:00–15:00"></div>
  <div><button class="btn btn-primary" type="submit">Save contact details</button></div>
</form>
<?php View::footer(['admin-messages.js']);
