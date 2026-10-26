/* Swipe deck: drag/touch, buttons and ← / → keys. Cards are built with textContent (no HTML injection). */
(function () {
  'use strict';
  var deck = document.getElementById('deck');
  var tpl = document.getElementById('cardTpl');
  if (!deck || !tpl) { return; }
  var emptyEl = deck.querySelector('.deck-empty');
  var loadingEl = deck.querySelector('.deck-loading');
  var remainingEl = document.querySelector('[data-remaining]');
  var queue = [];
  var busy = false;
  var fetching = false;
  var exhausted = false;
  var seen = {};

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text) { n.textContent = text; } return n; }

  function build(c) {
    var node = tpl.content.firstElementChild.cloneNode(true);
    node.dataset.id = c.id;
    var img = node.querySelector('img');
    var ini = node.querySelector('.swipe-initials');
    if (c.photo) { img.src = c.photo; img.hidden = false; ini.hidden = true; img.decoding = 'async'; } else { ini.textContent = c.initials; }
    var set = function (f, v) { var x = node.querySelector('[data-f="' + f + '"]'); if (x) { x.textContent = v || '—'; } };
    node.querySelector('[data-f="score"]').textContent = c.score + '%';
    node.querySelector('[data-f="name"]').textContent = c.name;
    node.querySelector('[data-f="age"]').textContent = c.age ? ', ' + c.age : '';
    var sub = [c.college, c.course].filter(Boolean).join(' · ');
    node.querySelector('[data-f="college"]').textContent = sub || 'College not shared';
    set('budget', c.budget ? c.budget + '/mo' : '');
    set('move_in', c.move_in);
    set('locality', c.locality);
    set('status', c.status);
    var bio = node.querySelector('[data-f="bio"]'); if (c.bio) { bio.textContent = c.bio; } else { bio.remove(); }
    var bw = node.querySelector('[data-f="badges"]');
    (c.badges || []).forEach(function (b) { var s = el('span', 'tbadge tbadge-' + b.tone); s.appendChild(el('i', 'bi bi-' + b.icon)); s.appendChild(document.createTextNode(b.label)); bw.appendChild(s); });
    if (!bw.children.length) { bw.remove(); }
    var reasons = node.querySelector('[data-f="reasons"]');
    (c.reasons || []).forEach(function (r) { reasons.appendChild(el('span', 'chip chip-ok', '✓ ' + r)); });
    (c.conflicts || []).forEach(function (r) { reasons.appendChild(el('span', 'chip chip-warn', '! ' + r)); });
    var tags = node.querySelector('[data-f="tags"]');
    (c.tags || []).forEach(function (t) { tags.appendChild(el('span', 'chip', t)); });
    node.setAttribute('aria-label', c.name + ', ' + c.score + '% compatible' + ((c.badges || []).length ? ', ' + c.badges.map(function (b) { return b.label; }).join(', ') : ''));
    attachDrag(node);
    return node;
  }

  function render() {
    loadingEl.hidden = true;
    var cards = deck.querySelectorAll('.swipe-card');
    while (cards.length < 3 && queue.length) {
      var n = build(queue.shift());
      deck.insertBefore(n, deck.firstChild); // newest at the bottom of the stack
      cards = deck.querySelectorAll('.swipe-card');
    }
    var top = topCard();
    emptyEl.hidden = !!top;
    document.querySelectorAll('.deck-actions button').forEach(function (b) { b.disabled = !top; });
    if (top) { top.classList.add('is-top'); }
    if (queue.length < 3 && !exhausted) { load(); }
  }

  function topCard() { var c = deck.querySelectorAll('.swipe-card:not(.gone)'); return c.length ? c[c.length - 1] : null; }

  function load() {
    if (fetching) { return; }
    fetching = true;
    sff.request(deck.dataset.deckUrl).then(function (r) {
      var fresh = (r.cards || []).filter(function (c) { if (seen[c.id]) { return false; } seen[c.id] = true; return true; });
      if (!fresh.length) { exhausted = true; }
      queue = queue.concat(fresh);
    }).catch(function (e) { sff.toast(e.message, 'danger'); exhausted = true; })
      .finally(function () { fetching = false; render(); });
  }

  function decide(action) {
    var card = topCard();
    if (!card || busy) { return; }
    if (action === 'info') { window.location.href = sff.url('/profile/' + card.dataset.id); return; }
    busy = true;
    card.classList.add('gone', action === 'like' ? 'fly-right' : 'fly-left');
    sff.post(deck.dataset.swipeUrl, { target_id: card.dataset.id, action: action }).then(function (r) {
      if (remainingEl && typeof r.remaining === 'number') { remainingEl.textContent = r.remaining; }
      if (r.matched) { showMatch(card.querySelector('[data-f="name"]').textContent, r.chat_url); }
      var next = topCard(); if (next) { next.focus({ preventScroll: true }); }
    }).catch(function (e) {
      card.classList.remove('gone', 'fly-right', 'fly-left'); card.style.transform = '';
      sff.toast(e.message, 'warning');
    }).finally(function () {
      busy = false;
      setTimeout(function () { if (card.classList.contains('gone')) { card.remove(); } render(); }, 280);
    });
  }

  function showMatch(name, chatUrl) {
    var m = document.getElementById('matchModal');
    if (!m || !window.bootstrap) { sff.toast("It's a match with " + name + '!', 'success'); return; }
    m.querySelector('[data-match-name]').textContent = name;
    m.querySelector('[data-match-chat]').href = chatUrl;
    bootstrap.Modal.getOrCreateInstance(m).show();
  }

  function attachDrag(card) {
    var startX = 0, startY = 0, dx = 0, dragging = false;
    card.addEventListener('pointerdown', function (e) {
      if (card !== topCard() || busy) { return; }
      dragging = true; startX = e.clientX; startY = e.clientY; dx = 0;
      card.setPointerCapture(e.pointerId); card.classList.add('dragging');
    });
    card.addEventListener('pointermove', function (e) {
      if (!dragging) { return; }
      dx = e.clientX - startX;
      var dy = (e.clientY - startY) * 0.2;
      card.style.transform = 'translate(' + dx + 'px,' + dy + 'px) rotate(' + dx / 18 + 'deg)';
      card.classList.toggle('hint-like', dx > 40); card.classList.toggle('hint-pass', dx < -40);
    });
    var end = function () {
      if (!dragging) { return; }
      dragging = false; card.classList.remove('dragging', 'hint-like', 'hint-pass');
      if (dx > 110) { decide('like'); } else if (dx < -110) { decide('pass'); } else { card.style.transform = ''; }
    };
    card.addEventListener('pointerup', end);
    card.addEventListener('pointercancel', end);
  }

  document.querySelectorAll('.deck-actions [data-action]').forEach(function (b) {
    b.addEventListener('click', function () { decide(b.dataset.action); });
  });
  document.addEventListener('keydown', function (e) {
    if (e.target.closest('input, textarea, select') || document.querySelector('.modal.show')) { return; }
    if (e.key === 'ArrowRight') { e.preventDefault(); decide('like'); }
    if (e.key === 'ArrowLeft') { e.preventDefault(); decide('pass'); }
    if (e.key === 'ArrowUp') { e.preventDefault(); decide('info'); }
  });
  load();
})();
