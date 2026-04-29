/**
 * 📋 Sistema Turni Operatori ANPR
 * ✅ Gestisce login/logout operatori e blocchi azioni
 * ✅ Integrato con costanti.txt per lista operatori
 * ✅ Logga tutte le azioni in tabella operatori_log_azioni
 */

window.TURNI = window.TURNI || {};

// ============ STATO GLOBALE ============
let currentOperatore = null;
let currentOperatoreStato = 'offline';
window.turnoAttivo = false;

// ============ INIT AL CARICAMENTO ============
document.addEventListener('DOMContentLoaded', async () => {
    await TURNI.initUI();
    await TURNI.loadOperatori();
    await TURNI.checkTurnoAtStartup();
    await TURNI.updateButtonStates();
});

// ============ INIT UI ============
TURNI.initUI = async function() {
    // Badge operatore nella header
    const headerStatus = document.querySelector('.header-status');
    if (!headerStatus) return;

    const badge = document.createElement('div');
    badge.id = 'operatoreBadge';
    badge.style.cssText = `
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 12px;
        background: #f3f4f6;
        border-radius: 6px;
        font-size: 13px;
        margin-right: 14px;
    `;
    badge.innerHTML = `
        <div style="font-weight: 700; white-space: nowrap;">
            Operatore: <span id="operatoreNome" style="color: #dc2626;">-</span>
        </div>
        <select id="operatoreSel" style="padding: 4px 6px; border-radius: 4px; border: 1px solid #d1d5db; font-size: 12px; min-width: 120px;">
            <option value="">Seleziona...</option>
        </select>
        <button id="loginBtn" class="btn-small" style="background: #22c55e; color: white; padding: 4px 8px; cursor: pointer;">🔓 Apri Turno</button>
        <button id="logoutBtn" class="btn-small" style="background: #dc2626; color: white; padding: 4px 8px; cursor: pointer; display: none;">🔒 Chiudi Turno</button>
        <span id="statusBadge" style="font-weight: 700; white-space: nowrap; color: #999;">⚪ offline</span>
    `;

    headerStatus.insertBefore(badge, headerStatus.firstChild);

    // Event listeners
    document.getElementById('loginBtn')?.addEventListener('click', TURNI.handleLogin);
    document.getElementById('logoutBtn')?.addEventListener('click', TURNI.handleLogout);
};

// ============ LOAD OPERATORI ============
TURNI.loadOperatori = async function() {
    const sel = document.getElementById('operatoreSel');
    if (!sel) return;

    try {
        const r = await fetch(`${API_BASE}/costanti_operatori_get.php?t=${Date.now()}`, { cache: 'no-store' });
        const j = await r.json();

        if (!j.success || !Array.isArray(j.data)) return;

        sel.innerHTML = '<option value="">Seleziona operatore...</option>';
        j.data.forEach(op => {
            const opt = document.createElement('option');
            opt.value = op.cod;
            opt.textContent = op.label;
            sel.appendChild(opt);
        });
    } catch (e) {
        console.error('Errore caricamento operatori:', e);
    }
};

// ============ LOGIN ============
TURNI.handleLogin = async function() {
    const sel = document.getElementById('operatoreSel');
    if (!sel?.value) {
        showToast('❌ Seleziona un operatore', 'error');
        return;
    }

    const operatore_cod = sel.value;

    try {
        const r = await fetch(`${API_BASE}/turni_operatore_login.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ operatore_cod })
        });
        const j = await r.json();

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

        // ✅ Salva in localStorage
        localStorage.setItem('turnoAttivo', '1');
        localStorage.setItem('operatoreTurno', operatore_cod);
        localStorage.setItem('operatoreTurnoNome', window.operatoreTurnoNome);

        await TURNI.updateUI();
        await TURNI.updateButtonStates();

        showToast('✅ ' + j.message, 'success');
    } catch (e) {
        showToast('❌ Errore connessione: ' + (e?.message || e), 'error');
    }
};

// ============ LOGOUT ============
TURNI.handleLogout = async function() {
    if (!currentOperatore) {
        showToast('❌ Nessun operatore in turno', 'error');
        return;
    }

    try {
        const r = await fetch(`${API_BASE}/turni_operatore_logout.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ operatore_cod: currentOperatore })
        });
        const j = await r.json();

        if (!j.success) {
            showToast('❌ ' + (j.message || 'Errore logout'), 'error');
            return;
        }

        // ✅ Resetta stato globale
        currentOperatore = null;
        currentOperatoreStato = 'offline';
        window.turnoAttivo = false;
        window.operatoreTurno = null;
        window.operatoreTurnoNome = null;

        // ✅ Pulisci localStorage
        localStorage.removeItem('turnoAttivo');
        localStorage.removeItem('operatoreTurno');
        localStorage.removeItem('operatoreTurnoNome');

        await TURNI.updateUI();
        await TURNI.updateButtonStates();

        showToast('✅ ' + j.message, 'success');
    } catch (e) {
        showToast('❌ Errore connessione: ' + (e?.message || e), 'error');
    }
};

