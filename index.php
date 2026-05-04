<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Lettura e Gestione Targhe</title>
  <link rel="stylesheet" href="css/style.css">

  <!-- ✅ BOOT CONFIG (sempre valido, anche senza PHP) -->
  <script>
    window.API_BASE    = window.API_BASE    || "/anpr/api";
    window.INVOICE_URL = window.INVOICE_URL || "/anpr/invoice";
    window.PRINT_URL   = window.PRINT_URL   || "/anpr/print";

    // thumbs default (no PHP)
    window.ANPR_THUMB_W = window.ANPR_THUMB_W || 320;
    window.ANPR_THUMB_Q = window.ANPR_THUMB_Q || 70;
  </script>

  <!-- ✅ PATCH: TEMPOCURSORE da costanti.txt + autofocus ricerca dopo inattività -->
  <script>
  (function () {
    async function loadTempoCursoreSec() {
      // fallback se non disponibile
      var fallbackSec = 30;

      try {
        // Endpoint atteso: deve restituire JSON con TEMPOCURSORE_SEC
        // Esempio payload accettato:
        // { success:true, data:{ TEMPOCURSORE_SEC: 30, ... } }
        // oppure { TEMPOCURSORE_SEC: 30, ... }
        var url = (window.API_BASE || '/anpr/api') + '/get_costanti.php?t=' + Date.now();
        var r = await fetch(url, { cache: 'no-store' });
        if (!r.ok) return fallbackSec;

        var j = await r.json();

        var data = (j && typeof j === 'object')
          ? (j.data && typeof j.data === 'object' ? j.data : j)
          : null;

        var sec = data && data.TEMPOCURSORE_SEC != null ? Number(data.TEMPOCURSORE_SEC) : NaN;
        if (!Number.isFinite(sec) || sec <= 0) return fallbackSec;

        // esponi anche globalmente per debug/uso in app.js
        window.TEMPOCURSORE_SEC = sec;
        return sec;
      } catch (e) {
        return fallbackSec;
      }
    }

    function setupIdleFocus(sec) {
      var INPUT_ID = 'searchTicketInfo';
      var IDLE_MS = Math.max(1, Number(sec) || 30) * 1000;

      var timer = null;

      function focusSearch() {
        var el = document.getElementById(INPUT_ID);
        if (!el) return;
        el.focus({ preventScroll: true });
        try { el.select(); } catch (_) {}
      }

      function reset() {
        if (timer) clearTimeout(timer);
        timer = setTimeout(focusSearch, IDLE_MS);
      }

      // inattività utente (mouse+tastiera) + anche touch/scroll
      ['mousemove', 'mousedown', 'keydown', 'touchstart', 'wheel', 'scroll'].forEach(function (evt) {
        window.addEventListener(evt, reset, { passive: true });
      });

      reset();
    }

    document.addEventListener('DOMContentLoaded', async function () {
      var sec = await loadTempoCursoreSec();
      setupIdleFocus(sec);
    });
  })();
  </script>
  <!-- ✅ FINE PATCH -->
</head>

