/* Admin: add, edit, invite, deactivate and reactivate residents. */
(function () {
  'use strict';
  const endpoint = 'api/admin/residents.php';

  // Add resident: errors are shown inside the dialog.
  const add = document.getElementById('add-form');
  if (add) add.addEventListener('submit', async (e) => {
    e.preventDefault();
    const dialog = add.closest('dialog');
    const btn = add.querySelector('button[type=submit]');
    try {
      await GZ.busy(btn, () => GZ.api(endpoint, Object.assign({ action: 'create' }, GZ.formData(add))));
      window.location.reload();
    } catch (err) { GZ.showModalError(dialog, err.message); }
  });

  // Edit the name and apartment (and the email while the account is pending).
  const edit = document.getElementById('edit-form');
  if (edit) edit.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = edit.querySelector('button[type=submit]');
    try {
      await GZ.busy(btn, () => GZ.api(endpoint, Object.assign({ action: 'update', id: +edit.dataset.id }, GZ.formData(edit))));
      window.location.reload();
    } catch (err) { GZ.toast(err.message, 'error'); }
  });

  // Buttons with data-action: resend, deactivate, activate and delete.
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-action]');
    if (!b) return;
    const action = b.dataset.action;
    const id = +b.dataset.id;
    // Deactivating and deleting ask for confirmation first.
    if (action === 'deactivate') {
      const ok = await GZ.confirm({ title: 'Deactivate account?', danger: true, confirmLabel: 'Deactivate',
        message: (b.dataset.name || 'This resident') + ' will no longer be able to sign in. Upcoming bookings are cancelled and a session in progress is ended. You can reactivate the account later.' });
      if (!ok) return;
    }
    if (action === 'delete') {
      const ok = await GZ.confirm({ title: 'Delete ' + b.dataset.name + '?', danger: true, confirmLabel: 'Delete permanently',
        message: 'The account, bookings and workout history are removed and cannot be restored. The resident is notified by email.' });
      if (!ok) return;
    }
    // Send the action; delete answers with a redirect to the resident list.
    try {
      const res = await GZ.busy(b, () => GZ.api(endpoint, { action: action, id: id }));
      if (res.redirect) window.location.href = res.redirect;
      else if (action === 'resend') GZ.toast(res.message);
      else window.location.reload();
    } catch (err) { GZ.toast(err.message, 'error'); }
  });
})();
