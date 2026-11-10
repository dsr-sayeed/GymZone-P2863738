/* Settings: username, email change and password change (both confirmed by email), and account deletion. */
(function () {
  'use strict';
  // Username: saved straight away.
  const uform = document.getElementById('username-form');
  if (uform) uform.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = uform.querySelector('button[type=submit]');
    try {
      const res = await GZ.busy(btn, () => GZ.api('api/account.php', { action: 'username', username: uform.username.value }));
      uform.username.value = res.username;
      document.querySelectorAll('.who').forEach((el) => { el.textContent = res.username; });
      GZ.toast(res.message);
    } catch (err) { GZ.toast(err.message, 'error'); }
  });

  // Email and password modals: submit, then show "check your email" in place of the form.
  function modalFlow(formId, action, onDone) {
    const form = document.getElementById(formId);
    if (!form) return;
    const dialog = form.closest('dialog');
    dialog.addEventListener('close', () => { form.hidden = false; const d = dialog.querySelector('.modal-done'); if (d) d.hidden = true; });
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = form.querySelector('button[type=submit]');
      try {
        const res = await GZ.busy(btn, () => GZ.api('api/account.php', Object.assign({ action: action }, GZ.formData(form))));
        if (onDone && onDone(res) === false) return;
        form.hidden = true;
        const done = dialog.querySelector('.modal-done');
        done.querySelector('.alert').textContent = res.message;
        done.hidden = false;
        done.querySelector('button').focus();
      } catch (err) { GZ.showModalError(dialog, err.message); }
    });
  }
  // Email change: also show the address that is waiting for confirmation.
  modalFlow('email-form', 'email_change', (res) => {
    document.getElementById('email-pending-value').textContent = res.new_email;
    document.getElementById('email-pending').hidden = false;
  });
  // Password change: the dialog then says "check your email".
  modalFlow('password-form', 'password_change');
  // Account deletion: go to the sign-in page afterwards.
  modalFlow('delete-form', 'delete', (res) => { window.location.href = res.redirect; return false; });
})();
