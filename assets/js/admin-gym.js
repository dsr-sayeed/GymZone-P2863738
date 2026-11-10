/* Admin: zones, opening hours, booking rules and closures. */
(function () {
  'use strict';
  // All gym changes go to the same endpoint, with different actions.
  const api = 'api/admin/gym.php';
  // Reload the page on the right tab after a change.
  const reloadOn = (tab) => { window.location.href = GZ.url('admin/gym.php?tab=' + tab); };
  // Show errors; "No changes were made" (the administrator pressed Go back) is shown as a normal message.
  const fail = (err) => { if (!err.cancelled) GZ.toast(err.message, 'error'); else GZ.toast(err.message); };

  // Save a zone's name, description and capacity.
  document.querySelectorAll('[data-zone-form]').forEach((f) => f.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const res = await GZ.busy(f.querySelector('button'), () => GZ.api(api, Object.assign({ action: 'zone_update' }, GZ.formData(f))));
      GZ.toast(res.message);
    } catch (err) { fail(err); }
  }));

  const closeModal = document.getElementById('close-modal');
  const closeForm = document.getElementById('close-form');
  // Buttons: close a zone (opens a dialog), reopen a zone, remove a closure.
  document.addEventListener('click', async (e) => {
    const c = e.target.closest('[data-zone-close]');
    if (c) {
      GZ.openModal(closeModal);
      closeForm.id.value = c.dataset.zoneClose;
      document.getElementById('close-title').textContent = 'Close ' + c.dataset.name;
      return;
    }
    const o = e.target.closest('[data-zone-open]');
    if (o) {
      try { await GZ.busy(o, () => GZ.api(api, { action: 'zone_open', id: +o.dataset.zoneOpen })); reloadOn('zones'); } catch (err) { fail(err); }
      return;
    }
    const d = e.target.closest('[data-closure-delete]');
    if (d) {
      const ok = await GZ.confirm({ title: 'Remove this closure?', message: 'Residents will be able to book these sessions again. Bookings cancelled because of the closure are not restored.', confirmLabel: 'Remove closure', danger: true });
      if (!ok) return;
      try { await GZ.busy(d, () => GZ.api(api, { action: 'closure_delete', id: +d.dataset.closureDelete })); reloadOn('closures'); } catch (err) { fail(err); }
    }
  });

  // Close a zone: apiWithConfirm() asks first if bookings would be cancelled.
  if (closeForm) closeForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const data = Object.assign({ action: 'zone_close' }, GZ.formData(closeForm));
    closeModal.close();
    try { await GZ.apiWithConfirm(api, data, 'Close zone and cancel bookings?'); reloadOn('zones'); } catch (err) { fail(err); }
  });

  // Opening hours: collect the 7 table rows into one object, one entry per weekday.
  const hours = document.getElementById('hours-form');
  if (hours) hours.addEventListener('submit', async (e) => {
    e.preventDefault();
    const days = {};
    hours.querySelectorAll('tr[data-day]').forEach((tr) => {
      days[tr.dataset.day] = { is_open: tr.querySelector('[data-f=is_open]').checked, open: tr.querySelector('[data-f=open]').value, close: tr.querySelector('[data-f=close]').value };
    });
    const btn = hours.querySelector('button[type=submit]');
    try {
      await GZ.busy(btn, () => GZ.apiWithConfirm(api, { action: 'hours', days: days }, 'Change opening hours?'));
      reloadOn('hours');
    } catch (err) { fail(err); }
  });

  // Booking rules.
  const rules = document.getElementById('rules-form');
  if (rules) rules.addEventListener('submit', async (e) => {
    e.preventDefault();
    try { const res = await GZ.busy(rules.querySelector('button'), () => GZ.api(api, Object.assign({ action: 'rules' }, GZ.formData(rules)))); GZ.toast(res.message); }
    catch (err) { fail(err); }
  });

  // Plan a closure (asks first if bookings would be cancelled).
  const closure = document.getElementById('closure-form');
  if (closure) closure.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      await GZ.busy(closure.querySelector('button'), () => GZ.apiWithConfirm(api, Object.assign({ action: 'closure_add' }, GZ.formData(closure)), 'Add closure and cancel bookings?'));
      reloadOn('closures');
    } catch (err) { fail(err); }
  });
})();
