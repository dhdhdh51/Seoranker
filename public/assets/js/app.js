/* Bharat SEO UI helpers — vanilla JS, no inline handlers (CSP: script-src 'self'). */
(function () {
  'use strict';

  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  // Theme toggle
  $$('[data-theme-toggle]').forEach(function (btn) {
    var sync = function () {
      var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
      btn.innerHTML = dark ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon-stars"></i>';
    };
    sync();
    btn.addEventListener('click', function () {
      var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-bs-theme', next);
      try { localStorage.setItem('bseo-theme', next); } catch (e) {}
      sync();
      renderCharts();
    });
  });

  // Confirmation prompts on forms and buttons
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    var submitter = ev.submitter;
    var msg = (submitter && submitter.getAttribute('data-confirm')) || form.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { ev.preventDefault(); return; }
    // prevent double submits
    if (!ev.defaultPrevented && form.method.toLowerCase() === 'post') {
      setTimeout(function () { $$('button[type="submit"], button:not([type])', form).forEach(function (b) { b.disabled = true; }); }, 0);
    }
  }, true);

  // Auto-submit selects
  $$('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { if (el.form) { el.form.submit(); } });
  });

  // Select-all checkboxes
  $$('[data-check-all]').forEach(function (master) {
    master.addEventListener('change', function () {
      $$('input[name="' + master.getAttribute('data-check-all') + '"]').forEach(function (c) { c.checked = master.checked; });
    });
  });

  // Copy to clipboard
  $$('[data-copy-target]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var src = $(btn.getAttribute('data-copy-target'));
      if (!src) { return; }
      var subj = $('#subject');
      var body = $('#body');
      var text = src.value || src.textContent;
      if (btn.getAttribute('data-copy-target') === '#copy-source' && subj && body) {
        text = 'Subject: ' + subj.value + '\n\n' + body.value;
      }
      var done = function () { var old = btn.innerHTML; btn.innerHTML = '<i class="bi bi-check2"></i> Copied'; setTimeout(function () { btn.innerHTML = old; }, 1500); };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text); done(); });
      } else { fallbackCopy(text); done(); }
    });
  });
  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
  }

  // Subject alternative buttons
  $$('[data-fill-target]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var t = $(btn.getAttribute('data-fill-target'));
      if (t) { t.value = btn.getAttribute('data-fill-value'); t.dispatchEvent(new Event('input')); }
    });
  });

  // Live preview
  $$('[data-preview]').forEach(function (input) {
    var out = $('[data-preview-out="' + input.getAttribute('data-preview') + '"]');
    if (!out) { return; }
    input.addEventListener('input', function () { out.textContent = input.value; });
  });

  // Print
  $$('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });

  // ---------------------------------------------------------------- charts (canvas, no library)
  var palette = ['#4f46e5', '#f97316', '#0ea5e9', '#16a34a', '#e11d48', '#a855f7', '#eab308', '#64748b', '#14b8a6'];

  function readJson(id) {
    var el = document.getElementById(id);
    if (!el) { return null; }
    try { return JSON.parse(el.textContent); } catch (e) { return null; }
  }
  function textColor() { return getComputedStyle(document.body).getPropertyValue('--bs-secondary-color') || '#64748b'; }
  function gridColor() { return document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'rgba(148,163,184,.15)' : 'rgba(15,23,42,.08)'; }

  function setupCanvas(box, height) {
    box.innerHTML = '';
    var canvas = document.createElement('canvas');
    var dpr = window.devicePixelRatio || 1;
    var w = box.clientWidth || 600;
    canvas.width = w * dpr; canvas.height = height * dpr;
    canvas.style.height = height + 'px';
    canvas.setAttribute('role', 'img');
    box.appendChild(canvas);
    var ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);
    return { ctx: ctx, w: w, h: height, canvas: canvas };
  }

  function legend(box, items) {
    var lg = document.createElement('div');
    lg.className = 'chart-legend';
    items.forEach(function (it, i) {
      var s = document.createElement('span');
      s.style.setProperty('--c', palette[i % palette.length]);
      s.textContent = it;
      lg.appendChild(s);
    });
    box.appendChild(lg);
  }

  function barChart(box, data) {
    var c = setupCanvas(box, 260);
    var ctx = c.ctx, labels = data.labels, series = data.series;
    var max = 1;
    series.forEach(function (s) { s.values.forEach(function (v) { if (v > max) { max = v; } }); });
    max = Math.max(4, Math.ceil(max / 4) * 4);
    var padL = 36, padB = 46, padT = 10, plotW = c.w - padL - 8, plotH = c.h - padB - padT;
    ctx.font = '11px system-ui, sans-serif';
    ctx.fillStyle = textColor(); ctx.strokeStyle = gridColor();
    for (var g = 0; g <= 4; g++) {
      var y = padT + plotH - plotH * g / 4;
      ctx.beginPath(); ctx.moveTo(padL, y); ctx.lineTo(padL + plotW, y); ctx.stroke();
      ctx.fillText(String(Math.round(max * g / 4)), 2, y + 4);
    }
    var groupW = plotW / Math.max(1, labels.length);
    var barW = Math.max(3, Math.min(22, (groupW - 10) / series.length));
    labels.forEach(function (lab, i) {
      var x0 = padL + i * groupW + (groupW - barW * series.length) / 2;
      series.forEach(function (s, j) {
        var v = s.values[i] || 0, h = plotH * v / max;
        ctx.fillStyle = palette[j % palette.length];
        ctx.fillRect(x0 + j * barW, padT + plotH - h, barW - 2, h);
      });
      ctx.fillStyle = textColor();
      var t = lab.length > 14 ? lab.slice(0, 13) + '…' : lab;
      ctx.save(); ctx.translate(padL + i * groupW + groupW / 2, padT + plotH + 12); ctx.rotate(-0.35);
      ctx.textAlign = 'right'; ctx.fillText(t, 0, 0); ctx.restore();
    });
    c.canvas.setAttribute('aria-label', 'Bar chart of ' + series.map(function (s) { return s.name; }).join(', ') + ' per project');
    legend(box, series.map(function (s) { return s.name; }));
  }

  function donutChart(box, items) {
    var c = setupCanvas(box, 200);
    var ctx = c.ctx, total = items.reduce(function (a, b) { return a + b.value; }, 0) || 1;
    var cx = c.w / 2, cy = 100, r = 85, start = -Math.PI / 2;
    items.forEach(function (it, i) {
      var ang = 2 * Math.PI * it.value / total;
      ctx.beginPath(); ctx.moveTo(cx, cy); ctx.arc(cx, cy, r, start, start + ang); ctx.closePath();
      ctx.fillStyle = palette[i % palette.length]; ctx.fill(); start += ang;
    });
    ctx.beginPath(); ctx.arc(cx, cy, 52, 0, 2 * Math.PI);
    ctx.fillStyle = getComputedStyle(box).backgroundColor && getComputedStyle(box).backgroundColor !== 'rgba(0, 0, 0, 0)' ? getComputedStyle(box).backgroundColor : (document.documentElement.getAttribute('data-bs-theme') === 'dark' ? '#151a2d' : '#ffffff');
    ctx.fill();
    ctx.fillStyle = textColor(); ctx.font = 'bold 18px system-ui, sans-serif'; ctx.textAlign = 'center';
    ctx.fillText(String(total), cx, cy + 6);
    c.canvas.setAttribute('aria-label', 'Donut chart: ' + items.map(function (i) { return i.label + ' ' + i.value; }).join(', '));
    legend(box, items.map(function (i) { return i.label + ' (' + i.value + ')'; }));
  }

  function renderCharts() {
    $$('[data-chart]').forEach(function (box) {
      var data = readJson(box.getAttribute('data-source'));
      if (!data) { box.innerHTML = '<p class="small text-danger">Chart data unavailable.</p>'; return; }
      if (box.getAttribute('data-chart') === 'bars') { barChart(box, data); } else { donutChart(box, data); }
    });
  }
  renderCharts();
  var rt;
  window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(renderCharts, 200); });
})();
