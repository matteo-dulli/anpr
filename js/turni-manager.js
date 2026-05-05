/**
 * 🔁 TURNI MANAGER - Sistema gestione turni operatori ANPR
  */

(function () {
  'use strict';
window.TURNI_MANAGER_NO_UI = window.TURNI_MANAGER_NO_UI === true;
  // ============================================================
  // STATO GLOBALE
  // ============================================================
  window.turnoAttivo  = false;
  window.turnoId      = null;   // id riga in turni_sessioni
  window.turnoNumero  = null;   // numero turno (es: 001)
  window.turnoOperatoreAttivo = null; // codice operatore

  /** Ritorna true se il turno è attualmente aperto */
  window.isTurnoAttivo = function () {
    return window.turnoAttivo === true;
  };

  // Compatibilità con il vecchio checkTurnoBeforeAction di turni.js
  window.checkTurnoBeforeAction = async function (azione) {
    if (!window.isTurnoAttivo()) {
      if (typeof showToast === 'function') {
        showToast('❌ Turno chiuso! Aprire turno per continuare.', 'error', 3000);
      }
      return false;
    }
    return true;
  };

  // ============================================================
  // INIT AL CARICAMENTO
  // ============================================================
  document.addEventListener('DOMContentLoaded', async function () {
  if (!window.TURNI_MANAGER_NO_UI) {
    injectUI();
  }
  await checkTurnoAtStartup();
});

  // ============================================================
  // INJECT UI IN HEADER
  // ============================================================
  function injectUI() {
    const headerStatus = document.querySelector('.header-status');
    if (!headerStatus) return;

    // -- Gruppo "Apri Turno" --
    const turnoGroup = document.createElement('div');
    turnoGroup.id = 'turnoManagerGroup';
    turnoGroup.className = 'turno-group';
    turnoGroup.innerHTML = `
      <div class="turno-dropdown-container" id="apriTurnoContainer">
        <button id="btnApriTurno" class="btn-turno btn-apri-turno">👤 Apri Turno ▼</button>
        <div id="operatoriDropdown" class="turno-dropdown" style="display:none;">
          <div class="turno-dropdown-title">Seleziona Operatore</div>
          <div id="operatoriList" class="turno-operatori-list">
            <div class="turno-loading">⏳ Caricamento...</div>
          </div>
        </div>
      </div>

      <div id="operatoreBadgeMgr" class="operatore-badge" style="display:none;">
        <span id="badgeOperatoreText">✅ Operatore: -</span>
        <span id="badgeOraInizio" class="badge-ora"></span>
        <button id="btnChiudiTurno" class="btn-turno btn-chiudi-turno">🔒 Chiudi Turno</button>
      </div>
    `;

    // -- Gruppo "Stampa Turni" --
    const stampaGroup = document.createElement('div');
    stampaGroup.id = 'stampaTurniGroup';
    stampaGroup.className = 'turno-group';
    stampaGroup.innerHTML = `
      <div class="turno-dropdown-container" id="stampaTurniContainer">
        <button id="btnStampaTurni" class="btn-turno btn-stampa-turni">🖨️ Stampa Turni ▼</button>
        <div id="turniDropdown" class="turno-dropdown" style="display:none;">
          <div class="turno-dropdown-title">Turni recenti (30 giorni)</div>
          <div id="turniList" class="turno-turni-list">
            <div class="turno-loading">⏳ Caricamento...</div>
          </div>
          <div id="turnoStampaAzioni" class="turno-stampa-azioni" style="display:none;">
            <button id="btnStampaPDF"  class="btn-stampa-action btn-print">🖨️ STAMPA</button>
            <button id="btnScaricaTxt" class="btn-stampa-action btn-download">⬇️ SCARICA</button>
          </div>
        </div>
      </div>
    `;

    // Inserisci PRIMA degli altri elementi header-status
    headerStatus.insertBefore(stampaGroup, headerStatus.firstChild);
    headerStatus.insertBefore(turnoGroup, headerStatus.firstChild);

    // === BIND EVENTS ===
    document.getElementById('btnApriTurno')?.addEventListener('click', function (e) {
      e.stopPropagation();
      toggleOperatoriDropdown();
    });

   document.getElementById('btnChiudiTurno')?.addEventListener('click', function () {
  showCloseTurnoDialog();
});

    document.getElementById('btnStampaTurni')?.addEventListener('click', function (e) {
      e.stopPropagation();
      toggleStampaDropdown();
    });

    // Chiudi dropdown cliccando fuori
    document.addEventListener('click', function (e) {
      if (!e.target.closest('#turnoManagerGroup')) {
        const dd = document.getElementById('operatoriDropdown');
        if (dd) dd.style.display = 'none';
      }
      if (!e.target.closest('#stampaTurniGroup')) {
        const dd = document.getElementById('turniDropdown');
        if (dd) dd.style.display = 'none';
      }
    });
  }

  // ============================================================
  // CHECK TURNO ALL'AVVIO (legge DB)
  // ============================================================
  async function checkTurnoAtStartup() {
    try {
      const r = await fetch(`${window.API_BASE || '/anpr/api'}/turni_operatore_stato.php?t=${Date.now()}`, {
        cache: 'no-store'
      });
      const j = await r.json();

      if (j.success && j.data && j.data.stato === 'online') {
        window.turnoAttivo          = true;
        window.turnoId              = j.data.id_sessione || null;
        window.turnoNumero          = j.data.numero_turno || null;
        window.turnoOperatoreAttivo = j.data.operatore_cod;
        updateBadgeUI(j.data.operatore_cod, j.data.inizio_turno);
      } else {
        window.turnoAttivo = false;
        updateBadgeUI(null);
      }
    } catch (e) {
      console.warn('TurniManager: errore check startup', e);
      window.turnoAttivo = false;
    }
  }

  // ============================================================
  // TOGGLE DROPDOWN OPERATORI
  // ============================================================
  function toggleOperatoriDropdown() {
    const dd = document.getElementById('operatoriDropdown');
    if (!dd) return;

    const isVisible = dd.style.display !== 'none';
    // Chiudi stampa se aperto
    const ddStampa = document.getElementById('turniDropdown');
    if (ddStampa) ddStampa.style.display = 'none';

    if (isVisible) {
      dd.style.display = 'none';
    } else {
      dd.style.display = 'block';
      loadOperatoriDropdown();
    }
  }

  // ============================================================
  // CARICA LISTA OPERATORI
  // ============================================================
  async function loadOperatoriDropdown() {
    const list = document.getElementById('operatoriList');
    if (!list) return;
    list.innerHTML = '<div class="turno-loading">⏳ Caricamento...</div>';

    try {
      const r = await fetch(`${window.API_BASE || '/anpr/api'}/turni_operatori_lista.php?t=${Date.now()}`, {
        cache: 'no-store'
      });
      const j = await r.json();

      if (!j.success || !Array.isArray(j.data) || j.data.length === 0) {
        list.innerHTML = '<div class="turno-loading">⚠️ Nessun operatore trovato</div>';
        return;
      }

      list.innerHTML = '';
      j.data.forEach(function (op) {
        const item = document.createElement('div');
        item.className = 'turno-operatore-item';
        item.textContent = `👤 ${op.cod} - ${op.nome}`;
        item.dataset.cod = op.cod;
        item.addEventListener('click', function () {
          handleApriTurno(op.cod, op.nome);
        });
        list.appendChild(item);
      });
    } catch (e) {
      list.innerHTML = '<div class="turno-loading">❌ Errore caricamento</div>';
    }
  }

  // ============================================================
  // APRI TURNO
  // ============================================================
  async function handleApriTurno(operatoreCod, operatoreNome) {
    // Chiudi dropdown
    const dd = document.getElementById('operatoriDropdown');
    if (dd) dd.style.display = 'none';

    try {
      if (typeof showToast === 'function') {
        showToast('⏳ Apertura turno...', 'info', 2000);
      }

      const r = await fetch(`${window.API_BASE || '/anpr/api'}/turni_operatore_login.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ operatore_cod: operatoreCod })
      });
      const j = await r.json();

      if (!j.success) {
        if (typeof showToast === 'function') {
          showToast('❌ ' + (j.message || 'Errore apertura turno'), 'error', 4000);
        }
        return;
      }

      window.turnoAttivo          = true;
      window.turnoId              = j.data?.id_sessione || null;
      window.turnoNumero          = j.data?.numero_turno || null;
      window.turnoOperatoreAttivo = operatoreCod;

      const inizio = j.data?.inizio || new Date().toISOString();
      updateBadgeUI(operatoreCod, inizio);

      if (typeof showToast === 'function') {
        showToast(`✅ Turno aperto — Operatore: ${operatoreCod} (${operatoreNome})`, 'success', 3500);
      }
    } catch (e) {
      if (typeof showToast === 'function') {
        showToast('❌ Errore connessione: ' + (e?.message || e), 'error', 4000);
      }
    }
  }

  // ============================================================
  // CHIUDI TURNO
  // ============================================================
  async function handleChiudiTurno() {
    if (!window.turnoOperatoreAttivo) {
      if (typeof showToast === 'function') {
        showToast('⚠️ Nessun turno aperto', 'error');
      }
      return;
    }

    if (!confirm(`Chiudere il turno dell'operatore ${window.turnoOperatoreAttivo}?`)) {
      return;
    }

    try {
      if (typeof showToast === 'function') {
        showToast('⏳ Chiusura turno...', 'info', 2000);
      }

      const r = await fetch(`${window.API_BASE || '/anpr/api'}/turni_operatore_logout.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          operatore_cod: window.turnoOperatoreAttivo,
          id_sessione:   window.turnoId
        })
      });
      const j = await r.json();

      if (!j.success) {
        if (typeof showToast === 'function') {
          showToast('❌ ' + (j.message || 'Errore chiusura turno'), 'error', 4000);
        }
        return;
      }

      window.turnoAttivo          = false;
      window.turnoId              = null;
      window.turnoNumero          = null;
      window.turnoOperatoreAttivo = null;

      updateBadgeUI(null);

      const msg = j.data?.file_path
        ? `✅ Turno chiuso — File: ${j.data.file_path}`
        : '✅ Turno chiuso';
      if (typeof showToast === 'function') {
        showToast(msg, 'success', 4000);
      }
    } catch (e) {
      if (typeof showToast === 'function') {
        showToast('❌ Errore connessione: ' + (e?.message || e), 'error', 4000);
      }
    }
  }
function showCloseTurnoDialog() {
  // evita doppioni
  if (document.getElementById('turnoCloseModal')) return;

  const wrap = document.createElement('div');
  wrap.id = 'turnoCloseModal';
  wrap.style.cssText = `
    position:fixed; inset:0; background:rgba(0,0,0,.45);
    display:flex; align-items:center; justify-content:center;
    z-index:99999;
  `;

  wrap.innerHTML = `
    <div style="background:#fff; width:min(520px, 92vw); border-radius:10px; padding:16px 16px 12px 16px; box-shadow:0 10px 30px rgba(0,0,0,.25);">
      <div style="font-weight:700; font-size:16px; margin-bottom:6px;">
        Chiudere il turno?
      </div>
      <div style="font-size:13px; color:#374151; margin-bottom:12px;">
        Il turno verrà chiuso. Confermi?
      </div>

      <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
        <button id="turnoCloseNo" style="padding:8px 12px; border-radius:8px; border:1px solid #d1d5db; background:#fff;">No</button>
        <button id="turnoCloseReprint" style="padding:8px 12px; border-radius:8px; border:1px solid #d1d5db; background:#f3f4f6;">Ristampa</button>
        <button id="turnoCloseYes" style="padding:8px 12px; border-radius:8px; border:1px solid #16a34a; background:#22c55e; color:#fff; font-weight:700;">Sì, chiudi</button>
      </div>
    </div>
  `;

  const close = () => wrap.remove();

  wrap.addEventListener('click', (e) => { if (e.target === wrap) close(); });

  wrap.querySelector('#turnoCloseNo')?.addEventListener('click', () => close());

  wrap.querySelector('#turnoCloseYes')?.addEventListener('click', async () => {
    close();
    await handleChiudiTurnoConfirmed(); // nuova funzione sotto
  });

  wrap.querySelector('#turnoCloseReprint')?.addEventListener('click', async () => {
    close();
    // apre direttamente il dropdown turni (riuso UI già in turni-manager)
    openStampaTurniDialog();
  });

  document.body.appendChild(wrap);
}
  // ============================================================
  // AGGIORNA UI BADGE
  // ============================================================
  function updateBadgeUI(operatoreCod, inizioTurno) {
    const btnApri     = document.getElementById('btnApriTurno');
    const badge       = document.getElementById('operatoreBadgeMgr');
    const badgeText   = document.getElementById('badgeOperatoreText');
    const badgeOra    = document.getElementById('badgeOraInizio');

    if (!btnApri || !badge) return;

    if (operatoreCod) {
      // Turno APERTO
      btnApri.style.display = 'none';
      badge.style.display   = 'flex';

      if (badgeText) {
        badgeText.textContent = `✅ Operatore: ${operatoreCod}`;
      }
      if (badgeOra && inizioTurno) {
        try {
          const d = new Date(inizioTurno);
          const hh = String(d.getHours()).padStart(2, '0');
          const mm = String(d.getMinutes()).padStart(2, '0');
          badgeOra.textContent = `dalle ${hh}:${mm}`;
        } catch (_) {
          badgeOra.textContent = '';
        }
      }
    } else {
      // Turno CHIUSO
      btnApri.style.display = '';
      badge.style.display   = 'none';
      if (badgeOra) badgeOra.textContent = '';
    }
  }

  // ============================================================
  // TOGGLE DROPDOWN STAMPA TURNI
  // ============================================================
  function toggleStampaDropdown() {
    const dd = document.getElementById('turniDropdown');
    if (!dd) return;

    const isVisible = dd.style.display !== 'none';
    // Chiudi dropdown operatori se aperto
    const ddOp = document.getElementById('operatoriDropdown');
    if (ddOp) ddOp.style.display = 'none';

    if (isVisible) {
      dd.style.display = 'none';
    } else {
      dd.style.display = 'block';
      loadTurniDropdown();
    }
  }

  // ============================================================
  // CARICA LISTA TURNI (per stampa)
  // ============================================================
  let selectedTurnoId = null;

  async function loadTurniDropdown() {
    const list    = document.getElementById('turniList');
    const azioni  = document.getElementById('turnoStampaAzioni');
    if (!list) return;

    list.innerHTML = '<div class="turno-loading">⏳ Caricamento...</div>';
    if (azioni) azioni.style.display = 'none';
    selectedTurnoId = null;

    try {
      const r = await fetch(`${window.API_BASE || '/anpr/api'}/turni_list.php?t=${Date.now()}`, {
        cache: 'no-store'
      });
      const j = await r.json();

      if (!j.success || !Array.isArray(j.data) || j.data.length === 0) {
        list.innerHTML = '<div class="turno-loading">⚠️ Nessun turno trovato</div>';
        return;
      }

      list.innerHTML = '';
      j.data.forEach(function (turno, idx) {
        const item = document.createElement('div');
        item.className = 'turno-turno-item' + (idx === 0 ? ' selected' : '');
        item.dataset.id       = turno.id;
        item.dataset.fileName = turno.file_name || '';

        const statoIcon = turno.stato === 'online' ? '🟢' : '⚫';
        const dataFmt   = turno.data_inizio ? formatDateIt(turno.data_inizio) : '-';
        item.textContent = `${statoIcon} Turno ${String(turno.numero_turno).padStart(3, '0')} — ${turno.operatore_cod} — ${dataFmt}`;

        item.addEventListener('click', function () {
          document.querySelectorAll('.turno-turno-item').forEach(function (el) {
            el.classList.remove('selected');
          });
          item.classList.add('selected');
          selectedTurnoId = turno.id;
          if (azioni) azioni.style.display = 'flex';
        });

        list.appendChild(item);
      });

      // Seleziona il più recente di default
      if (j.data.length > 0) {
        selectedTurnoId = j.data[0].id;
        if (azioni) azioni.style.display = 'flex';
      }

      bindStampaAzioni();
    } catch (e) {
      list.innerHTML = '<div class="turno-loading">❌ Errore caricamento</div>';
    }
  }

  function bindStampaAzioni() {
  const btnPrint   = document.getElementById('btnStampaPDF');
  const btnScarica = document.getElementById('btnScaricaTxt');

  // ✅ non serve in kiosk
  if (btnScarica) {
    btnScarica.style.display = 'none';
  }

  // ✅ STAMPA DIRETTA TERMICA (KIOSK): niente window.open, niente nuove pagine
  if (btnPrint) {
    btnPrint.onclick = async function () {
      if (!selectedTurnoId) return;

      try {
        if (typeof showToast === 'function') {
          showToast('⏳ Stampa turno...', 'info', 2000);
        }

        const url = `${window.API_BASE || '/anpr/api'}/turni_stampa.php?id=${selectedTurnoId}&action=thermal&t=${Date.now()}`;
        const r = await fetch(url, { cache: 'no-store' });

        // robusto: se non torna JSON valido, mostra errore e logga
        const raw = await r.text();
        let j = null;
        try { j = JSON.parse(raw); } catch (_) {}

        if (!j || j.success !== true) {
          console.error('turni_stampa thermal response:', raw);
          if (typeof showToast === 'function') {
            showToast('❌ ' + ((j && j.message) ? j.message : 'Errore stampa turno'), 'error', 4500);
          }
          return;
        }

        if (typeof showToast === 'function') {
          showToast('✅ Turno stampato', 'success', 2500);
        }

        // ✅ chiudi dropdown dopo stampa
        const dd = document.getElementById('turniDropdown');
        if (dd) dd.style.display = 'none';

      } catch (e) {
        console.error('Stampa turno error:', e);
        if (typeof showToast === 'function') {
          showToast('❌ Errore stampa: ' + (e?.message || e), 'error', 4500);
        }
      }
    };
  }
}

  // ============================================================
  // UTILITY: formatta data ISO → GG/MM/AAAA
  // ============================================================
  function formatDateIt(isoStr) {
    try {
      const d = new Date(isoStr);
      const gg = String(d.getDate()).padStart(2, '0');
      const mm = String(d.getMonth() + 1).padStart(2, '0');
      const aa = d.getFullYear();
      return `${gg}/${mm}/${aa}`;
    } catch (_) {
      return isoStr;
    }
  }

})();
