console.log('📋 plate-management.js caricato');
// ===== Helper: mostra solo ultima parte del ticket_code (dopo l'ultimo "-") =====
function formatTicketShort(ticketCode) {
  const t = (ticketCode || '').trim();
  if (!t) return '-';
  return t.includes('-') ? t.split('-').pop() : t;
}
// ===== UPDATE PLATES LIST (UNICA VERSIONE) =====
function updatePlatesList(plates) {
    const listEl = document.getElementById('platesList');
    if (!listEl) return;

    if (!plates || !plates.length) {
        listEl.innerHTML = '<p class="empty-state">Nessuna targa trovata</p>';
        updateStats(0);
        return;
    }

    // ✅ LIMITE MASSIMO di elementi
    const maxDisplay = 1000;
	
    const displayPlates = plates.length > maxDisplay ? plates.slice(0, maxDisplay) : plates;

    const html = displayPlates.map(plate => {
        // =============== PATCH NORMALIZZAZIONE BEGIN ===============
        /*
        // VERSIONE COMMENTATA:
        if (!('annullato' in plate)) {
            if ('Tannullato' in plate) plate.annullato = Number(plate.Tannullato);
            else if ('Pannullato' in plate) plate.annullato = Number(plate.Pannullato);
            // PATCH: fallback forced to 0 se non esiste
            else plate.annullato = 0;
        }
        // PATCH: normalizza pagato (targa e passaggio)
        if (!("pagato" in plate)) {
            if ("Tpaid" in plate) plate.pagato = Number(plate.Tpaid);
            else if ("Ppaid" in plate) plate.pagato = Number(plate.Ppaid);
            else if ("paid" in plate) plate.pagato = Number(plate.paid);
            else plate.pagato = 0;
        }
        */
       
        // PATCH: normalizzazione "annullato" e "pagato" robusta, gestisce anche undefined/null/""
        if (!('annullato' in plate)) {
            // Se Tannullato o Pannullato sono assenti/null/vuote → 0
            plate.annullato = Number(
                (typeof plate.Tannullato !== 'undefined' && plate.Tannullato !== null && plate.Tannullato !== '')
                    ? plate.Tannullato
                    : (typeof plate.Pannullato !== 'undefined' && plate.Pannullato !== null && plate.Pannullato !== '')
                        ? plate.Pannullato
                        : 0
            );
        }
        if (!('pagato' in plate)) {
            // Se Tpaid o Ppaid o paid sono assenti/null/vuote → 0
            plate.pagato = Number(
                (typeof plate.Tpaid !== 'undefined' && plate.Tpaid !== null && plate.Tpaid !== '')
                    ? plate.Tpaid
                    : (typeof plate.Ppaid !== 'undefined' && plate.Ppaid !== null && plate.Ppaid !== '')
                        ? plate.Ppaid
                        : (typeof plate.paid !== 'undefined' && plate.paid !== null && plate.paid !== '' ? plate.paid : 0)
            );
        }
        // =============== PATCH NORMALIZZAZIONE END ===============

        const displayPlate = (plate.plate_corrected || plate.plate_number || '').toUpperCase();
        const dateObj = new Date(plate.date_detected || plate.entry_datetime);
        const timeText = isNaN(dateObj)
            ? '-'
            : `${dateObj.toLocaleDateString('it-IT')} ${dateObj.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' })}`;
        const isManual = plate.is_manual === 1 || plate.is_manual === '1';
       /// const isActive = selectedPlateId === plate.id;
	   const isActive = (typeof selectedPlateId !== 'undefined') && (selectedPlateId === plate.id);
        const isPassage = plate.is_passage === 1 || plate.is_passage === '1';

        let classList = [];
        if (isPassage) classList.push('passage');
        else if (isManual) classList.push('manual');
        if (isActive) classList.push('active');

        // PATCH: ora status corretti!
        const isAnnullato = plate.annullato === 1;
        const isPagato    = !isAnnullato && plate.pagato === 1; // PATCH: giallo solo se non annullato

        if (isAnnullato) classList.push('annullato');  // ROSSO
        else if (isPagato) classList.push('pagato');   // GIALLO

        const classAttr = classList.join(' ');

        // Badge descrittivo
// Badge descrittivo
let badgeText = '';
if (isPassage) {
  const invoiceCode = plate.invoice_code || null;
  const ticketLast = formatTicketShort(plate.ticket_code);
  badgeText = invoiceCode ? `📄 Invoice ${invoiceCode}` : `🎟️ Ticket: ${ticketLast}`;
} else if (isManual) {
  // se ha ticket_code, mostralo ridotto, altrimenti "Manuale"
  const ticketLast = formatTicketShort(plate.ticket_code);
  badgeText = (ticketLast && ticketLast !== '-') ? `🎟️ Ticket: ${ticketLast}` : '✏️ Manuale';
} else {
  // rilevata: se ha ticket_code, mostralo ridotto, altrimenti "Rilevata"
  const ticketLast = formatTicketShort(plate.ticket_code);
  badgeText = (ticketLast && ticketLast !== '-') ? `🎟️ Ticket: ${ticketLast}` : '📸 Rilevata';
}
        // ✅ NEW: icone Abbonamento/Tessera in lista (attive = colorate, inattive = grigie)
        // NB: i flag arrivano da get_plates.php (abb_attivo / tessera_attiva)
        const abbAttivo = Number(plate.abb_attivo || 0) === 1;
        const tessAttiva = Number(plate.tessera_attiva || 0) === 1;

        // puoi cambiare simboli se preferisci (FontAwesome, SVG, ecc.)
        const abbIcon = `<span class="plate-flag-icon ${abbAttivo ? 'on' : 'off'}" title="Abbonamento ${abbAttivo ? 'attivo' : 'non attivo'}">🪪</span>`;
        const tessIcon = `<span class="plate-flag-icon ${tessAttiva ? 'on' : 'off'}" title="Tessera ${tessAttiva ? 'attiva' : 'non attiva'}">💳</span>`;

        const flagsHtml = `<div class="plate-flags">${abbIcon}${tessIcon}</div>`;
		
        let plateContent = '';
        // PATCH: visualizza badge motivazione annullato/pagato
       if (isPassage) {
    const passageId = plate.passage_id || plate.id;
    const invoiceCode = plate.invoice_code || null;

    // ✅ Ticket: solo ultima parte (dopo l'ultimo "-")
    const ticketLast = formatTicketShort(plate.ticket_code);

    plateContent = `
        <div class="plate-number">PASSAGGIO #${passageId}</div>
        <div class="plate-time">🎟️ Ticket: ${ticketLast}</div>
        ${invoiceCode ? `<div class="plate-time">📄 Invoice ${invoiceCode}</div>` : ''}
        ${isAnnullato && plate.motivo ? `<div class="plate-motivo">❌ Motivo: ${plate.motivo}</div>` : ''}
        ${isPagato ? `<div class="plate-pagato">✔️ Pagato</div>` : ''}
    `;
} else {
            // OLD (senza icone abb/tessera)
            // plateContent = `
            //     <div class="plate-number">${displayPlate}</div>
            //     <div class="plate-time">${timeText}</div>
            //     <div class="plate-badge">${badgeText}</div>
            //     ${isAnnullato && plate.motivo ? `<div class="plate-motivo">❌ Motivo: ${plate.motivo}</div>` : ''}
            //     ${isPagato ? `<div class="plate-pagato">✔️ Pagato</div>` : ''}
            // `;

            // ✅ NEW: include flags
            plateContent = `
                <div class="plate-number">${displayPlate}</div>
                <div class="plate-time">${timeText}</div>
                <div class="plate-badge">${badgeText}</div>
                ${flagsHtml}
                ${isAnnullato && plate.motivo ? `<div class="plate-motivo">❌ Motivo: ${plate.motivo}</div>` : ''}
                ${isPagato ? `<div class="plate-pagato">✔️ Pagato</div>` : ''}
            `;
        }

        return `
            <div class="plate-item ${classAttr}" 
                 data-plate-id="${plate.id}"
                 data-is-passage="${isPassage ? '1' : '0'}"
                 data-passage-id="${isPassage ? (plate.passage_id || plate.id) : ''}"
                 data-plate-number="${displayPlate}">
                <div class="plate-info">
                    ${plateContent}
                </div>
            </div>
        `;
    }).join('');

    listEl.innerHTML = html;

    // PATCH: info elementi visualizzati invariata
    if (plates.length > maxDisplay) {
        const footer = document.createElement('div');
        footer.style.cssText = `
            padding: 10px; 
            background: #fef3c7; 
            border-top: 1px solid #fbbf24; 
            font-size: 11px; 
            color: #92400e;
            text-align: center;
            position: sticky;
            bottom: 0;
        `;
        footer.innerHTML = `⚠️ ${plates.length} elementi totali (mostrati ${maxDisplay})<br>Usa la ricerca per trovare targhe più vecchie`;
        listEl.appendChild(footer);
    }

    ////updateStats(plates.length);
	if (typeof updateStats === 'function') updateStats(plates.length);

 document.querySelectorAll('.plate-item').forEach(item => {
  item.addEventListener('click', (e) => {
    e.stopPropagation();

    // ✅ Selezione manuale dalla colonna sinistra => UM = 1
    window.currentUM = 1;

    const isPassage = item.dataset.isPassage === '1';
    if (isPassage) {
      const passageId = parseInt(item.dataset.passageId, 10);
      selectPassage(passageId);
    } else {
      const plateId = parseInt(item.dataset.plateId, 10);
      selectPlate(plateId);
    }
  });
});
}

