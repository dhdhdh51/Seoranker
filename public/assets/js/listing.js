/* Listing detail: photo gallery (thumbs + swipe + arrow keys) and enquiry form toggles. */
(function () {
  'use strict';
  var g = document.querySelector('[data-gallery]');
  if (g) {
    var main = g.querySelector('[data-gallery-main]');
    var count = g.querySelector('[data-gallery-count]');
    var thumbs = Array.prototype.slice.call(g.querySelectorAll('.gallery-thumbs button'));
    var idx = 0;
    var show = function (i) {
      if (!thumbs.length) { return; }
      idx = (i + thumbs.length) % thumbs.length;
      var t = thumbs[idx];
      main.src = t.dataset.full;
      main.alt = 'Photo ' + (idx + 1) + ' of ' + thumbs.length;
      if (count) { count.textContent = (idx + 1) + ' / ' + thumbs.length; }
      thumbs.forEach(function (b, j) { b.setAttribute('aria-current', j === idx ? 'true' : 'false'); });
      t.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    };
    thumbs.forEach(function (b, j) { b.addEventListener('click', function () { show(j); }); });
    var sx = null;
    main.addEventListener('touchstart', function (e) { sx = e.touches[0].clientX; }, { passive: true });
    main.addEventListener('touchend', function (e) {
      if (sx === null) { return; }
      var dx = e.changedTouches[0].clientX - sx; sx = null;
      if (Math.abs(dx) > 40) { show(idx + (dx < 0 ? 1 : -1)); }
    });
    g.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') { show(idx + 1); }
      if (e.key === 'ArrowLeft') { show(idx - 1); }
    });
  }
  var viewing = document.querySelector('[data-viewing-field]');
  if (viewing) {
    document.querySelectorAll('input[name="kind"]').forEach(function (r) {
      r.addEventListener('change', function () { viewing.hidden = r.value !== 'viewing' || !r.checked; });
    });
  }
})();