// ============ UPDATE UI ============
TURNI.updateUI = function() {
    const nomeEl = document.getElementById('operatoreNome');
    const selEl = document.getElementById('operatoreSel');
    const loginBtn = document.getElementById('loginBtn');
    const logoutBtn = document.getElementById('logoutBtn');
    const statusBadge = document.getElementById('statusBadge');

    if (!nomeEl) return;

    if (currentOperatoreStato === 'online' && currentOperatore) {
        const opt = Array.from(selEl?.options || []).find(o => o.value === currentOperatore);
        const label = opt ? opt.textContent : currentOperatore;

        nomeEl.textContent = label;
        nomeEl.style.color = '#22c55e';

        if (selEl) selEl.disabled = true;
        if (loginBtn) loginBtn.style.display = 'none';
        if (logoutBtn) logoutBtn.style.display = 'inline-block';
        if (statusBadge) {
            statusBadge.textContent = '🟢 online';
            statusBadge.style.color = '#22c55e';
        }
    } else {
        nomeEl.textContent = '-';
        nomeEl.style.color = '#dc2626';

        if (selEl) selEl.disabled = false;
        if (loginBtn) loginBtn.style.display = 'inline-block';
        if (logoutBtn) logoutBtn.style.display = 'none';
        if (statusBadge) {
            statusBadge.textContent = '⚪ offline';
            statusBadge.style.color = '#999';
        }
    }
};

// ============ CHECK TURNO AL STARTUP ============
TURNI.checkTurnoAtStartup = async function() {
    // ✅ Prova a ripristinare da localStorage
    const wasActive = localStorage.getItem('turnoAttivo') === '1';
    const savedOperatore = localStorage.getItem('operatoreTurno');
    const savedNome = localStorage.getItem('operatoreTurnoNome');

    if (wasActive && savedOperatore) {
        currentOperatore = savedOperatore;
        currentOperatoreStato = 'online';
        window.turnoAttivo = true;
        window.operatoreTurno = savedOperatore;
        window.operatoreTurnoNome = savedNome || savedOperatore;

        await TURNI.updateUI();
        await TURNI.updateButtonStates();

        showToast(`🔄 Turno ripristinato: ${window.operatoreTurnoNome}`, 'info', 3000);
    }
};

// ============ UPDATE BUTTON STATES ============
TURNI.updateButtonStates = async function() {
    const isOpen = window.turnoAttivo === true;

    // Emetti Ticket
    const emitBtn = document.getElementById('emitTicketBtn');
    if (emitBtn) {
        emitBtn.disabled = !isOpen;
        emitBtn.style.opacity = isOpen ? '1' : '0.5';
        emitBtn.style.cursor = isOpen ? 'pointer' : 'not-allowed';
        emitBtn.title = isOpen ? 'Emetti Ticket' : 'Turno chiuso - Non disponibile';
    }

    // Ristampa Ticket (SEMPRE abilitato)
    const reprintBtn = document.getElementById('reprintTicketBtn');
    if (reprintBtn) {
        reprintBtn.disabled = false;
        reprintBtn.style.opacity = '1';
        reprintBtn.style.cursor = 'pointer';
    }

    // Salva (cerca vari selettori possibili)
    const saveBtn = document.querySelector('button.btn-save, button[onclick*="handleSave"], button[id*="save"]');
    if (saveBtn) {
        saveBtn.disabled = !isOpen;
        saveBtn.style.opacity = isOpen ? '1' : '0.5';
        saveBtn.style.cursor = isOpen ? 'pointer' : 'not-allowed';
        saveBtn.title = isOpen ? 'Salva Dati' : 'Turno chiuso - Non disponibile';
    }
};

// ============ CHECK PRIMA AZIONI ============
TURNI.checkBeforeAction = async function(azione) {
    if (!currentOperatore || currentOperatoreStato !== 'online') {
        showToast(`❌ Turno non aperto - ${azione} bloccato`, 'error', 3000);
        return false;
    }

    // Log azione
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
