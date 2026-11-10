/* Resident actions shared by the dashboard and My bookings: check in, walk-in, check out, cancel, check-out report. */
(function () {
  'use strict';
  // Reload the page shortly after an action, so every number is fresh.
  const reloadSoon = () => setTimeout(() => window.location.reload(), 900);

  // One click handler for the whole page; it checks which kind of button was clicked.
  document.addEventListener('click', async (e) => {
    // Check in on a zone card (a walk-in): open the dialog for that zone.
    const w = e.target.closest('[data-walkin]');
    if (w) {
      const d = GZ.openModal('walkin-modal');
      d.querySelector('[name=zone_id]').value = w.dataset.zone;
      d.querySelector('#walkin-title').textContent = 'Check in to ' + w.dataset.zoneName;
      const now = new Date();
      d.querySelector('#walkin-start').textContent = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
      d.querySelector('#walkin-minutes').value = d.querySelector('#walkin-minutes').querySelector('[selected]').value;
      return;
    }
    // Check in to a booking, or check out.
    const b = e.target.closest('[data-attendance]');
    if (b) {
      const data = { action: b.dataset.attendance };
      if (data.action === 'checkin') data.booking_id = +b.dataset.booking;
      try {
        const res = await GZ.busy(b, () => GZ.api('api/attendance.php', data));
        // Check-out answers with a redirect to the thank-you page.
        if (res.redirect) { window.location.href = res.redirect; return; }
        GZ.toast(res.message);
        reloadSoon();
      } catch (err) { GZ.toast(err.message, 'error'); }
      return;
    }
    // Cancel: ask first, then cancel and remove the row.
    const c = e.target.closest('[data-cancel]');
    if (c) {
      const ok = await GZ.confirm({ title: 'Cancel this booking?', message: c.dataset.label + '. The place will be released for other residents.', confirmLabel: 'Cancel booking', cancelLabel: 'Keep booking', danger: true });
      if (!ok) return;
      try {
        const res = await GZ.busy(c, () => GZ.api('api/bookings.php', { action: 'cancel', id: +c.dataset.cancel }));
        GZ.toast(res.message);
        const item = c.closest('[data-booking-id]');
        if (item) item.remove();
        reloadSoon();
      } catch (err) { GZ.toast(err.message, 'error'); }
      return;
    }
    // The "I left at ..." button: confirm the automatic check-out time.
    const a = e.target.closest('[data-confirm-auto]');
    if (a) {
      try { const res = await GZ.busy(a, () => GZ.api('api/attendance.php', { action: 'confirm_auto', attendance_id: +a.dataset.confirmAuto })); GZ.toast(res.message); reloadSoon(); }
      catch (err) { GZ.toast(err.message, 'error'); }
    }
  });

  // The walk-in dialog's form: send the zone and length to api/attendance.php (action "walkin").
  const walk = document.getElementById('walkin-form');
  if (walk) walk.addEventListener('submit', async (e) => {
    e.preventDefault();
    const d = walk.closest('dialog');
    try {
      const res = await GZ.busy(walk.querySelector('button[type=submit]'), () => GZ.api('api/attendance.php', Object.assign({ action: 'walkin' }, GZ.formData(walk))));
      d.close();
      GZ.toast(res.message);
      reloadSoon();
    } catch (err) { GZ.showModalError(d, err.message); }
  });

  // The "when did you leave?" form after an automatic check-out.
  const report = document.getElementById('report-form');
  if (report) report.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const res = await GZ.busy(report.querySelector('button'), () => GZ.api('api/attendance.php', { action: 'report_checkout', attendance_id: +report.dataset.id, time: report.time.value }));
      GZ.toast(res.message);
      setTimeout(() => window.location.reload(), 1800);
    } catch (err) { GZ.toast(err.message, 'error'); }
  });
})();
