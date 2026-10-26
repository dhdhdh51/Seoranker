/* Private chat: AJAX polling (adaptive interval), optimistic send, load earlier messages. */
(function () {
  'use strict';
  var root = document.getElementById('chat');
  if (!root) { return; }
  var log = document.getElementById('chatLog');
  var form = document.getElementById('chatForm');
  var input = document.getElementById('chatInput');
  var url = root.dataset.url;
  var last = parseInt(root.dataset.last, 10) || 0;
  var first = parseInt(root.dataset.first, 10) || 0;
  var delay = 3000;
  var timer = null;
  var have = {};
  log.querySelectorAll('.msg[data-id]').forEach(function (m) { have[m.dataset.id] = true; });

  function nearBottom() { return log.scrollHeight - log.scrollTop - log.clientHeight < 120; }
  function scrollDown() { log.scrollTop = log.scrollHeight; }

  function node(m) {
    var wrap = document.createElement('div');
    wrap.className = 'msg' + (m.mine ? ' mine' : '');
    wrap.dataset.id = m.id;
    var b = document.createElement('div');
    b.className = 'bubble' + (m.hidden ? ' removed' : '');
    if (m.hidden) { var em = document.createElement('em'); em.textContent = 'Message removed by a moderator'; b.appendChild(em); } else { b.textContent = m.body; }
    var meta = document.createElement('div');
    meta.className = 'meta';
    meta.textContent = m.at;
    if (!m.mine && !m.hidden) {
      var r = document.createElement('button');
      r.type = 'button'; r.className = 'btn btn-link btn-sm p-0 align-baseline small ms-1';
      r.dataset.report = 'message'; r.dataset.id = m.id; r.textContent = '· Report';
      meta.appendChild(r);
    }
    wrap.appendChild(b); wrap.appendChild(meta);
    return wrap;
  }

  function append(list) {
    var stick = nearBottom();
    var empty = log.querySelector('.chat-empty'); if (empty && list.length) { empty.remove(); }
    list.forEach(function (m) {
      if (have[m.id]) { return; }
      have[m.id] = true;
      log.appendChild(node(m));
      if (m.id > last) { last = m.id; }
    });
    if (stick) { scrollDown(); }
  }

  function poll() {
    clearTimeout(timer);
    if (document.hidden) { timer = setTimeout(poll, 15000); return; }
    sff.request(url + '?since=' + last).then(function (r) {
      if (r.messages && r.messages.length) { append(r.messages); delay = 3000; } else { delay = Math.min(delay + 2000, 15000); }
      if (form && r.can_send === false) { form.replaceWith(Object.assign(document.createElement('div'), { className: 'p-3 border-top text-center small text-body-secondary', textContent: r.problem || 'You can no longer send messages here.' })); form = null; }
    }).catch(function (e) { if (e.status === 404 || e.status === 401) { return; } delay = 15000; })
      .finally(function () { timer = setTimeout(poll, delay); });
  }

  if (form) {
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit(); }
    });
    input.addEventListener('input', function () { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight, 140) + 'px'; });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var body = input.value.trim();
      if (!body) { return; }
      var btn = form.querySelector('button'); btn.disabled = true;
      sff.post(url, { body: body }).then(function (r) {
        input.value = ''; input.style.height = 'auto';
        append([r.message]); scrollDown(); delay = 3000; poll();
      }).catch(function (err) { sff.toast(err.message, 'danger'); })
        .finally(function () { btn.disabled = false; input.focus(); });
    });
  }

  var older = log.querySelector('[data-older]');
  if (older) {
    older.addEventListener('click', function () {
      older.disabled = true;
      sff.request(url + '?before=' + first).then(function (r) {
        var h = log.scrollHeight;
        var anchor = older.nextSibling;
        (r.messages || []).forEach(function (m) { if (have[m.id]) { return; } have[m.id] = true; log.insertBefore(node(m), anchor); if (m.id < first) { first = m.id; } });
        log.scrollTop += log.scrollHeight - h;
        if (!r.messages || r.messages.length < 50) { older.remove(); } else { older.disabled = false; }
      }).catch(function () { older.disabled = false; });
    });
  }

  scrollDown();
  timer = setTimeout(poll, delay);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { delay = 3000; poll(); } });
})();
