/* Admin: cancel bookings for operational reasons and check residents out. */
(function () {
  'use strict';
  const api = 'api/admin/bookings.php';
  const modal = document.getElementById('cancel-modal');
  const form = document.getElementById('cancel-form');
  // Cancel opens a dialog for the reason; Check out asks for confirmation first.
  document.addEventListener('click', async (e) => {
    const c = e.target.closest('[data-cancel-booking]');
    if (c) {
      GZ.openModal(modal);
      form.id.value = c.dataset.cancelBooking;
      document.getElementById('cancel-label').textContent = c.dataset.label + '. The resident is notified by email.';
      return;
    }
    const o = e.target.closest('[data-checkout]');
    if (o) {
      const ok = await GZ.confirm({ title: 'Check out ' + o.dataset.name + '?', message: 'Use this if the resident forgot to check out. Their place is released straight away.', confirmLabel: 'Check out' });
      if (!ok) return;
      try { await GZ.busy(o, () => GZ.api(api, { action: 'checkout', id: +o.dataset.checkout })); window.location.reload(); }
      catch (err) { GZ.toast(err.message, 'error'); }
    }
  });
  // Send the cancellation with its reason.
  if (form) form.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      await GZ.busy(form.querySelector('button[type=submit]'), () => GZ.api(api, Object.assign({ action: 'cancel' }, GZ.formData(form))));
      window.location.reload();
    } catch (err) { GZ.showModalError(modal, err.message); }
  });
})();
