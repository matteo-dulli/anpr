(function setupIdleFocusOnSearch() {
  'use strict';

  var INPUT_ID = 'searchTicketInfo';

  // default 30s (come in costanti.txt)
  var IDLE_MS_DEFAULT = 30 * 1000;

  var timer = null;

  function getIdleMs() {
    // priorità: window.TEMPOCURSORE_SEC (se caricato da get_costanti.php) -> default
    var sec = (window.TEMPOCURSORE_SEC != null) ? Number(window.TEMPOCURSORE_SEC) : NaN;
    if (!isFinite(sec) || sec <= 0) return IDLE_MS_DEFAULT;
    return Math.max(1, sec) * 1000;
  }

  function focusSearch() {
    var el = document.getElementById(INPUT_ID);
    if (!el) return;

    // se l'utente sta già scrivendo in un input/textarea/select, non rubare focus
    var ae = document.activeElement;
    if (ae && ae !== document.body) {
      var tag = (ae.tagName || '').toLowerCase();
      if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
      if (ae.isContentEditable) return;
    }

    el.focus({ preventScroll: true });
    try { el.select(); } catch (_) {}
  }

  function reset() {
    if (timer) clearTimeout(timer);
    timer = setTimeout(focusSearch, getIdleMs());
  }

  function bind() {
    // inattività utente (mouse+tastiera) + touch/scroll
    ['mousemove', 'mousedown', 'keydown', 'touchstart', 'wheel', 'scroll'].forEach(function (evt) {
      window.addEventListener(evt, reset, { passive: true });
    });

    // quando la tab torna visibile, riparti
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) reset();
    });

    reset();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();