/**
 * 📋 Sistema Turni Operatori ANPR
 * ✅ Gestisce login/logout operatori e blocchi azioni
 * ✅ Disabilita pulsanti quando turno è chiuso
 * ✅ Contatore tempo turno con downtime recovery
 */

window.TURNI = window.TURNI || {};

// ============ STATO GLOBALE ============
let currentOperatore = null;
let currentOperatoreStato = 'offline';
let currentTurnoSessionId = null;
let currentTurnoNumero = null;
window.turnoAttivo = false;
let turnoTimerInterval = null;

// ============ INIT AL CARICAMENTO ============
document.addEventListener('DOMContentLoaded', async () => {
    console.log('🚀 turni.js DOMContentLoaded');
    await TURNI.initUI();
    await TURNI.checkTurnoAtStartup();
    await TURNI.updateButtonStates();
});

// ============ INIT UI ============
TURNI.initUI = async function() {
    console.log('🎨 TURNI.initUI');
    const headerStatus = document.querySelector('.header-status');
    if (!headerStatus) {
        console.warn('⚠️ .header-status non trovato');
        return;
    }

    const badge = document.createElement('div');
    badge.id = 'operatoreBadge';
    badge.style.cssText = `
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 10px 16px;
        background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
        border-radius: 8px;
        font-size: 13px;
        margin-right: 14px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    `;
    badge.innerHTML = `
        <div style="font-weight: 700; white-space: nowrap; color: #666;">
            Operatore: <span id="operatoreNome" style="color: #dc2626; font-size: 14px;">-</span>
        </div>
        <div style="display: flex; gap: 10px; align-items: center;">
            <span id="turnoTimer" style="font-weight: 700; color: #2563eb; font-size: 16px; min-width: 80px; font-family: monospace;">⏱️ 00:00:00</span>
            <button id="loginBtn" class="btn-small" style="background: #22c55e; color: white; padding: 10px 18px; cursor: pointer; border: none; border-radius: 6px; font-weight: 700; font-size: 15px; min-width: 130px; box-shadow: 0 2px 6px rgba(34,197,94,0.3);">🔓 Apri Turno</button>
            <button id="logoutBtn" class="btn-small" style="background: #dc2626; color: white; padding: 10px 18px; cursor: pointer; display: none; border: none; border-radius: 6px; font-weight: 700; font-size: 15px; min-width: 130px; box-shadow: 0 2px 6px rgba(220,38,38,0.3);">🔒 Chiudi Turno</button>
            <span id="statusBadge" style="font-weight: 700; white-space: nowrap; color: #999; padding: 4px 8px; background: #f0f0f0; border-radius: 4px;">⚪ offline</span>
        </div>
    `;

    headerStatus.insertBefore(badge, headerStatus.firstChild);

    // Event listeners
    document.getElementById('loginBtn')?.addEventListener('click', TURNI.handleLoginClick);
   document.getElementById('logoutBtn')?.addEventListener('click', TURNI.handleLogoutClick);
    
    // Crea modal per selezione operatore
    TURNI.createOperatoreModal();
    
    console.log('✅ Badge creato');
};
TURNI.handleLogoutClick = function () {
  // evita doppioni
  if (document.getElementById('turnoCloseChoiceModal')) return;

  const wrap = document.createElement('div');
  wrap.id = 'turnoCloseChoiceModal';
  wrap.style.cssText = `
    position: fixed; inset: 0; background: rgba(0,0,0,.45);
    display: flex; align-items: center; justify-content: center;
    z-index: 99999;
  `;

  wrap.innerHTML = `
    <div style="background:#fff; width:min(520px, 92vw); border-radius:10px; padding:16px; box-shadow:0 10px 30px rgba(0,0,0,.25);">
      <div style="font-weight:800; font-size:16px; margin-bottom:6px;">Chiudere il turno?</div>
      <div style="font-size:13px; color:#374151; margin-bottom:12px;">
        Il turno verrà chiuso. Confermi?
      </div>

      <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
        <button id="turnoCloseNo" style="padding:8px 12px; border-radius:8px; border:1px solid #d1d5db; background:#fff;">No</button>
        <button id="turnoCloseReprint" style="padding:8px 12px; border-radius:8px; border:1px solid #d1d5db; background:#f3f4f6;">Ristampa</button>
        <button id="turnoCloseYes" style="padding:8px 12px; border-radius:8px; border:0; background:#dc2626; color:#fff; font-weight:800;">Sì, chiudi</button>
      </div>
    </div>
  `;

  const close = () => wrap.remove();
  wrap.addEventListener('click', (e) => { if (e.target === wrap) close(); });

  wrap.querySelector('#turnoCloseNo')?.addEventListener('click', close);

  wrap.querySelector('#turnoCloseYes')?.addEventListener('click', async () => {
    close();
    await TURNI.handleLogout(); // chiusura reale
  });

  wrap.querySelector('#turnoCloseReprint')?.addEventListener('click', async () => {
    close();
    // apre il pannello ristampa turni
    if (typeof TURNI.openTurniReprintDialog === 'function') {
      TURNI.openTurniReprintDialog();
    } else {
      showToast('❌ Funzione ristampa turni non disponibile', 'error', 3500);
    }
  });

  document.body.appendChild(wrap);
};
TURNI.openTurniReprintDialog = function () {
  if (document.getElementById('turniReprintModal')) return;

  const wrap = document.createElement('div');
  wrap.id = 'turniReprintModal';
  wrap.style.cssText = `
    position: fixed; inset: 0; background: rgba(0,0,0,.45);
    display: flex; align-items: center; justify-content: center;
    z-index: 99999;
  `;

  wrap.innerHTML = `
    <div style="background:#fff; width:min(760px, 96vw); border-radius:10px; padding:14px; box-shadow:0 10px 30px rgba(0,0,0,.25);">
      <div style="display:flex; align-items:center; justify-content:space-between; gap:10px;">
        <div style="font-weight:800;">Ristampa Turni</div>
        <button id="turniReprintClose" style="border:1px solid #d1d5db; background:#fff; border-radius:8px; padding:6px 10px;">Chiudi</button>
      </div>

      <div style="margin-top:10px; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
        <div id="turniReprintList" style="max-height:320px; overflow:auto; padding:10px;">
          <div style="color:#9ca3af; font-size:13px;">⏳ Caricamento...</div>
        </div>

        <div style="display:flex; gap:10px; justify-content:flex-end; padding:10px; border-top:1px solid #e5e7eb;">
          <button id="turniReprintPrint" style="padding:8px 12px; border-radius:8px; background:#f59e42; border:0; color:#fff; font-weight:800;">🖨️ STAMPA</button>
          <button id="turniReprintDownload" style="padding:8px 12px; border-radius:8px; background:#2563eb; border:0; color:#fff; font-weight:800;">⬇️ SCARICA</button>
        </div>
      </div>
    </div>
  `;

  const close = () => wrap.remove();
  wrap.querySelector('#turniReprintClose')?.addEventListener('click', close);
  wrap.addEventListener('click', (e) => { if (e.target === wrap) close(); });

  document.body.appendChild(wrap);

  TURNI._loadTurniReprintList(wrap);
};

