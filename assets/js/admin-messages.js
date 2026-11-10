/* Admin: mark messages handled and save the contact details shown on the Help page. */
(function () {
  'use strict';
  // Mark a message handled, or open again.
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-handled]');
    if (!b) return;
    try { await GZ.busy(b, () => GZ.api('api/admin/messages.php', { action: 'handled', id: +b.dataset.handled, handled: b.dataset.value === '1' })); window.location.reload(); }
    catch (err) { GZ.toast(err.message, 'error'); }
  });
  // Save the contact details.
  const f = document.getElementById('contact-details');
  if (f) f.addEventListener('submit', async (e) => {
    e.preventDefault();
    try { const r = await GZ.busy(f.querySelector('button'), () => GZ.api('api/admin/messages.php', Object.assign({ action: 'contact' }, GZ.formData(f)))); GZ.toast(r.message); }
    catch (err) { GZ.toast(err.message, 'error'); }
  });
})();
