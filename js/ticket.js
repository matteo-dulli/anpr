console.log('🎫 ticket.js caricato');

// Restituisce la parte finale del codice ticket dopo l'ultimo trattino (es. "D27BE")
function ticketCodeTail(code) {
    if (!code) return '';
    const idx = code.lastIndexOf('-');
    return idx >= 0 ? code.slice(idx + 1) : code;
}

// ===== RENDER ELENCO TARGHE + PASSAGGI (SENZA CESTINO) =====
function updatePlatesList(list) {
    const container = document.getElementById('platesList');
    if (!container) return;

    if (!Array.isArray(list) || list.length === 0) {
        container.innerHTML = '<p class="empty-state">Nessuna targa / passaggio</p>';
        return;
    }

    container.innerHTML = list.map(plate => {
        const isPassage = plate.is_passage === 1 || plate.is_passage === '1';
        const displayPlate = (plate.plate_corrected || plate.plate_number || '').toUpperCase();

        const dt = new Date(plate.date_detected);
        const dateText = isNaN(dt)
            ? '-'
            : dt.toLocaleDateString('it-IT') + ' ' +
              dt.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });

        const originLabel = plate.origin_label
            ? plate.origin_label
            : (isPassage
                ? '🚶 Passaggio'
                : (plate.is_manual ? '✏️ Manuale' : '📸 Rilevata'));

        const extraBadge = isPassage
            ? '<div class="plate-badge">PASSAGGIO</div>'
            : (plate.is_manual
                ? '<div class="plate-badge">MANUALE</div>'
                : '');

        // G2: passaggi con ticket emesso oggi mostrano solo il tail del ticket code
        let ticketBadge = '';
        if (isPassage && plate.ticket_code) {
            const tail = ticketCodeTail(plate.ticket_code);
            if (tail) {
                ticketBadge = `<div class="plate-badge" style="background:#e8f5e9;color:#2e7d32;">🎫 ${tail}</div>`;
            }
        }

        const dataPlateId  = plate.id;
        const dataIsPass   = isPassage ? '1' : '0';
        const dataPassId   = isPassage ? (plate.passage_id || Math.abs(plate.id)) : '';

        return `
            <div class="plate-item"
                 data-plate-id="${dataPlateId}"
                 data-is-passage="${dataIsPass}"
                 data-passage-id="${dataPassId}">
                <div class="plate-info">
                    <div class="plate-number">${displayPlate}</div>
                    <div class="plate-time">${dateText}</div>
                    <div class="plate-badge">${originLabel}</div>
                    ${extraBadge}
                    ${ticketBadge}
                </div>
            </div>
        `;
    }).join('');

    // ===== AGGIUNGI EVENT LISTENER A TUTTE LE TARGHE =====
    document.querySelectorAll('.plate-item').forEach(item => {
        item.addEventListener('click', (e) => {
            e.stopPropagation();

            // D3: selezione manuale dalla prima colonna → UM=1
            window.pendingUM = 1;

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

    // ✅ TICKET CODE - USA PRIMA TICKET.TICKET_CODE, POI PLATE.TICKET_CODE
    if (document.getElementById('ticketCode')) {
        const ticketCodeValue = ticket.ticket_code || plate.ticket_code || '';
        document.getElementById('ticketCode').value = ticketCodeValue;
        
        if (ticketCodeValue && ticketCodeValue.trim()) {
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
async function saveFinalTicket(plateId) {
    try {
        const plateInput = document.getElementById('plateNumber');
        const manualPlate = plateInput ? plateInput.value : '';
        if (!manualPlate.trim()) {
            showToast('⚠️ Inserisci una targa', 'error');
            return;
        }

        const cleanDate = (value) => (!value || value === '') ? null : value;

        const data = {
            plate_id: plateId,
            plate_number: manualPlate.toUpperCase(),
            ticket_info: document.getElementById('ticketInfo')?.value || '',
            ticket_code: document.getElementById('ticketCode')?.value || '',
            vehicle_type: document.getElementById('vehicleType')?.value || '',
            vehicle_brand: document.getElementById('vehicleBrand')?.value || '',
            vehicle_color: document.getElementById('vehicleColor')?.value || '',
            vehicle_position: document.getElementById('vehiclePosition')?.value || '',
            entry_date: cleanDate(document.getElementById('entryDate')?.value),
            entry_time: cleanDate(document.getElementById('entryTime')?.value),
            exit_date: cleanDate(document.getElementById('exitDate')?.value),
            exit_time: cleanDate(document.getElementById('exitTime')?.value),
            notes: document.getElementById('notes')?.value || '',
            paid: document.getElementById('paid')?.checked ? 1 : 0,
            subscription: document.getElementById('subscription')?.value || '',
            subscription_from: cleanDate(document.getElementById('subscriptionFrom')?.value),
            subscription_to: cleanDate(document.getElementById('subscriptionTo')?.value),
            ticket_prepaid: document.getElementById('ticketPrepaid')?.checked ? 1 : 0,
            ticket_from: cleanDate(document.getElementById('ticketFrom')?.value),
            ticket_to: cleanDate(document.getElementById('ticketTo')?.value),
            ticket_balance: parseFloat(document.getElementById('ticketBalance')?.value || '0.00'),
            authorized_vehicle: document.getElementById('authorizedVehicle')?.value || ''
        };

        const endpoint = plateId === 0 ? 'create_manual_ticket.php' : 'update_ticket.php';

        const response = await fetch(`${API_BASE}/${endpoint}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });

        const result = await response.json();

        if (result.success) {
            showToast('💾 ' + result.message, 'success', 3000);

            if (plateId === 0) {
                const newPlate = {
                    id: result.plate_id,
                    plate_number: manualPlate.toUpperCase(),
                    plate_corrected: manualPlate.toUpperCase(),
                    date_detected: new Date().toISOString(),
                    is_manual: 1,
                    is_passage: 0,
                    passage_id: null,
                    ticket_code: data.ticket_code || null
                };
                allPlates.unshift(newPlate);
                updatePlatesList(allPlates);
                updateStats(allPlates.length);
                justCreatedManualPlateId = result.plate_id;
                setTimeout(() => { justCreatedManualPlateId = null; }, 10000);
            } else {
                const idx = allPlates.findIndex(p => p.id === plateId);
                if (idx !== -1) {
                    allPlates[idx].plate_number = manualPlate.toUpperCase();
                    allPlates[idx].plate_corrected = manualPlate.toUpperCase();
                    allPlates[idx].ticket_code = data.ticket_code || null;
                }
                updatePlatesList(allPlates);
                setTimeout(() => closeDetails(), 1000);
            }
        } else {
            showToast('❌ Errore: ' + result.message, 'error');
        }
    } catch (error) {
        console.error('❌ Errore salvataggio:', error);
        showToast('❌ Errore salvataggio', 'error');
    }
}

// ===== SELEZIONA TARGA DALL'ELENCO =====
function selectPlate(plateId, event) {
    if (event && event.stopPropagation) {
        event.stopPropagation();
    }

    selectedPlateId = plateId;
    selectPlateLock = true;

    // Evidenzia nella lista
    document.querySelectorAll('.plate-item').forEach(el => {
        const id = parseInt(el.dataset.plateId, 10);
        el.classList.toggle('active', id === plateId);
    });

    let plate = (allPlates || []).find(p => p.id === plateId);
    
    if (!plate) {
        console.warn('selectPlate: targa non in lista, carico dal DB...');
        fetch(`${API_BASE}/get_plates.php?days=365&sort=desc&t=${Date.now()}`)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.data) {
                    const found = data.data.find(p => p.id === plateId);
                    if (found) {
                        renderDetails(found);
                        if (typeof loadTicketData === 'function' && typeof updateFormWithTicketData === 'function') {
                            loadTicketData(plateId).then(() => {
                                updateFormWithTicketData(found);
                            });
                        }
                    } else {
                        showToast('⚠️ Targa non trovata', 'error');
                    }
                }
            })
            .catch(err => {
                console.error('Errore caricamento targa:', err);
                showToast('❌ Errore caricamento targa', 'error');
            });
        return;
    }

    renderDetails(plate);

    if (typeof loadTicketData === 'function' && typeof updateFormWithTicketData === 'function') {
        loadTicketData(plateId).then(() => {
            updateFormWithTicketData(plate);
        });
    }
}



function highlightPlateItemByPassageId(passageId) {
    document.querySelectorAll('.plate-item').forEach(el => {
        el.classList.remove('active');
        if (el.dataset.isPassage === '1' && parseInt(el.dataset.passageId, 10) === passageId) {
            el.classList.add('active');
        }
    });
}