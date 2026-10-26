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
    var sync = function () { var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark'; btn.innerHTML = dark ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon-stars"></i>'; btn.setAttribute('aria-pressed', dark ? 'true' : 'false'); };
    sync();
    btn.addEventListener('click', function () {
      var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-bs-theme', next);
      try { localStorage.setItem('sff-theme', next); } catch (e) {}
      sync();
    });
  });

  // Confirmation for destructive forms/buttons
  document.addEventListener('submit', function (e) {
    var f = e.target.closest('form[data-confirm]');
    if (f && !window.confirm(f.getAttribute('data-confirm'))) { e.preventDefault(); }
  });
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-confirm-click]');
    if (b && !window.confirm(b.getAttribute('data-confirm-click'))) { e.preventDefault(); }
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
