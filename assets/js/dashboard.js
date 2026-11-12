/* Dashboard: refreshes zone availability every 15 seconds without reloading the page. */
(function () {
  'use strict';
  // The zone cards; stop if this is not the dashboard.
  const grid = document.getElementById('zones');
  if (!grid) return;
  const labels = { available: 'Available', limited: 'Few places left', full: 'Full', closed: 'Closed' };

  // refresh(): asks the server for fresh numbers and updates each card in place, without reloading the page.
  async function refresh() {
    let data;
    // If the request fails, keep the old numbers and try again next time.
    try { data = await GZ.api('api/availability.php?view=overview'); } catch (e) { return; }
    data.zones.forEach((z) => {
      const card = grid.querySelector('[data-zone-id="' + z.id + '"]');
      if (!card) return;
      // set(): puts a value into the element with the matching data-field attribute.
      const set = (f, v) => { const el = card.querySelector('[data-field="' + f + '"]'); if (el) el.textContent = v; };
      set('available', z.available); set('capacity', z.capacity); set('booked', z.booked); set('checked_in', z.checked_in);
      // The status pill, the card's colour and the fill of the bar.
      const pill = card.querySelector('[data-field="pill"]');
      pill.textContent = labels[z.status]; pill.className = 'pill pill-' + z.status;
      card.className = card.className.replace(/status-\w+/, 'status-' + z.status);
      const bar = card.querySelector('[data-field="bar"]');
      bar.dataset.pct = z.capacity ? Math.round(100 * (z.capacity - z.available) / z.capacity) : 0;
      const closed = card.querySelector('[data-field="closed"]');
      closed.hidden = !z.closed; closed.textContent = z.closed || '';
      // The zone notice, for example "1 treadmill is out of order".
      const notice = card.querySelector('[data-field="notice"]');
      if (notice) { notice.hidden = !z.notice; notice.textContent = z.notice || ''; }
      // The walk-in button is disabled when the zone is full or closed.
      const walk = card.querySelector('[data-walkin]');
      if (walk) walk.disabled = z.status === 'full' || z.status === 'closed';
    });
    GZ.applyMeters(grid);
    const stamp = document.getElementById('updated');
    if (stamp) stamp.textContent = data.updated;
  }
  // Every 15 seconds, but only while the tab is visible; and at once when the user comes back to the tab.
  setInterval(() => { if (!document.hidden) refresh(); }, 15000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
})();