TURNI._loadTurniReprintList = async function (modalRoot) {
  const list = modalRoot.querySelector('#turniReprintList');
  const btnPrint = modalRoot.querySelector('#turniReprintPrint');
  const btnDl = modalRoot.querySelector('#turniReprintDownload');
  let selectedTurnoId = null;

  // ✅ kiosk: nascondi download
  if (btnDl) btnDl.style.display = 'none';

  try {
    const r = await fetch(`${API_BASE}/turni_list.php?t=${Date.now()}`, { cache: 'no-store' });
    const j = await r.json();

    if (!j.success || !Array.isArray(j.data) || j.data.length === 0) {
      list.innerHTML = `<div style="color:#9ca3af; font-size:13px;">⚠️ Nessun turno trovato</div>`;
      if (btnPrint) btnPrint.disabled = true;
      return;
    }

    // default = più recente
    selectedTurnoId = j.data[0].id;

    list.innerHTML = '';
    j.data.forEach((turno, idx) => {
      const item = document.createElement('div');
      item.style.cssText = `
        padding:10px 12px; border-radius:8px; cursor:pointer;
        border:1px solid ${idx === 0 ? '#93c5fd' : '#e5e7eb'};
        background:${idx === 0 ? '#eff6ff' : '#fff'};
        margin-bottom:8px; font-size:13px;
      `;
      const statoIcon = turno.stato === 'online' ? '🟢' : '⚫';
      const dtFine = turno.data_fine ? ` — chiuso: ${turno.data_fine}` : '';
      item.textContent = `${statoIcon} Turno ${String(turno.numero_turno).padStart(3, '0')} — ${turno.operatore_cod} — ${turno.data_inizio || '-'}${dtFine}`;

      item.addEventListener('click', () => {
        [...list.querySelectorAll('[data-turno-item="1"]')].forEach(el => {
          el.style.borderColor = '#e5e7eb';
          el.style.background = '#fff';
        });
        item.style.borderColor = '#93c5fd';
        item.style.background = '#eff6ff';
        selectedTurnoId = turno.id;
      });

      item.dataset.turnoItem = "1";
      list.appendChild(item);
    });

    // ✅ STAMPA DIRETTA TERMICA: niente window.open, niente nuove pagine
    if (btnPrint) {
      btnPrint.onclick = async () => {
        if (!selectedTurnoId) return;

        try {
          if (typeof showToast === 'function') {
            showToast('⏳ Stampa turno...', 'info', 2000);
          }

          const url = `${API_BASE}/turni_stampa.php?id=${selectedTurnoId}&action=thermal&t=${Date.now()}`;
          const resp = await fetch(url, { cache: 'no-store' });

          const raw = await resp.text();
          let jj = null;
          try { jj = JSON.parse(raw); } catch (_) {}

          if (!jj || jj.success !== true) {
            console.error('turni_stampa thermal response:', raw);
            if (typeof showToast === 'function') {
              showToast('❌ ' + ((jj && jj.message) ? jj.message : 'Errore stampa turno'), 'error', 4500);
            }
            return;
          }

          if (typeof showToast === 'function') {
            showToast('✅ Turno stampato', 'success', 2500);
          }

          // ✅ chiudi popup dopo stampa
          document.getElementById('turniReprintModal')?.remove();

        } catch (e) {
          console.error('Stampa turno error:', e);
          if (typeof showToast === 'function') {
            showToast('❌ Errore stampa: ' + (e?.message || e), 'error', 4500);
          }
        }
      };
    }

  } catch (e) {
    list.innerHTML = `<div style="color:#ef4444; font-size:13px;">❌ Errore caricamento turni</div>`;
    if (btnPrint) btnPrint.disabled = true;
  }
};
// ============ CREATE OPERATORE MODAL ============
TURNI.createOperatoreModal = async function() {
    console.log('🎨 TURNI.createOperatoreModal');
    
    // Modal HTML
    const modal = document.createElement('div');
    modal.id = 'operatoreModal';
    modal.style.cssText = `
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 9999;
        justify-content: center;
        align-items: center;
    `;
    modal.innerHTML = `
        <div style="background: white; padding: 30px; border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); min-width: 400px;">
            <h2 style="margin: 0 0 20px 0; color: #333; font-size: 18px;">📋 Seleziona Operatore</h2>
            <select id="operatoreSelModal" style="width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px; margin-bottom: 20px; box-sizing: border-box;">
                <option value="">-- Scegli operatore --</option>
            </select>
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button id="cancelOperatoreBtn" style="padding: 10px 20px; background: #999; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">Annulla</button>
                <button id="confirmOperatoreBtn" style="padding: 10px 20px; background: #22c55e; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">✅ Apri Turno</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);

    // Load operatori
    await TURNI.loadOperatoriModal();

    // Event listeners
    document.getElementById('cancelOperatoreBtn')?.addEventListener('click', () => {
        modal.style.display = 'none';
    });

    document.getElementById('confirmOperatoreBtn')?.addEventListener('click', TURNI.handleLoginFromModal);
};

// ============ LOAD OPERATORI MODAL ============
TURNI.loadOperatoriModal = async function() {
    console.log('📋 TURNI.loadOperatoriModal');
    const sel = document.getElementById('operatoreSelModal');
    if (!sel) return;

    try {
        const r = await fetch(`${API_BASE}/turni_operatori_lista.php?t=${Date.now()}`, { cache: 'no-store' });
        const j = await r.json();

        console.log('📡 Risposta operatori:', j);

        if (!j.success || !Array.isArray(j.data)) {
            console.warn('⚠️ Errore caricamento operatori:', j.message);
            return;
        }

        sel.innerHTML = '<option value="">-- Scegli operatore --</option>';
        j.data.forEach(op => {
            const opt = document.createElement('option');
            opt.value = op.cod;
            opt.textContent = op.label;
            sel.appendChild(opt);
        });
        
        console.log('✅ Operatori caricati:', j.data.length);
    } catch (e) {
        console.error('❌ Errore caricamento operatori:', e);
    }
};

// ============ HANDLE LOGIN CLICK ============
TURNI.handleLoginClick = async function() {
    console.log('🔐 TURNI.handleLoginClick');
    const modal = document.getElementById('operatoreModal');
    if (modal) {
        modal.style.display = 'flex';
        document.getElementById('operatoreSelModal').focus();
    }
};

// ============ HANDLE LOGIN FROM MODAL ============
TURNI.handleLoginFromModal = async function() {
    console.log('🔐 TURNI.handleLoginFromModal');
    const sel = document.getElementById('operatoreSelModal');
    if (!sel?.value) {
        showToast('❌ Seleziona un operatore', 'error');
        return;
    }

    const operatore_cod = sel.value;
    console.log('🔓 Apertura turno per operatore:', operatore_cod);

    try {
        const r = await fetch(`${API_BASE}/turni_operatore_login.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ operatore_cod })
        });
        const j = await r.json();

        console.log('📡 Risposta login:', j);

        if (!j.success) {
            showToast('❌ ' + (j.message || 'Errore login'), 'error');
            return;
        }

        // ✅ Setta stato globale
        currentOperatore = operatore_cod;
        currentOperatoreStato = 'online';
        window.turnoAttivo = true;
        window.operatoreTurno = operatore_cod;
        window.operatoreTurnoNome = j.data?.operatore_nome || operatore_cod;

        // ✅ NUOVO: salva id_sessione e numero_turno (turni_sessioni)
        // (servono per logout corretto, TXT, stampa, e popolare idturno)
        if (typeof currentTurnoSessionId !== 'undefined') {
            currentTurnoSessionId = j.data?.id_sessione || null;
        }
        if (typeof currentTurnoNumero !== 'undefined') {
            currentTurnoNumero = j.data?.numero_turno || null;
        }

        // comodo per debug
        window.turnoSessionId = j.data?.id_sessione || null;
        window.turnoNumero = j.data?.numero_turno || null;

        console.log('✅ Stato aggiornato:', {
            turnoAttivo: window.turnoAttivo,
            operatore: window.operatoreTurno,
            id_sessione: window.turnoSessionId,
            numero_turno: window.turnoNumero
        });

        // ✅ Salva in localStorage
        localStorage.setItem('turnoAttivo', '1');
        localStorage.setItem('operatoreTurno', operatore_cod);
        localStorage.setItem('operatoreTurnoNome', window.operatoreTurnoNome);
        localStorage.setItem('turnoStartTime', Date.now().toString());
        localStorage.setItem('turnoDowntimeSeconds', '0');

        // ✅ NUOVO: persistenza id_sessione/numero_turno
        if (j.data?.id_sessione) localStorage.setItem('turnoSessionId', String(j.data.id_sessione));
        if (j.data?.numero_turno) localStorage.setItem('turnoNumero', String(j.data.numero_turno));

        await TURNI.updateUI();
        await TURNI.updateButtonStates();
        await TURNI.startTurnoTimer();

        // Chiudi modal
        document.getElementById('operatoreModal').style.display = 'none';

        showToast('✅ ' + j.message, 'success');
    } catch (e) {
        console.error('❌ Errore login:', e);
        showToast('❌ Errore connessione: ' + (e?.message || e), 'error');
    }
};

