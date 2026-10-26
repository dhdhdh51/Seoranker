/* Student Flatmate Finder — shared behaviour (no inline scripts; CSP script-src 'self'). */
(function () {
  'use strict';
  var meta = function (n) { var m = document.querySelector('meta[name="' + n + '"]'); return m ? m.content : ''; };
  var base = meta('base-path');

  /** fetch() wrapper adding the CSRF token and JSON parsing. */
  window.sff = {
    url: function (p) { return base + p; },
    request: function (url, opts) {
      opts = opts || {};
      opts.headers = Object.assign({ 'Accept': 'application/json', 'X-CSRF-Token': meta('csrf-token') }, opts.headers || {});
      opts.credentials = 'same-origin';
      return fetch(url, opts).then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (data) {
          if (!r.ok) { var err = new Error(data.error || 'Something went wrong. Please try again.'); err.status = r.status; throw err; }
          return data;
        });
      });
    },
    post: function (url, data) {
      var body = new URLSearchParams();
      Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
      return this.request(url, { method: 'POST', body: body });
    },
    toast: function (msg, type) {
      var wrap = document.getElementById('sffToasts');
      if (!wrap) { wrap = document.createElement('div'); wrap.id = 'sffToasts'; wrap.className = 'toast-container position-fixed top-0 end-0 p-3'; wrap.setAttribute('aria-live', 'polite'); document.body.appendChild(wrap); }
      var t = document.createElement('div');
      t.className = 'toast align-items-center border-0 show text-bg-' + (type || 'dark');
      t.setAttribute('role', 'status');
      var d = document.createElement('div'); d.className = 'd-flex';
      var b = document.createElement('div'); b.className = 'toast-body'; b.textContent = msg;
      d.appendChild(b); t.appendChild(d); wrap.appendChild(t);
      setTimeout(function () { t.remove(); }, 4500);
    }
  };

  // Dark mode toggle
  document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
    var sync = function () { var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark'; btn.innerHTML = dark ? '<i class="bi bi-sun" aria-hidden="true"></i>' : '<i class="bi bi-moon-stars" aria-hidden="true"></i>'; btn.setAttribute('aria-pressed', dark ? 'true' : 'false'); };
    sync();
    btn.addEventListener('click', function () {
      var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-bs-theme', next);
      try { localStorage.setItem('sff-theme', next); } catch (e) {}
      sync();
    });
  });

  // Accessible confirmation dialog for sensitive actions (falls back to window.confirm)
  var confirmModal = null;
  function askConfirm(message, okLabel, onOk) {
    if (!window.bootstrap) { if (window.confirm(message)) { onOk(); } return; }
    if (!confirmModal) {
      var m = document.createElement('div');
      m.className = 'modal fade'; m.tabIndex = -1; m.id = 'confirmModal';
      m.setAttribute('aria-labelledby', 'confirmTitle'); m.setAttribute('aria-hidden', 'true');
      m.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content p-2"><div class="modal-body">' +
        '<h2 class="h5 mb-2" id="confirmTitle">Are you sure?</h2><p class="mb-0 text-body-secondary" data-msg></p></div>' +
        '<div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger" data-ok>Confirm</button></div></div></div>';
      document.body.appendChild(m);
      confirmModal = m;
    }
    confirmModal.querySelector('[data-msg]').textContent = message;
    var ok = confirmModal.querySelector('[data-ok]');
    ok.textContent = okLabel || 'Confirm';
    var inst = bootstrap.Modal.getOrCreateInstance(confirmModal);
    var handler = function () { ok.removeEventListener('click', handler); inst.hide(); onOk(); };
    ok.addEventListener('click', handler);
    confirmModal.addEventListener('hidden.bs.modal', function () { ok.removeEventListener('click', handler); }, { once: true });
    inst.show();
  }
  document.addEventListener('submit', function (e) {
    var f = e.target.closest('form[data-confirm]');
    if (!f || f.dataset.confirmed === '1') { return; }
    e.preventDefault();
    askConfirm(f.getAttribute('data-confirm'), f.getAttribute('data-confirm-ok'), function () { f.dataset.confirmed = '1'; if (f.requestSubmit) { f.requestSubmit(); } else { f.submit(); } });
  }, true);
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-confirm-click]');
    if (!b || b.dataset.confirmed === '1') { return; }
    e.preventDefault();
    askConfirm(b.getAttribute('data-confirm-click'), b.getAttribute('data-confirm-ok'), function () { b.dataset.confirmed = '1'; b.click(); });
  }, true);

  // Loading feedback on normal form submits (prevents double submits)
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (e.defaultPrevented || f.method.toLowerCase() !== 'post' || f.id === 'chatForm') { return; }
    var btn = e.submitter || f.querySelector('button[type="submit"], button:not([type])');
    if (!btn || btn.getAttribute('aria-busy') === 'true') { return; }
    setTimeout(function () {
      btn.setAttribute('aria-busy', 'true');
      var sp = document.createElement('span'); sp.className = 'spinner-border'; sp.setAttribute('aria-hidden', 'true');
      btn.prepend(sp);
    }, 0);
  });

  // City → college dependent selects (filter options by data-city)
  document.querySelectorAll('[data-city-select]').forEach(function (city) {
    var target = document.querySelector(city.getAttribute('data-city-select'));
    if (!target) { return; }
    var apply = function () {
      var c = city.value;
      Array.prototype.forEach.call(target.options, function (o) {
        if (!o.dataset.city) { return; }
        var show = !c || o.dataset.city === c;
        o.hidden = !show; o.disabled = !show;
        if (!show && o.selected) { target.value = ''; }
      });
    };
    city.addEventListener('change', function () {
      if (target.dataset.loadColleges && city.value && !target.querySelector('option[data-city="' + city.value + '"]')) {
        sff.request(sff.url('/colleges.json?city=' + encodeURIComponent(city.value))).then(function (rows) {
          rows.forEach(function (r) { var o = document.createElement('option'); o.value = r.id; o.textContent = r.name; o.dataset.city = r.city_id; target.appendChild(o); });
          apply();
        }).catch(function () {});
      }
      apply();
    });
    apply();
  });

  // Save / unsave (listings and profiles)
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-save]');
    if (!b) { return; }
    e.preventDefault();
    b.disabled = true;
    sff.post(sff.url('/api/save'), { type: b.dataset.save, id: b.dataset.id }).then(function (r) {
      b.setAttribute('aria-pressed', r.saved ? 'true' : 'false');
      var i = b.querySelector('i'); if (i) { i.className = 'bi ' + (r.saved ? 'bi-bookmark-fill' : 'bi-bookmark') + (i.classList.contains('me-1') ? ' me-1' : ''); }
      var s = b.querySelector('span'); if (s) { s.textContent = r.saved ? 'Saved' : 'Save'; }
    }).catch(function (err) { sff.toast(err.message, 'danger'); }).finally(function () { b.disabled = false; });
  });

  // Report modal
  var modalEl = document.getElementById('reportModal');
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-report]');
    if (!b || !modalEl || !window.bootstrap) { return; }
    e.preventDefault();
    modalEl.querySelector('[name="target_type"]').value = b.dataset.report;
    modalEl.querySelector('[name="target_id"]').value = b.dataset.id;
    var blk = modalEl.querySelector('[data-block-option]');
    if (blk) { blk.hidden = b.dataset.report === 'listing'; }
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
  });

  // Nav badge polling (pauses when the tab is hidden)
  var badges = document.querySelectorAll('[data-badge]');
  if (badges.length) {
    var poll = function () {
      if (document.hidden) { return; }
      sff.request(sff.url('/api/poll')).then(function (r) {
        badges.forEach(function (el) {
          var n = r[el.dataset.badge] || 0;
          el.textContent = n > 99 ? '99+' : String(n);
          el.hidden = n === 0;
        });
      }).catch(function () {});
    };
    setInterval(poll, 30000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); } });
  }

  // OTP inputs: digits only
  document.querySelectorAll('.otp-input').forEach(function (i) {
    i.addEventListener('input', function () { i.value = i.value.replace(/[^0-9]/g, '').slice(0, 6); });
  });
})();
