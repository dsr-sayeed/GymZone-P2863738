/*
 * Booking flow on one page: date → zone → start time and length → review → confirm.
 * The start time can be any minute. Every change is checked against the server
 * (api/availability.php?view=check), which applies exactly the same rules as the booking itself.
 */
(function () {
  'use strict';
  // The booking container from book.php; stop if this is not the booking page.
  const root = document.getElementById('booking');
  if (!root) return;
  const $ = (id) => document.getElementById(id);
  // Everything the page remembers: the days, zones, chosen week, date and zone, and the last check result.
  const state = { days: [], zones: [], week: 0, date: root.dataset.date || null, zone: +root.dataset.zone || null, check: null, now: null };
  const stateLabel = { open: 'Open', closed: 'Closed', over: 'Ended' };
  // The start-time and length inputs.
  const startInput = $('start-time');
  const minutesInput = $('minutes');
  // checkTimer delays the live check while typing; checkSeq lets old answers that arrive late be ignored.
  let checkTimer = null;
  let checkSeq = 0;

  // setSteps(): marks each of the 4 steps as done or current.
  function setSteps() {
    const ok = !!(state.check && state.check.ok);
    const done = [!!state.date, !!state.zone, ok];
    const current = !state.date ? 1 : !state.zone ? 2 : !ok ? 3 : 4;
    root.querySelectorAll('.step').forEach((s) => {
      const n = +s.dataset.step;
      s.classList.toggle('is-done', n < 4 && done[n - 1]);
      s.classList.toggle('is-active', n === current);
      if (n === current) s.setAttribute('aria-current', 'step'); else s.removeAttribute('aria-current');
    });
  }

  // renderWeeks(): the "This week / Next week / Week 3" tabs above the calendar.
  function renderWeeks() {
    const tabs = $('week-tabs');
    tabs.innerHTML = '';
    const names = ['This week', 'Next week', 'Week 3'];
    for (let w = 0; w * 7 < state.days.length; w++) {
      const chunk = state.days.slice(w * 7, w * 7 + 7);
      const b = document.createElement('button');
      b.type = 'button';
      b.setAttribute('role', 'tab');
      b.setAttribute('aria-selected', w === state.week ? 'true' : 'false');
      b.innerHTML = GZ.escape(names[w] || 'Week ' + (w + 1)) + '<small>' + GZ.escape(chunk[0].day + ' ' + chunk[0].month + ' – ' + chunk[chunk.length - 1].day + ' ' + chunk[chunk.length - 1].month) + '</small>';
      b.addEventListener('click', () => { state.week = w; renderWeeks(); renderCalendar(); });
      tabs.appendChild(b);
    }
  }

  // renderCalendar(): one button per day of the chosen week; closed and ended days are disabled.
  function renderCalendar() {
    const cal = $('calendar');
    cal.innerHTML = '';
    state.days.slice(state.week * 7, state.week * 7 + 7).forEach((d) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'day state-' + d.state + (d.today ? ' is-today' : '');
      b.disabled = d.state !== 'open';
      b.setAttribute('aria-pressed', d.date === state.date ? 'true' : 'false');
      b.setAttribute('aria-label', d.label + ': ' + stateLabel[d.state] + (d.hours ? ', ' + d.hours : '') + (d.mine ? ', you have a session' : ''));
      // GZ.escape() makes the text safe before it goes into innerHTML.
      b.innerHTML = '<span class="dw">' + GZ.escape(d.today ? 'Today' : d.weekday) + '</span><span class="dn">' + d.day + '</span><span class="ds">' + GZ.escape(stateLabel[d.state]) + '</span>'
        + (d.mine ? '<span class="mine" aria-hidden="true"></span>' : '');
      b.addEventListener('click', () => { state.date = d.date; renderCalendar(); suggestStart(); loadDay(); });
      cal.appendChild(b);
    });
  }

  // renderZones(): one button per zone.
  function renderZones() {
    const box = $('zone-picker');
    box.innerHTML = '';
    state.zones.forEach((z) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'zone-option';
      b.setAttribute('aria-pressed', z.id === state.zone ? 'true' : 'false');
      b.innerHTML = '<strong>' + GZ.escape(z.name) + '</strong><span>' + (z.open ? z.capacity + ' places' : 'Temporarily closed') + '</span>';
      b.addEventListener('click', () => { state.zone = z.id; renderZones(); loadDay(); });
      box.appendChild(b);
    });
  }

  /** On today, start at the next quarter hour; on other days keep the chosen time (or 17:00). */
  function suggestStart() {
    if (state.date && state.now && state.date === state.now.slice(0, 10)) {
      const [h, m] = state.now.slice(11, 16).split(':').map(Number);
      let t = h * 60 + m + 5;
      t = Math.ceil(t / 15) * 15;
      if (t >= 1440) t = 1425;
      if (!startInput.value || startInput.value < pad(Math.floor(t / 60)) + ':' + pad(t % 60)) startInput.value = pad(Math.floor(t / 60)) + ':' + pad(t % 60);
    } else if (!startInput.value) {
      startInput.value = '17:00';
    }
  }
  const pad = (n) => String(n).padStart(2, '0');

  // loadDay(): loads the free places hour by hour for the chosen date and zone, and draws the grid.
  async function loadDay() {
    setSteps();
    const box = $('hours');
    if (!state.date || !state.zone) return;
    box.innerHTML = '<div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div>';
    let data;
    try { data = await GZ.api('api/availability.php?view=day&date=' + state.date + '&zone=' + state.zone); }
    catch (e) { box.innerHTML = ''; $('day-caption').textContent = e.message; return; }
    $('day-caption').textContent = data.zone.name + ' · ' + data.date_label + (data.hours_label ? ' · ' + data.hours_label : '')
      + (data.mine.length ? ' · your sessions: ' + data.mine.join(', ') : '');
    box.innerHTML = '';
    // One button per hour that has not passed; full and closed hours are disabled.
    data.hours.filter((h) => h.state !== 'past').forEach((h) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'hour hour-' + h.state;
      b.disabled = h.state === 'closed' || h.state === 'full';
      const sub = h.state === 'closed' ? (h.reason || 'Closed') : h.state === 'full' ? 'Full' : h.free + ' free';
      b.innerHTML = '<strong>' + h.hour + '</strong><span>' + GZ.escape(sub) + '</span>';
      b.setAttribute('aria-label', h.hour + ': ' + sub);
      b.title = sub;
      // Clicking an hour sets the start time to that hour (or to the next quarter hour today) and checks it.
      b.addEventListener('click', () => {
        let v = h.hour;
        if (state.now && state.date === state.now.slice(0, 10) && v <= state.now.slice(11, 16)) suggestStart(); else startInput.value = v;
        runCheck(true);
      });
      box.appendChild(b);
    });
    if (!box.children.length) box.innerHTML = '<div class="empty-state">There is no time left to book on this day.</div>';
    runCheck(true);
  }

  // runCheck(): waits 350 ms after the last key press, so typing does not send a request for every key.
  function runCheck(immediate) {
    clearTimeout(checkTimer);
    checkTimer = setTimeout(doCheck, immediate ? 0 : 350);
  }

  // doCheck(): asks the server whether this exact session can be booked (the same rules as a real booking).
  async function doCheck() {
    const out = $('check-result');
    state.check = null;
    renderSummary();
    if (!state.date || !state.zone || !startInput.value) { out.hidden = true; setSteps(); return; }
    // Number this request. If a newer one starts before this answer arrives, this answer is ignored.
    const seq = ++checkSeq;
    out.hidden = false;
    out.className = 'check-result is-loading';
    out.textContent = 'Checking…';
    let res;
    try { res = await GZ.api('api/availability.php?view=check&zone=' + state.zone + '&start=' + encodeURIComponent(state.date + ' ' + startInput.value) + '&minutes=' + minutesInput.value); }
    catch (e) { res = { check: { ok: false, reason: e.message } }; }
    if (seq !== checkSeq) return;
    state.check = res.check;
    // Show the result: the free places, or the reason it cannot be booked.
    out.className = 'check-result ' + (res.check.ok ? 'ok' : 'bad');
    out.textContent = res.check.ok
      ? res.check.available + ' of ' + res.check.capacity + ' places free from ' + res.check.label.replace('–', ' to ') + '.'
      : res.check.reason;
    renderSummary();
    setSteps();
  }

  // renderSummary(): fills step 4 and enables Confirm only when the check passed.
  function renderSummary() {
    const day = state.days.find((d) => d.date === state.date);
    const zone = state.zones.find((z) => z.id === state.zone);
    const c = state.check && state.check.ok ? state.check : null;
    $('s-date').textContent = day ? day.label : '–';
    $('s-zone').textContent = zone ? zone.name : '–';
    $('s-time').textContent = c ? c.label : '–';
    $('s-length').textContent = c ? c.duration : '–';
    $('s-left').textContent = c ? c.available + ' of ' + c.capacity : '–';
    $('confirm').disabled = !c;
    $('summary-hint').textContent = c ? 'You can cancel upcoming bookings in My bookings.' : 'Choose a start time that is available to continue.';
  }

  // Check again whenever the time or the length changes.
  startInput.addEventListener('input', () => runCheck(false));
  minutesInput.addEventListener('change', () => runCheck(true));

  // Confirm: send the booking. The server checks every rule again, inside a transaction.
  $('confirm').addEventListener('click', async () => {
    if (!state.check || !state.check.ok) return;
    try {
      const res = await GZ.busy($('confirm'), () => GZ.api('api/bookings.php', { action: 'create', zone_id: state.zone, start: state.date + ' ' + startInput.value, minutes: +minutesInput.value }));
      const b = res.booking;
      // Success: hide the form and show the confirmation.
      $('booking-main').hidden = true;
      root.querySelectorAll('.step').forEach((s) => { s.classList.add('is-done'); s.classList.remove('is-active'); });
      $('c-details').textContent = b.zone + ' · ' + b.date + ' · ' + b.time + ' (' + b.duration + ')';
      const c = $('confirmation');
      c.hidden = false;
      c.focus();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (e) {
      // Refused (for example someone took the last place in the meantime): show why and reload the hours.
      GZ.toast(e.message, 'error');
      loadDay();
    }
  });

  // loadCalendar(): runs when the page opens. Loads the days and zones, picks a sensible date and zone,
  // then draws everything and loads the first day.
  async function loadCalendar() {
    let data;
    try { data = await GZ.api('api/availability.php?view=calendar'); }
    catch (e) { $('calendar').innerHTML = '<div class="empty-state">' + GZ.escape(e.message) + '</div>'; return; }
    state.days = data.days;
    state.zones = data.zones;
    state.now = data.now;
    // If the date from the link is not open, use the first open day.
    if (!state.days.some((d) => d.date === state.date && d.state === 'open')) {
      const open = state.days.find((d) => d.state === 'open');
      state.date = open ? open.date : null;
    }
    if (!state.zones.some((z) => z.id === state.zone)) state.zone = state.zones.length ? state.zones[0].id : null;
    const idx = state.days.findIndex((d) => d.date === state.date);
    state.week = idx > 0 ? Math.floor(idx / 7) : 0;
    renderWeeks();
    renderCalendar();
    renderZones();
    suggestStart();
    loadDay();
  }

  // Start.
  loadCalendar();
})();