/*
=== PATCH DOCUMENTAZIONE ===
- Normalizzazione per annullato/motivo/pagato A INIZIO CICLO plate.
- Nessuna riga rimossa, logica originale conservata.
- Colori: 
    .annullato = rosso tenue,
    .pagato    = giallo tenue;
    NOTA: `.pagato` appare SOLO se non annullato.
- Badge motivo: sempre in basso nella cella lista se presente.
- Badge pagato: solo se pagato==1 e non annullato.
- Gli oggetti plate ora sono colorati e badge come richiesto!
*/

/*
==== PATCH/NOTE/COMMENTI ====
- Normalizzazione per ogni plate/passage garantita a inizio ciclo.
- Nessuna riga vecchia eliminata: solo aggiunta la logica che ora colora correttamente le righe ANNULATO (rosso) e PAGATO (giallo).
- Badge "❌ Motivo" appare solo se annullato.
- Badge "✔️ Pagato" appare solo se pagato e NON annullato.
- Attenzione: per vedere subito il colore, assicurati il CSS abbia
    .plate-item.annullato   { background: #ffe5e5!important; }
    .plate-item.pagato      { background: #fffacc!important; }
    .plate-motivo { font-size:10px;color:#b91c1c;font-style:italic;}
    .plate-pagato { font-size:11px;color:#927600;}
- Il click handler non viene toccato.
============================
*/