<body>
<div class="container">
  <!-- HEADER -->
  <div class="header">
    <h1>🚗 Gestione SMART Autorimessa - con ANPR</h1>

    <div class="header-center">
      <span id="headerDateTime" class="header-datetime"></span>
    </div>

    <div class="header-status">
      <!-- PRESENZE TOPBAR (NUOVO) -->
      <div id="presenzeTopbar" style="display:flex;align-items:center;gap:10px;margin-right:14px;">
        <div style="font-weight:700;font-size:16px;white-space:nowrap;">
          Presenze: <span id="presentiCounter">…</span>
        </div>

        <select id="presentiDays"
                style="padding:6px 10px;border-radius:6px;border:none;font-size:14px;min-width:190px;">
        </select>
      </div>

      <!-- DB STATUS (FIX: overlay per evitare vibrazioni/spostamenti) -->
      <div id="dbStatusWrap" class="db-status db-wait" title="Stato connessione">
        <span class="db-layer db-wait-layer">
          <span class="db-dot dot-yellow"></span>
          <span class="db-text">Lettura</span>
        </span>

        <span class="db-layer db-ok-layer">
          <span class="db-dot dot-green"></span>
          <span class="db-text">Connesso</span>
        </span>

        <span class="db-layer db-bad-layer">
          <span class="db-dot dot-red"></span>
          <span class="db-text">No Conn</span>
        </span>
      </div>
      <!-- FINE DB STATUS -->

      <span id="connectionTime"></span>

      <select id="refreshInterval" style="padding:4px;border-radius:4px;border:none;">
        <option value="1">Refresh 1s</option>
        <option value="2" selected>Refresh 2s</option>
        <option value="5">Refresh 5s</option>
        <option value="10">Refresh 10s</option>
      </select>
    </div>
  </div>

  <!-- BARRA RICERCA / FILTRI -->
  <div class="search-section">
    <div class="search-row-main">

      <!-- 1ª colonna: Giorni -->
      <div class="search-item">
        <label>Giorni:</label>
        <div class="control-wrap">
          <input type="number" id="searchDays" value="2" min="1" max="30">
        </div>
      </div>

      <!-- 2ª colonna: Nuova targa inline -->
      <div class="search-item search-item-new-plate">
        <label>Inserimento nuova targa:</label>
        <div style="display:flex; gap:4px;">
          <input type="text"
                 id="newPlateInline"
                 maxlength="10"
                 placeholder="Es: AB123CD"
                 style="flex:1; padding:6px 8px; font-size:12px;">
          <button class="btn btn-primary btn-new-plate" id="createNewPlateInline">
            ✏️ Inserisci
          </button>
        </div>
      </div>

      <!-- Ticket buttons -->
      <div class="search-item">
        <label>&nbsp;</label>
        <div class="ticket-buttons-container">
          <button id="emitTicketBtn" class="btn-emit">🎫 Emetti Ticket</button>
          <button id="reprintTicketBtn" class="btn-reprint">🖨️ Ristampa Ticket</button>
        </div>
      </div>

      <!-- Input unico: Ticket/Ricevuta/Targa/Passaggio -->
      <div class="search-item" id="searchTicketWrapper" style="position:relative;">
        <label>Ricerca Ticket - Targhe - Ricevute:</label>
        <input type="text" id="searchTicketInfo" placeholder="Ticket / Ricevuta / Targa / ID passaggio...">
      </div>

      <div class="search-item">
        <label>&nbsp;</label>
        <div class="search-buttons-wrapper">
          <button class="btn btn-small" id="searchBtn" onclick="handleTicketSearch()">🔍 Cerca</button>
          <button class="btn btn-small" id="resetBtn" onclick="location.reload(true)">↺ Reset</button>
        </div>
      </div>

    </div>
  </div>

  <!-- LAYOUT PRINCIPALE -->
  <div class="main-layout">
    <div class="plates-section">
      <button class="plates-section-header-btn" onclick="closeDetails()">✕ Chiudi Scheda Targhe</button>
      <div class="plates-list" id="platesList">
        <p class="empty-state">🔍 Caricamento...</p>
      </div>
      <div class="plates-stats">
        <span id="platesCount">0 targhe</span>
      </div>
    </div>

    <div class="details-section">
      <div class="details-panel" id="detailsPanel">
        <!-- ✅ ROOT DATI (per details.js / calcoli) -->
        <div id="details-root" data-plate-id="" data-passage-id="" data-id="" style="display:none"></div>

        <p class="empty-state">Seleziona una targa</p>
      </div>
    </div>

    <div class="image-section">
      <div class="image-box" id="imageBox"></div>
    </div>
  </div>
</div>

<!-- MODAL IMMAGINE -->
<div id="imageModal" class="modal">
  <div class="modal-content">
    <span class="modal-close">&times;</span>
    <img id="modalImage" class="modal-image" src="" alt="Immagine targa">
    <div id="modalCaption" class="modal-caption"></div>
  </div>
</div>

<!-- TOAST -->
<div id="toastContainer" class="toast-container"></div>

<!-- ✅ COMPAT: molti file usano API_BASE senza window. -->
<script>var API_BASE = window.API_BASE;</script>

<script src="/anpr/js/utils.js"></script>
<script src="/anpr/js/ui.js"></script>

<!-- ... TUTTO IL RESTO INVARIATO ... -->

<script src="/anpr/js/details.js"></script>
<script src="/anpr/js/plate-management.js"></script>
<script src="/anpr/js/plates.js"></script>
<script src="/anpr/js/app.js"></script>

<!-- INIT PRESENZE (NUOVO) -->
<script>
  (function () {
    async function fetchPresenti(days) {
      if (!window.API_BASE) throw new Error('API_BASE non definito');
      const r = await fetch(`${window.API_BASE}/get_presenti.php?days=${days}&t=${Date.now()}`, { cache: 'no-store' });
      return r.json();
    }

    function getSelectedDays(sel) {
      const v = parseInt((sel && sel.value != null ? sel.value : '0'), 10);
      if (Number.isNaN(v)) return 0;
      return v < 0 ? 0 : v;
    }

    async function initPresenzeTopbar() {
      if (!window.API_BASE) {
        console.error('API_BASE non definito: presenzeTopbar disabilitato');
        return;
      }

      const sel = document.getElementById('presentiDays');
      const counter = document.getElementById('presentiCounter');
      if (!sel || !counter) return;

      const j = await fetchPresenti(0);
      if (!j.success) return;

      sel.innerHTML = '';
      (j.data.options || []).forEach(opt => {
        const o = document.createElement('option');
        o.value = String(opt.value);
        o.textContent = opt.label;
        sel.appendChild(o);
      });

      sel.value = '0';
      counter.textContent = j.data.presenti ?? '-';

      sel.addEventListener('change', async () => {
        const days = getSelectedDays(sel);
        const jj = await fetchPresenti(days);
        if (jj.success) counter.textContent = jj.data.presenti ?? '-';
      });

      setInterval(async () => {
        const days = getSelectedDays(sel);
        const jj = await fetchPresenti(days);
        if (jj.success) counter.textContent = jj.data.presenti ?? '-';
      }, 15000);
    }

    document.addEventListener('DOMContentLoaded', initPresenzeTopbar);
  })();
</script>

</body>
</html>