// ============ LOGOUT ============
TURNI.handleLogout = async function() {
    console.log('🔒 TURNI.handleLogout');

    if (!currentOperatore) {
        showToast('❌ Nessun operatore in turno', 'error');
        return;
    }

    // ✅ conferma prima di chiudere
    const ok = confirm(`Chiudere il turno dell'operatore ${currentOperatore}?`);
    if (!ok) return;

    const savedSessionId = localStorage.getItem('turnoSessionId');
    const id_sessione = (typeof currentTurnoSessionId !== 'undefined' && currentTurnoSessionId)
        ? currentTurnoSessionId
        : (savedSessionId ? parseInt(savedSessionId, 10) : null);

    const payload = { operatore_cod: currentOperatore, id_sessione: id_sessione };
    console.log('📤 Logout payload:', payload);

    try {
        const r = await fetch(`${API_BASE}/turni_operatore_logout.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const j = await r.json();
        console.log('📡 Risposta logout:', j);

        if (!j.success) {
            showToast('❌ ' + (j.message || 'Errore logout'), 'error');
            return;
        }

        // reset stato
        currentOperatore = null;
        currentOperatoreStato = 'offline';
        window.turnoAttivo = false;
        window.operatoreTurno = null;
        window.operatoreTurnoNome = null;

        if (typeof currentTurnoSessionId !== 'undefined') currentTurnoSessionId = null;
        if (typeof currentTurnoNumero !== 'undefined') currentTurnoNumero = null;
        window.turnoSessionId = null;
        window.turnoNumero = null;

        localStorage.removeItem('turnoAttivo');
        localStorage.removeItem('operatoreTurno');
        localStorage.removeItem('operatoreTurnoNome');
        localStorage.removeItem('turnoStartTime');
        localStorage.removeItem('turnoDowntimeSeconds');
        localStorage.removeItem('turnoSessionId');
        localStorage.removeItem('turnoNumero');

        if (turnoTimerInterval) {
            clearInterval(turnoTimerInterval);
            turnoTimerInterval = null;
        }

        await TURNI.updateUI();
        await TURNI.updateButtonStates();

        showToast('✅ ' + (j.message || 'Turno chiuso'), 'success');
    } catch (e) {
        console.error('❌ Errore logout:', e);
        showToast('❌ Errore connessione: ' + (e?.message || e), 'error');
    }
};

// ============ UPDATE UI ============
TURNI.updateUI = function() {
    console.log('🎨 TURNI.updateUI');
    const nomeEl = document.getElementById('operatoreNome');
    const loginBtn = document.getElementById('loginBtn');
    const logoutBtn = document.getElementById('logoutBtn');
    const statusBadge = document.getElementById('statusBadge');

    if (!nomeEl) {
        console.warn('⚠️ #operatoreNome non trovato');
        return;
    }

    if (currentOperatoreStato === 'online' && currentOperatore) {
        console.log('🟢 Turno APERTO');

        nomeEl.textContent = window.operatoreTurnoNome || currentOperatore;
        nomeEl.style.color = '#22c55e';

        if (loginBtn) loginBtn.style.display = 'none';
        if (logoutBtn) logoutBtn.style.display = 'inline-block';
        if (statusBadge) {
            statusBadge.textContent = '🟢 online';
            statusBadge.style.color = '#22c55e';
            statusBadge.style.background = '#d1fae5';
        }
    } else {
        console.log('⚪ Turno CHIUSO');
        nomeEl.textContent = '-';
        nomeEl.style.color = '#dc2626';

        if (loginBtn) loginBtn.style.display = 'inline-block';
        if (logoutBtn) logoutBtn.style.display = 'none';
        if (statusBadge) {
            statusBadge.textContent = '⚪ offline';
            statusBadge.style.color = '#999';
            statusBadge.style.background = '#f0f0f0';
        }
    }
};

// ============ CHECK TURNO AL STARTUP ============
TURNI.checkTurnoAtStartup = async function() {
    console.log('🔄 TURNI.checkTurnoAtStartup');

    // 1) prova a ripristinare da DB (fonte autorevole)
    try {
        const r = await fetch(`${API_BASE}/turni_operatore_stato.php?t=${Date.now()}`, { cache: 'no-store' });
        const j = await r.json();

        console.log('📡 Stato turno da DB:', j);

        if (j.success && j.data && j.data.stato === 'online') {
            // ripristino da DB
            currentOperatore = j.data.operatore_cod;
            currentOperatoreStato = 'online';
            window.turnoAttivo = true;

            window.operatoreTurno = j.data.operatore_cod;
            window.operatoreTurnoNome = j.data.operatore_cod; // il nome lo puoi mappare da costanti se vuoi

            // ✅ sessione turno
            currentTurnoSessionId = j.data.id_sessione || null;
            currentTurnoNumero = j.data.numero_turno || null;
            window.turnoSessionId = currentTurnoSessionId;
            window.turnoNumero = currentTurnoNumero;

            // ✅ localStorage: allinea stato
            localStorage.setItem('turnoAttivo', '1');
            localStorage.setItem('operatoreTurno', currentOperatore);
            localStorage.setItem('operatoreTurnoNome', window.operatoreTurnoNome || currentOperatore);

            if (currentTurnoSessionId) localStorage.setItem('turnoSessionId', String(currentTurnoSessionId));
            if (currentTurnoNumero) localStorage.setItem('turnoNumero', String(currentTurnoNumero));

            // startTime: se non c’è, impostalo ora (non possiamo ricostruire perfettamente il passato senza un endpoint dedicato)
            if (!localStorage.getItem('turnoStartTime')) {
                localStorage.setItem('turnoStartTime', Date.now().toString());
            }
            if (!localStorage.getItem('turnoDowntimeSeconds')) {
                localStorage.setItem('turnoDowntimeSeconds', '0');
            }

            await TURNI.updateUI();
            await TURNI.updateButtonStates();
            await TURNI.startTurnoTimer();

            showToast(`🔄 Turno ripristinato da DB: ${window.operatoreTurnoNome}`, 'info', 5000);
            return;
        }
    } catch (e) {
        console.warn('⚠️ Ripristino da DB fallito, provo da localStorage:', e);
    }

    // 2) fallback: ripristino da localStorage (tuo comportamento attuale)
    const wasActive = localStorage.getItem('turnoAttivo') === '1';
    const savedOperatore = localStorage.getItem('operatoreTurno');
    const savedNome = localStorage.getItem('operatoreTurnoNome');
    const startTime = localStorage.getItem('turnoStartTime');

    console.log('💾 localStorage:', { wasActive, savedOperatore, savedNome, startTime });

    if (wasActive && savedOperatore && startTime) {
        currentOperatore = savedOperatore;
        currentOperatoreStato = 'online';
        window.turnoAttivo = true;
        window.operatoreTurno = savedOperatore;
        window.operatoreTurnoNome = savedNome || savedOperatore;

        // ✅ sessione da localStorage (se presente)
        currentTurnoSessionId = localStorage.getItem('turnoSessionId') ? parseInt(localStorage.getItem('turnoSessionId'), 10) : null;
        currentTurnoNumero = localStorage.getItem('turnoNumero') ? parseInt(localStorage.getItem('turnoNumero'), 10) : null;
        window.turnoSessionId = currentTurnoSessionId;
        window.turnoNumero = currentTurnoNumero;

        // downtime come avevi già
        const now = Date.now();
        const startTimeMs = parseInt(startTime, 10);
        const downtimeMs = now - startTimeMs;
        const downtimeSeconds = Math.floor(downtimeMs / 1000);

        let downtimeSaved = parseInt(localStorage.getItem('turnoDowntimeSeconds') || '0', 10);
        downtimeSaved += downtimeSeconds;

        localStorage.setItem('turnoDowntimeSeconds', downtimeSaved.toString());
        localStorage.setItem('turnoStartTime', now.toString());

        await TURNI.updateUI();
        await TURNI.updateButtonStates();
        await TURNI.startTurnoTimer();

        const downtimeMin = Math.floor(downtimeSaved / 60);
        const downtimeSec = downtimeSaved % 60;
        const downtimeStr = downtimeMin > 0 ? `+${downtimeMin}m ${downtimeSec}s downtime` : `+${downtimeSec}s downtime`;
        showToast(`🔄 Turno ripristinato: ${window.operatoreTurnoNome} (${downtimeStr})`, 'info', 5000);
    }
};

// ============ START TURNO TIMER ============
TURNI.startTurnoTimer = async function() {
    console.log('⏱️ TURNI.startTurnoTimer');
    
    // Ferma timer precedente se esiste
    if (turnoTimerInterval) {
        clearInterval(turnoTimerInterval);
    }

    // Aggiorna subito
    TURNI.updateTurnoTimer();

    // Aggiorna ogni secondo
    turnoTimerInterval = setInterval(() => {
        TURNI.updateTurnoTimer();
    }, 1000);
};

// ============ UPDATE TURNO TIMER ============
TURNI.updateTurnoTimer = async function() {
    if (!window.turnoAttivo) return;

    const timerEl = document.getElementById('turnoTimer');
    if (!timerEl) return;

    const startTime = parseInt(localStorage.getItem('turnoStartTime') || Date.now(), 10);
    const downtimeSeconds = parseInt(localStorage.getItem('turnoDowntimeSeconds') || '0', 10);
    
    const now = Date.now();
    const elapsedMs = now - startTime;
    const totalSeconds = Math.floor(elapsedMs / 1000) + downtimeSeconds;

    // Calcola giorni, ore, minuti
    const days = Math.floor(totalSeconds / 86400);
    const hours = Math.floor((totalSeconds % 86400) / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);

    // Formatta: gg:hh:mm
    const formatted = `${String(days).padStart(2, '0')}:${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;

    timerEl.textContent = `⏱️ ${formatted}`;
    timerEl.style.color = days > 0 ? '#ea580c' : '#2563eb';
};

// ============ UPDATE BUTTON STATES ============
TURNI.updateButtonStates = async function() {
    const isOpen = window.turnoAttivo === true;
    
    console.log('🔘 TURNI.updateButtonStates - isOpen:', isOpen);

    // ✅ Emetti Ticket
    const emitBtn = document.getElementById('emitTicketBtn');
    if (emitBtn) {
        emitBtn.disabled = !isOpen;
        emitBtn.style.opacity = isOpen ? '1' : '0.5';
        emitBtn.style.cursor = isOpen ? 'pointer' : 'not-allowed';
        emitBtn.style.pointerEvents = isOpen ? 'auto' : 'none';
        emitBtn.title = isOpen ? 'Emetti Ticket' : 'Turno chiuso';
        console.log('🎫 Emetti Ticket:', { disabled: emitBtn.disabled });
    }

    // ✅ Ristampa Ticket (SEMPRE abilitato)
    const reprintBtn = document.getElementById('reprintTicketBtn');
    if (reprintBtn) {
        reprintBtn.disabled = false;
        reprintBtn.style.opacity = '1';
        reprintBtn.style.cursor = 'pointer';
        reprintBtn.style.pointerEvents = 'auto';
        console.log('🖨️ Ristampa Ticket: SEMPRE abilitato');
    }

    // ✅ Inserisci Targa
    const insertPlateBtn = document.getElementById('createNewPlateInline');
    if (insertPlateBtn) {
        insertPlateBtn.disabled = !isOpen;
        insertPlateBtn.style.opacity = isOpen ? '1' : '0.5';
        insertPlateBtn.style.cursor = isOpen ? 'pointer' : 'not-allowed';
        insertPlateBtn.style.pointerEvents = isOpen ? 'auto' : 'none';
        insertPlateBtn.title = isOpen ? 'Inserisci Targa' : 'Turno chiuso';
        console.log('🚗 Inserisci Targa:', { disabled: insertPlateBtn.disabled });
    }

    // ✅ Salva Dati
    const saveBtn = document.querySelector('button.btn-save, button[onclick*="handleSave"], button[id*="save"]');
    if (saveBtn) {
        saveBtn.disabled = !isOpen;
        saveBtn.style.opacity = isOpen ? '1' : '0.5';
        saveBtn.style.cursor = isOpen ? 'pointer' : 'not-allowed';
        saveBtn.style.pointerEvents = isOpen ? 'auto' : 'none';
        saveBtn.title = isOpen ? 'Salva Dati' : 'Turno chiuso';
        console.log('💾 Salva Dati:', { disabled: saveBtn.disabled });
    }

    // ✅ Modulo 5: Inserisci Ricarica
    const ricaricaBtn = document.getElementById('inserisciRicaricaBtn');
    if (ricaricaBtn) {
        ricaricaBtn.disabled = !isOpen;
        ricaricaBtn.style.opacity = isOpen ? '1' : '0.5';
        ricaricaBtn.style.cursor = isOpen ? 'pointer' : 'not-allowed';
        ricaricaBtn.style.pointerEvents = isOpen ? 'auto' : 'none';
        ricaricaBtn.title = isOpen ? 'Inserisci Ricarica' : 'Turno chiuso';
        console.log('💳 Inserisci Ricarica:', { disabled: ricaricaBtn.disabled });
    }

    // ✅ Modulo 5: Crea Nuova Tessera
    const nuovaTesseraBtn = document.getElementById('createNewCardBtn');
    if (nuovaTesseraBtn) {
        nuovaTesseraBtn.disabled = !isOpen;
        nuovaTesseraBtn.style.opacity = isOpen ? '1' : '0.5';
        nuovaTesseraBtn.style.cursor = isOpen ? 'pointer' : 'not-allowed';
        nuovaTesseraBtn.style.pointerEvents = isOpen ? 'auto' : 'none';
        nuovaTesseraBtn.title = isOpen ? 'Crea Nuova Tessera' : 'Turno chiuso';
        console.log('📇 Crea Nuova Tessera:', { disabled: nuovaTesseraBtn.disabled });
    }
};

// ============ CHECK PRIMA AZIONI ============
TURNI.checkBeforeAction = async function(azione) {
    console.log('⚠️ TURNI.checkBeforeAction:', azione);
    if (!currentOperatore || currentOperatoreStato !== 'online') {
        showToast(`❌ Turno non aperto - ${azione} bloccato`, 'error', 3000);
        return false;
    }

    try {
        await fetch(`${API_BASE}/log_azione_operatore.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                operatore_cod: currentOperatore,
                azione: azione,
                plate_id: window.PLATE_ID || null,
                ticket_code: window.currentTicketCode || null,
                passage_id: window.PASSAGE_ID || null
            })
        });
    } catch (e) {
        console.warn('Log azione fallito:', e);
    }

    return true;
};

// ============ EXPORT ============
window.checkTurnoBeforeAction = TURNI.checkBeforeAction;

console.log('✅ turni.js caricato');