/*
===== COMMENTI PATCH =====
- PATCH: Unificazione campo "annullato" e "motivo" su ogni oggetto plate, partendo da Tannullato/Pannullato e Tannultxt/Pannultxt (o paid).
- PATCH: Unificazione campo "pagato" su ogni oggetto plate, partendo da Tpaid/Ppaid/paid.
- PATCH: Badge e colore funzionano sempre, ora anche il giallo "pagato" scatta anche su righe passaggio (Ppaid->pagato).
- PATCH: Mostra badge "✔️ Pagato" su ogni riga gialla non annullata; motivo su ogni riga annullata.
- VECCHIO CODICE lasciato (nessuna riga rimossa), solo robustezza logica e retrocompatibilità.
============================================
*/

// ===== CARICA DATI TICKET =====
async function loadTicketData(plateId) {
    try {
        const response = await fetch(`${API_BASE}/get_tickets.php?plate_id=${plateId}`);
        const data = await response.json();
        if (data.success) {
            window.currentTicket = data.data;
        } else {
            window.currentTicket = {};
        }
    } catch (error) {
        window.currentTicket = {};
    }
}

// ===== POPOLA FORM DA TICKET =====
function updateFormWithTicketData(plate) {
    const ticket = window.currentTicket || {};
    if (document.getElementById('vehicleType')) document.getElementById('vehicleType').value = ticket.vehicle_type || '';
    if (document.getElementById('vehicleBrand')) document.getElementById('vehicleBrand').value = ticket.vehicle_brand || '';
    if (document.getElementById('vehicleColor')) document.getElementById('vehicleColor').value = ticket.vehicle_color || '';
    if (document.getElementById('vehiclePosition')) document.getElementById('vehiclePosition').value = ticket.vehicle_position || '';
    if (document.getElementById('subscription')) document.getElementById('subscription').value = ticket.subscription || '';
    if (document.getElementById('subscriptionFrom')) document.getElementById('subscriptionFrom').value = ticket.subscription_from || '';
    if (document.getElementById('subscriptionTo')) document.getElementById('subscriptionTo').value = ticket.subscription_to || '';
    if (document.getElementById('entryDate')) document.getElementById('entryDate').value = ticket.entry_date || '';
    if (document.getElementById('entryTime')) document.getElementById('entryTime').value = ticket.entry_time || '';
    if (document.getElementById('exitDate')) document.getElementById('exitDate').value = ticket.exit_date || '';
    if (document.getElementById('exitTime')) document.getElementById('exitTime').value = ticket.exit_time || '';
    if (document.getElementById('ticketInfo')) document.getElementById('ticketInfo').value = ticket.ticket_info || '';
    if (document.getElementById('paid')) document.getElementById('paid').checked = ticket.paid || false;
    if (document.getElementById('ticketBalance')) document.getElementById('ticketBalance').value = ticket.ticket_balance || '0.00';
    if (document.getElementById('ticketPrepaid')) document.getElementById('ticketPrepaid').checked = ticket.ticket_prepaid || false;
    if (document.getElementById('ticketFrom')) document.getElementById('ticketFrom').value = ticket.ticket_from || '';
    if (document.getElementById('ticketTo')) document.getElementById('ticketTo').value = ticket.ticket_to || '';
    if (document.getElementById('notes')) document.getElementById('notes').value = ticket.notes || '';
    if (document.getElementById('authorizedVehicle')) document.getElementById('authorizedVehicle').value = ticket.authorized_vehicle || '';

    // ✅ TICKET CODE - BLOCCA SE ESISTE GIÀ
    if (document.getElementById('ticketCode')) {
        document.getElementById('ticketCode').value = ticket.ticket_code || '';
        
        if (ticket.ticket_code && ticket.ticket_code.trim()) {
            document.getElementById('ticketCode').disabled = true;
            document.getElementById('ticketCode').style.background = '#f3f4f6';
            document.getElementById('ticketCode').style.color = '#6b7280';
            document.getElementById('ticketCode').title = '❌ Ticket già emesso - Non modificabile';
        }
    }

    initSubscriptionExpiryWatcher();
    initTicketPrepaidExpiryWatcher();
}

