/* GymZone shared front-end helpers: API calls (Fetch), toasts, modals, loading states. No frameworks. */
// Everything is wrapped in a function that runs at once, so its variables do not leak into the page.
(function () {
  'use strict';
  // meta(): reads a meta tag that View::header() put in the page, for example the CSRF token.
  const meta = (name) => (document.querySelector('meta[name="' + name + '"]') || {}).content || '';
  // The web folder, for example /gymzone/.
  const base = meta('base-path') || '/';

  // GZ is the small toolbox that every page script uses.
  const GZ = {
    base: base,
    // url(): a link inside GymZone.
    url: (path) => base + String(path).replace(/^\//, ''),

    /** Calls a JSON endpoint. GET without data, POST with data. Throws an Error with .data on failure. */
    async api(path, data, options) {
      // No data means GET; with data it is a POST.
      const opts = Object.assign({ method: data === undefined ? 'GET' : 'POST' }, options || {});
      // same-origin sends the session cookie; no-store means never use a cached answer.
      const init = { method: opts.method, headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' };
      // A POST sends JSON plus the CSRF token in the X-CSRF-Token header (checked by Auth::verifyCsrf()).
      if (opts.method !== 'GET') {
        init.headers['Content-Type'] = 'application/json';
        init.headers['X-CSRF-Token'] = meta('csrf-token');
        init.body = JSON.stringify(data || {});
      }
      let res, body;
      // Send the request and read the JSON answer. A network failure becomes a friendly error.
      try {
        res = await fetch(GZ.url(path), init);
        body = await res.json();
      } catch (e) {
        const err = new Error('We could not reach GymZone. Check your connection and try again.');
        err.data = {};
        throw err;
      }
      // 401: the session has ended, so go to the sign-in page.
      if (res.status === 401) { window.location.href = GZ.url('login.php'); }
      // An error answer: throw an Error with the server's message, so the page can show it.
      if (!res.ok || body.ok === false) {
        const err = new Error(body.error || 'Something went wrong. Please try again.');
        err.data = body;
        err.status = res.status;
        throw err;
      }
      return body;
    },

    // toast(): a short message at the bottom of the screen that disappears by itself.
    toast(message, type) {
      const region = document.getElementById('toast-region');
      if (!region) return;
      const el = document.createElement('div');
      el.className = 'toast ' + (type || 'success');
      el.setAttribute('role', type === 'error' ? 'alert' : 'status');
      // textContent (not innerHTML) shows the message as plain text, so it can never run as HTML.
      el.textContent = message;
      region.appendChild(el);
      setTimeout(() => el.remove(), type === 'error' ? 7000 : 4500);
    },

    /** Shows a spinner on a button while a promise runs. */
    async busy(button, work) {
      if (button) { button.classList.add('is-loading'); button.disabled = true; }
      try { return await work(); }
      finally { if (button) { button.classList.remove('is-loading'); button.disabled = false; } }
    },

    // openModal(): opens a dialog, clears old errors, resets its form and puts the cursor in the first field.
    openModal(id) {
      const d = typeof id === 'string' ? document.getElementById(id) : id;
      if (!d) return null;
      const err = d.querySelector('.modal-error');
      if (err) { err.hidden = true; err.textContent = ''; }
      const form = d.querySelector('form');
      if (form && !d.dataset.keep) form.reset();
      d.showModal();
      const first = d.querySelector('input:not([type=hidden]), select, textarea, .btn-primary');
      if (first) first.focus();
      return d;
    },

    // closeModal(): closes the dialog that contains the given element.
    closeModal(el) {
      const d = el && el.closest ? el.closest('dialog') : el;
      if (d && d.open) d.close();
    },

    // showModalError(): shows an error message inside a dialog.
    showModalError(dialog, message) {
      const err = dialog.querySelector('.modal-error');
      if (err) { err.textContent = message; err.hidden = false; } else { GZ.toast(message, 'error'); }
    },

    /** Promise-based confirmation dialog. */
    confirm(opts) {
      return new Promise((resolve) => {
        const d = document.createElement('dialog');
        d.className = 'modal';
        d.setAttribute('aria-labelledby', 'confirm-title');
        d.innerHTML = '<div class="modal-head"><h2 id="confirm-title"></h2></div><div class="modal-body"><p class="confirm-msg"></p>'
          + '<div class="modal-actions"><button type="button" class="btn" data-no></button><button type="button" class="btn" data-yes></button></div></div>';
        d.querySelector('h2').textContent = opts.title || 'Are you sure?';
        d.querySelector('.confirm-msg').textContent = opts.message || '';
        const yes = d.querySelector('[data-yes]');
        yes.textContent = opts.confirmLabel || 'Confirm';
        yes.classList.add(opts.danger ? 'btn-danger-solid' : 'btn-primary');
        d.querySelector('[data-no]').textContent = opts.cancelLabel || 'Go back';
        document.body.appendChild(d);
        let answer = false;
        yes.addEventListener('click', () => { answer = true; d.close(); });
        d.querySelector('[data-no]').addEventListener('click', () => d.close());
        d.addEventListener('close', () => { d.remove(); resolve(answer); });
        d.showModal();
        yes.focus();
      });
    },

    /**
     * Posts an admin change. If the server answers that bookings would be cancelled
     * (needs_confirm), asks first and resends with confirm = true.
     */
    async apiWithConfirm(path, data, title) {
      // Try the request normally. If the server answers needs_confirm (the change would cancel bookings),
      // ask the administrator, and only if they agree, send the same request again with confirm: true.
      try {
        return await GZ.api(path, data);
      } catch (e) {
        if (!e.data || !e.data.needs_confirm) throw e;
        const ok = await GZ.confirm({ title: title || 'Cancel affected bookings?', message: e.message, confirmLabel: 'Continue and cancel bookings', danger: true });
        if (!ok) { const c = new Error('No changes were made.'); c.cancelled = true; throw c; }
        return GZ.api(path, Object.assign({}, data, { confirm: true }));
      }
    },

    // formData(): turns a form into a plain object; checkboxes become true or false.
    formData(form) {
      const out = {};
      new FormData(form).forEach((v, k) => { out[k] = v; });
      form.querySelectorAll('input[type=checkbox][name]').forEach((c) => { out[c.name] = c.checked; });
      return out;
    },

    // escape(): makes text safe to put into HTML built by JavaScript (protects against XSS).
    escape(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    },

    // applyMeters(): sets the width of each availability bar from its data-pct value.
    applyMeters(root) {
      (root || document).querySelectorAll('[data-pct]').forEach((el) => { el.style.width = Math.max(0, Math.min(100, +el.dataset.pct)) + '%'; });
    }
  };

  // Close buttons, password visibility toggles, and meters.
  document.addEventListener('click', (e) => {
    const close = e.target.closest('[data-close]');
    if (close) GZ.closeModal(close);
    const open = e.target.closest('[data-open-modal]');
    if (open) GZ.openModal(open.dataset.openModal);
    const toggle = e.target.closest('.password-toggle');
    if (toggle) {
      const input = toggle.parentElement.querySelector('input');
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      toggle.textContent = show ? 'Hide' : 'Show';
      toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
    }
  });
  // Clicking the backdrop closes a modal.
  document.addEventListener('click', (e) => {
    if (e.target.tagName === 'DIALOG' && e.target.classList.contains('modal')) e.target.close();
  });
  // Forms that submit normally show a loading state on their button.
  document.addEventListener('submit', (e) => {
    const f = e.target;
    if (f.dataset.ajax !== undefined || e.defaultPrevented) return;
    const b = f.querySelector('button[type=submit]:not(.btn-icon)');
    if (b) setTimeout(() => { b.classList.add('is-loading'); }, 0);
  });
  // Tabs: buttons with role="tab" inside [data-tabs] show the matching panel.
  document.querySelectorAll('[data-tabs]').forEach((box) => {
    const tabs = Array.from(box.querySelectorAll('[role=tab]'));
    const select = (t) => {
      tabs.forEach((x) => { const on = x === t; x.setAttribute('aria-selected', on ? 'true' : 'false'); x.tabIndex = on ? 0 : -1;
        const p = document.getElementById(x.getAttribute('aria-controls')); if (p) p.hidden = !on; });
      const key = t.getAttribute('aria-controls').replace('panel-', '');
      try { history.replaceState(null, '', '?tab=' + key); } catch (e) { /* ignore */ }
    };
    tabs.forEach((t, i) => {
      t.addEventListener('click', () => select(t));
      t.addEventListener('keydown', (e) => {
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
        const n = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
        n.focus(); select(n);
      });
    });
  });
  // Filters marked data-autosubmit reload the page as soon as they change.
  document.querySelectorAll('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form.submit()));
  GZ.applyMeters();
  // Make GZ available to the other scripts.
  window.GZ = GZ;
})();