// ===== CREA TICKET MANUALE =====
function createManualTicket() {
    selectedPlateId = null;
    selectPlateLock = false;
    window.currentTicket = {};

    const manualPlate = {
        id: null,
        plate_number: '',
        plate_corrected: '',
        date_detected: new Date().toISOString(),
        is_manual: 1,
        is_passage: 0
    };

    document.querySelectorAll('.plate-item').forEach(el => el.classList.remove('active'));
    renderDetails(manualPlate);
    showToast('✏️ Compila manualmente i dati', 'info');
}

// ===== CHIUDI SCHEDA =====
function closeDetails() {
    selectedPlateId = null;
    selectPlateLock = false;
    document.querySelectorAll('.plate-item').forEach(el => el.classList.remove('active'));

    const detailsPanel = document.getElementById('detailsPanel');
    if (detailsPanel) detailsPanel.innerHTML = '<p class="empty">Seleziona una targa</p>';

    const buttonContainer = document.getElementById('detailsButtonContainer');
    if (buttonContainer) buttonContainer.style.display = 'none';

    window.currentTicket = {};
    justCreatedManualPlateId = Math.random();
    setTimeout(() => { justCreatedManualPlateId = null; }, 1000);

    showToast('✕ Scheda chiusa', 'info', 1500);
}

// ===== ELIMINA TARGA (SOLO MANUALI) =====
function confirmDeletePlate(plateId) {
    const plate = allPlates.find(p => p.id === plateId);
    if (!plate) {
        showToast('⚠️ Targa non trovata', 'error');
        return;
    }

    const plateName = (plate.plate_corrected || plate.plate_number || '').toUpperCase();

    if (!confirm(`⚠️ Elimina definitivamente:\n\n🚗 ${plateName}?`)) {
        console.log('Delete cancelled by user');
        return;
    }
    
    const reason = prompt('Motivo della cancellazione:', 'Cancellazione manuale');
    if (reason === null) {
        console.log('Delete reason cancelled');
        return;
    }
    
    deleteConfirmed(plateId, plateName, reason);
}

async function deleteConfirmed(plateId, plateName, reason) {
    try {
        console.log('deleteConfirmed:', plateId, plateName, reason);
        showToast('⏳ Eliminazione in corso...', 'info', 2000);

        const response = await fetch(`${API_BASE}/delete_plate.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                plate_id: plateId,
                reason: reason,
                deleted_by: 'web'
            })
        });

        const result = await response.json();
        console.log('deleteConfirmed response:', result);
        
        if (result.success) {
            showToast('🗑️ ' + result.message, 'success', 3000);
            
            allPlates = allPlates.filter(p => p.id !== plateId);
            selectedPlateId = null;
            selectPlateLock = false;
            
            updatePlatesList(allPlates);
            updateStats(allPlates.length);

            const detailsPanel = document.getElementById('detailsPanel');
            if (detailsPanel) {
                detailsPanel.innerHTML = '<p class="empty-state">Seleziona una targa</p>';
            }
            
            const buttonContainer = document.getElementById('detailsButtonContainer');
            if (buttonContainer) {
                buttonContainer.style.display = 'none';
            }
        } else {
            showToast('❌ Errore: ' + result.message, 'error', 3000);
        }
    } catch (error) {
        console.error('deleteConfirmed error:', error);
        showToast('❌ Errore eliminazione', 'error', 3000);
    }
}

// ===== CONTROLLO SCADENZA ABBONAMENTO =====
function initSubscriptionExpiryWatcher() {
    const subEl  = document.getElementById('subscription');
    const fromEl = document.getElementById('subscriptionFrom');
    const toEl   = document.getElementById('subscriptionTo');
    const alertEl = document.getElementById('subscriptionAlert');

    if (!subEl || !fromEl || !toEl || !alertEl) return;

    const checkExpiry = () => {
        alertEl.style.display = 'none';

        const type = subEl.value;
        const from = fromEl.value;
        const to   = toEl.value;
        if (!type || !from || !to) return;

        const today   = new Date();
        const endDate = new Date(to + 'T23:59:59');
        if (isNaN(endDate.getTime())) return;

        if (endDate < today) {
            alertEl.style.display = 'block';
            alert(`ABBONAMENTO ${type} SCADUTO il ${endDate.toLocaleDateString('it-IT')}`);
        }
    };

    subEl.addEventListener('change', checkExpiry);
    fromEl.addEventListener('change', checkExpiry);
    toEl.addEventListener('change', checkExpiry);

    checkExpiry();
}

// ===== CONTROLLO SCADENZA TESSERA A SCALARE =====
function initTicketPrepaidExpiryWatcher() {
    const enabledEl = document.getElementById('ticketPrepaid');
    const fromEl    = document.getElementById('ticketFrom');
    const toEl      = document.getElementById('ticketTo');
    const alertEl   = document.getElementById('ticketPrepaidAlert');

    if (!enabledEl || !fromEl || !toEl || !alertEl) return;

    const checkExpiry = () => {
        alertEl.style.display = 'none';

        if (!enabledEl.checked) return;

        const from = fromEl.value;
        const to   = toEl.value;
        if (!from || !to) return;

        const today   = new Date();
        const endDate = new Date(to + 'T23:59:59');
        if (isNaN(endDate.getTime())) return;

        if (endDate < today) {
            alertEl.style.display = 'block';
            alert(`Tessera a scalare SCADUTA il ${endDate.toLocaleDateString('it-IT')}`);
        }
    };

    enabledEl.addEventListener('change', checkExpiry);
    fromEl.addEventListener('change', checkExpiry);
    toEl.addEventListener('change', checkExpiry);

    checkExpiry();
}

// ===== FUNZIONI ORA INGRESSO/USCITA =====
function setEntryTimeFromDetected() {
    const plate = allPlates.find(p => p.id === selectedPlateId);
    if (!plate) return;
    const detected = new Date(plate.date_detected);
    const dateStr = detected.toISOString().slice(0, 10);
    const timeStr = detected.toTimeString().slice(0, 5);
    const dEl = document.getElementById('entryDate');
    const tEl = document.getElementById('entryTime');
    if (dEl) dEl.value = dateStr;
    if (tEl) tEl.value = timeStr;
}

function setEntryTimeNow() {
    const now = new Date();
    const dateStr = now.toISOString().slice(0, 10);
    const timeStr = now.toTimeString().slice(0, 5);
    const dEl = document.getElementById('entryDate');
    const tEl = document.getElementById('entryTime');
    if (dEl) dEl.value = dateStr;
    if (tEl) tEl.value = timeStr;
}

function setExitTimeFromDetected() {
    const plate = allPlates.find(p => p.id === selectedPlateId);
    if (!plate) return;
    const detected = new Date(plate.date_detected);
    const dateStr = detected.toISOString().slice(0, 10);
    const timeStr = detected.toTimeString().slice(0, 5);
    const dEl = document.getElementById('exitDate');
    const tEl = document.getElementById('exitTime');
    if (dEl) dEl.value = dateStr;
    if (tEl) tEl.value = timeStr;
}

function setExitTimeNow() {
    const now = new Date();
    const dateStr = now.toISOString().slice(0, 10);
    const timeStr = now.toTimeString().slice(0, 5);
    const dEl = document.getElementById('exitDate');
    const tEl = document.getElementById('exitTime');
    if (dEl) dEl.value = dateStr;
    if (tEl) tEl.value = timeStr;
}

// ===== SALVA TICKET =====
// ===== SALVA TICKET =====
async function saveFinalTicket(plateId) {
	// ✅ PATCH ROBUSTEZZA: plateId deve essere un intero valido
    plateId = parseInt(plateId, 10) || 0;

    // fallback: se arriva 0, prova a leggerlo dalla scheda aperta (se applichi anche plateId hidden)
    if (plateId <= 0) {
        const fromDom = parseInt(document.getElementById('plateId')?.value || '0', 10) || 0;
        if (fromDom > 0) plateId = fromDom;
    }

    if (plateId <= 0) {
        showToast(`❌ ID targa non valido (plateId=${plateId})`, 'error', 4000);
        return;
    }
    try {
        // --- COSTRUISCI IL PAYLOAD LEGATO AI TUOI CAMPI FORM ---
        const annullato = document.getElementById('annullato')?.checked ? 1 : 0;
        const motivo    = document.getElementById('motivo')?.value || '';

        const pagato    = document.getElementById('paid')?.checked ? 1 : 0;

        // ✅ NUOVO: tipo pagamento
        const payCash = document.getElementById('pay_cash')?.checked ? 1 : 0;
        const payElec = document.getElementById('pay_electronic')?.checked ? 1 : 0;

        const exitDate  = document.getElementById('exitDate')?.value || '';
        const exitTime  = document.getElementById('exitTime')?.value || '';

        //// VECCHIO: non inviava TpayC/TpayE
        // const data = {
        //     plate_id:   plateId,
        //     Tannullato: annullato,
        //     Tannultxt:  motivo,
        //     Tpaid:      pagato,
        //     exit_date:  exitDate,
        //     exit_time:  exitTime
        // };

        // ✅ NUOVO: invia anche TpayC/TpayE
        const data = {
            plate_id:   plateId,
            Tannullato: annullato,
            Tannultxt:  motivo,
            Tpaid:      pagato,
            TpayC:      payCash,
            TpayE:      payElec,
            exit_date:  exitDate,   // può essere '' -> il backend lo converte in NULL
            exit_time:  exitTime
        };

        console.log("DEBUG saveFinalTicket payload:", data);

        const resp = await fetch('/anpr/api/save_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await resp.json();

        if (!result.success) {
            showToast(result.message || "❌ Errore salvataggio", "error");
            return;
        }

        showToast(result.message || "✅ Dati targa aggiornati", "success");

        // Carica la lista dal backend
        loadPlates();

        // Ri-seleziona la targa dopo un attimo
        setTimeout(() => selectPlate(plateId), 500);

    } catch (e) {
        showToast("❌ Errore di rete: " + e.message, "error");
    }
}
// ===== SELEZIONA TARGA DALL'ELENCO =====
async function selectPlate(plateId, event) {
    if (event && event.stopPropagation) {
        event.stopPropagation();
    }

    selectedPlateId = plateId;
    selectedIsPassage = false;
    selectPlateLock = true;

    // Evidenzia nella lista
    document.querySelectorAll('.plate-item').forEach(el => {
        const id = parseInt(el.dataset.plateId, 10);
        el.classList.toggle('active', id === plateId);
    });

    try {
        // ✅ CARICA DATI COMPLETI DA get_plate.php
        const url = `${API_BASE}/get_plate.php?id=${plateId}&t=${Date.now()}`;
        console.log('📸 selectPlate: carico da', url);
        
        const resp = await fetch(url, { cache: 'no-store' });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const data = await resp.json();
        
        console.log('🔍 GET_PLATE risposta:', data);

        if (!data.success) {
            showToast(data.message || '❌ Errore caricamento targa', 'error', 3000);
            return;
        }

        const plate = data.data;
        console.log('✅ Targa caricata completo:', plate);

        // ✅ USA LA NUOVA FUNZIONE CON INGRESSO/USCITA
        if (typeof renderPlateDetailsWithTimes === 'function') {
            console.log('✅ Chiamo renderPlateDetailsWithTimes');
            renderPlateDetailsWithTimes(plate);
        } else if (typeof renderDetails === 'function') {
            console.log('⚠️ Fallback a renderDetails');
            renderDetails(plate);
        } else {
            showToast('❌ Nessuna funzione render disponibile', 'error');
        }

    } catch (err) {
        console.error('❌ selectPlate error:', err);
        showToast('❌ Errore caricamento targa', 'error', 3000);
    }
}
async function loadPresenzeOptions() {
  const sel = document.getElementById('presentiDays');
  if (!sel) return;

  const r = await fetch(`/anpr/api/get_presenze_options.php?t=${Date.now()}`, { cache: 'no-store' });
  const j = await r.json();
  if (!j.success) return;

  sel.innerHTML = '';
  for (const opt of (j.data.options || [])) {
    const o = document.createElement('option');
    o.value = String(opt.value);
    o.textContent = opt.label;
    sel.appendChild(o);
  }

  // ✅ default = 0 (Totale)
  sel.value = '0';
}

function getSelectedDays(sel) {
  // ✅ supporta 0 (Totale)
  let days = parseInt((sel && sel.value != null ? sel.value : '0'), 10);
  if (Number.isNaN(days)) days = 0;
  if (days < 0) days = 0;
  return days;
}

async function refreshPresenti() {
  const sel = document.getElementById('presentiDays');
  const days = getSelectedDays(sel);

  const r = await fetch(`/anpr/api/get_presenti.php?days=${days}&t=${Date.now()}`, { cache: 'no-store' });
  const j = await r.json();
  if (!j.success) return;

  const el = document.getElementById('presentiCounter');
  if (el) el.textContent = (j.data && j.data.presenti != null) ? j.data.presenti : '-';
}

async function initPresenzeTopBar() {
  // ✅ guard: evita doppia init (se chiamata due volte da più script)
  if (window.__presenzeTopbarInited) return;
  window.__presenzeTopbarInited = true;

  const sel = document.getElementById('presentiDays');
  const counter = document.getElementById('presentiCounter');
  if (!sel || !counter) return;

  await loadPresenzeOptions();

  sel.addEventListener('change', refreshPresenti);
  await refreshPresenti();

  // refresh ogni 10 secondi
  setInterval(refreshPresenti, 10000);
}

// Inizializza
document.addEventListener('DOMContentLoaded', initPresenzeTopBar);

