console.log('📝 details.js caricato (dinamico e lock post-ricevuta)');
var selectedPassageId = null;
const FIELDS_ALWAYS_ENABLED = [
    'annullato', 'motivo', 'paid', 'pay_cash', 'pay_electronic'
];

// =============== UTILS FIELD STATE DYNAMIC ===============
function fieldState(id, invoiceCode) {
    return (invoiceCode && !FIELDS_ALWAYS_ENABLED.includes(id)) ? 'disabled readonly' : '';
}

function onlyEnabledData(fieldIds) {
    const data = {};
    fieldIds.forEach(id => {
        const el = document.getElementById(id);
        if (el && !el.disabled) {
            if (el.type === 'checkbox')
                data[id] = el.checked ? 1 : 0;
            else
                data[id] = el.value;
        }
    });
    return data;
}

// =============== IMMAGINE BOX ===============
function renderTargaImageBox(plate) {
    const imageBox = document.getElementById('imageBox');
    const plateText = plate.plate_corrected || plate.plate_number || '';
    const dateObj = new Date(plate.date_detected);
    const dateText = isNaN(dateObj) ? '-' : dateObj.toLocaleString('it-IT');
    const sourceText = "📸 Rilevata dalla telecamera";
    const imageUrl = plate.id ? `${API_BASE}/get_image.php?id=${plate.id}&t=${Date.now()}` : '';
    const plateImageUrl = plate.id ? `${API_BASE}/get_plate_image.php?id=${plate.id}&t=${Date.now()}` : '';
    if (!imageBox) return;

    if (imageUrl && plate.id) {
        imageBox.innerHTML = `
            <div class="image-meta">
                <div class="image-meta-plate">${plateText}</div>
                <div class="image-meta-row">
                    <span class="image-meta-label">Data rilevazione</span>
                    <span class="image-meta-value">${dateText}</span>
                </div>
                <div class="image-meta-row">
                    <span class="image-meta-label">Origine</span>
                    <span class="image-meta-value">${sourceText}</span>
                </div>
            </div>
            <div class="image-box-inner" style="margin-bottom:8px;">
                <img src="${imageUrl}" alt="${plateText} (ANPR)" onclick="openImageModal(this, '${plateText}')" />
            </div>
            <div class="image-box-inner">
                <img src="${plateImageUrl}" alt="${plateText} (targa)" onclick="openImageModal(this, '${plateText}')" />
            </div>
        `;
    } else {
        imageBox.innerHTML = `
            <div class="image-meta">
                <div class="image-meta-plate">${plateText}</div>
                <div class="image-meta-row">
                    <span class="image-meta-label">Data rilevazione</span>
                    <span class="image-meta-value">${dateText}</span>
                </div>
                <div class="image-meta-row">
                    <span class="image-meta-label">Origine</span>
                    <span class="image-meta-value">${sourceText}</span>
                </div>
            </div>
            <div class="image-box-inner">
                <span class="image-placeholder">Nessuna immagine</span>
            </div>
        `;
    }
}
// =============== RISTAMPA RICEVUTA ===============
function ristampaRicevutaTarga(invoiceCode) {
    // Eventualmente invoca una API che storicizza la stampa
    fetch(`${API_BASE}/print_invoice.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ receipt_code: invoiceCode })
    });
    window.open(`${API_BASE}/invoice/RECEIPT_${invoiceCode}.txt`, '_blank');
}
function ristampaRicevutaPassaggio(invoiceCode) {
    window.open(`${API_BASE}/invoice/RECEIPT_${invoiceCode}.txt`, '_blank');
}

// =============== RENDER DETTAGLI TARGA DINAMICO ===============
// ==== renderDetails COMPLETA - TUTTI GLI ACCORDION E FUNZIONI SMART ====
async function renderDetails(plate) {
    const detailsPanel = document.getElementById('detailsPanel');
    if (!detailsPanel) return;

    const isRilevata = Number(plate.is_manual) === 0;
    const invoiceCode = plate.invoice_code || "";

    // =========== Date / Time auto =============
    let entryDate = plate.Tentry_date || plate.entry_date || "";
    let entryTime = plate.Tentry_time || plate.entry_time || "";
    if (!entryDate || !entryTime) {
        if (plate.date_detected) {
            const detected = new Date(plate.date_detected);
            entryDate = detected.toISOString().slice(0, 10);
            entryTime = detected.toTimeString().slice(0, 5);
        }
    }
    let exitDate = plate.Texit_date || plate.exit_date || "";
    let exitTime = plate.Texit_time || plate.exit_time || "";

    // =========== Fasce Orarie ===========
    let fasceData = [];
    let fascieOptions = '';
    let globalNore = 5;
    try {
        const resFasce = await fetch(`${API_BASE}/get_fasce.php`);
        const dataFasce = await resFasce.json();
        if (dataFasce.success && Array.isArray(dataFasce.data)) {
            fasceData = dataFasce.data;
            globalNore = dataFasce.nore || 5;
        }
    } catch (e) { fasceData = []; }
    const fasciaSelected = plate.fascia || (fasceData.length > 0 ? fasceData[0].codice : 'F1');
    fascieOptions = fasceData.map(fascia => `
        <option value="${fascia.codice}" ${fascia.codice === fasciaSelected ? 'selected' : ''} 
            data-testo="${fascia.testo}" data-prezzo="${fascia.prezzo}" data-prezzo-day="${fascia.prezzo_day}" data-tolleranza="${fascia.tolleranza || 5}">
            ${fascia.codice} ${fascia.testo} - H. €${parseFloat(fascia.prezzo).toFixed(2)} - D. €${parseFloat(fascia.prezzo_day).toFixed(2)}
        </option>
    `).join('');

    // =========== Calcolo Durata ===========
    let durataGiorni = 0, durataOre = 0, durataMinuti = 0;
    if (entryDate && entryTime && exitDate && exitTime) {
        const dtIn = new Date(`${entryDate}T${entryTime}:00`);
        const dtOut = new Date(`${exitDate}T${exitTime}:00`);
        const totMin = Math.max(0, Math.floor((dtOut - dtIn) / 60000));
        durataGiorni = Math.floor(totMin / 1440);
        durataOre = Math.floor((totMin % 1440) / 60);
        durataMinuti = totMin % 60;
    }

    function fieldState(id) {
        if (["annullato", "motivo", "paid", "pay_cash", "pay_electronic"].includes(id)) return "";
        return invoiceCode ? 'disabled readonly' : '';
    }

    // ============= TEMPLATE COMPLETO =============
    detailsPanel.innerHTML = `
    <input type="hidden" id="plateNumber" value="${plate.plate_corrected || plate.plate_number || ''}">
    <!-- ENTRATA/USCITA/PAGAMENTI -->
    <div class="accordion-section open">
      <div class="accordion-header" onclick="toggleAccordion(this)">
        <span class="accordion-icon">▶</span>
        <h3>🚪 Entrata/Uscita & Pagamenti</h3>
      </div>
      <div class="accordion-content" style="display:block;">
        <div style="display:grid;grid-template-columns:2fr 2fr 2fr 2fr 2fr 2fr 2fr 2fr;gap:10px;">
          <div class="form-group"><label>DATA INGRESSO</label><input type="date" id="entryDate" value="${entryDate}" ${fieldState("entryDate")}readonly disabled></div>
          <div class="form-group"><label>ORA INGRESSO</label><input type="time" id="entryTime" value="${entryTime ? entryTime.slice(0,5) : ''}" ${fieldState("entryTime")}readonly disabled></div>
          <div class="form-group"><label>DATA USCITA</label><input type="date" id="exitDate" value="${exitDate}" ${fieldState("exitDate")}readonly disabled></div>
          <div class="form-group"><label>ORA USCITA</label><input type="time" id="exitTime" value="${exitTime}" ${fieldState("exitTime")}readonly disabled></div>
          <div class="form-group"><label>FASCIA ORARIA</label><select id="fascia" ${fieldState("fascia")}>${fascieOptions}</select></div>
          <div class="form-group"><label>Giorni</label><input type="number" id="giorni" value="${durataGiorni}" readonly></div>
          <div class="form-group"><label>Ore</label><input type="number" id="ore" value="${durataOre}" readonly></div>
          <div class="form-group"><label>Min</label><input type="number" id="minuti" value="${durataMinuti}" readonly></div>
        </div>
               <div class="dettagli-button-row">
          <label><input type="checkbox" id="paid" ${plate.Tpaid == 1 ? "checked" : ""}> PAGATO</label>
          <label><input type="checkbox" id="pay_cash" ${plate.TpayC == 1 ? "checked" : ""}> CASH</label>
          <label><input type="checkbox" id="pay_electronic" ${plate.TpayE == 1 ? "checked" : ""}> ELETTR.</label>
          <label>
            <input type="checkbox" id="annullato" ${plate.Tannullato == 1 ? "checked" : ""}> Annullato
            <input type="text" id="motivo" value="${plate.Tannultxt || ''}" maxlength="40" placeholder="Motivo annullamento" ${fieldState("motivo")}>
          </label>
          <label>Prezzo €
            <input type="number" id="prezzo" value="${plate.prezzo || ''}" step="0.01" min="0" ${fieldState("prezzo")}>
          </label>
          <button type="button" onclick="calcolaUscitaPerTarga()" class="btn-small" style="background:#667eea; color:white;" ${invoiceCode ? "disabled" : ""}>🧮 Calcola</button>
          ${!invoiceCode ? `<button type="button" onclick="emettiRicevutaTarga()" class="btn-small" style="background:#22c55e;">📄 Ricevuta</button>` : ""}
          ${invoiceCode ? `<button type="button" onclick="ristampaRicevutaTarga('${invoiceCode}')" class="btn-small" style="background:#f59e42;">🖨️ Ristampa</button>` : ""}
        </div>
      </div>
    </div>
    <!-- TICKET -->
    <div class="accordion-section">
      <div class="accordion-header" onclick="toggleAccordion(this)">
        <span class="accordion-icon">▶</span>
        <h3>💳 Ticket</h3>
      </div>
      <div class="accordion-content" style="display:none;">
        <div class="form-group"><label>Codice Ticket:</label><input type="text" id="ticketCode" value="${plate.ticket_code || ''}" maxlength="40"></div>
        <div class="form-group"><label>Info:</label><input type="text" id="ticketInfo" value="${plate.ticket_info || ''}" maxlength="50"></div>
      </div>
    </div>
    <!-- VEICOLO -->
    <div class="accordion-section">
      <div class="accordion-header" onclick="toggleAccordion(this)">
        <span class="accordion-icon">▶</span>
        <h3>🚙 Veicolo</h3>
      </div>
      <div class="accordion-content" style="display:none;">
        <div class="form-group"><label>Tipo:</label><input type="text" id="vehicleType" value="${plate.vehicle_type || ''}"></div>
        <div class="form-group"><label>Marca:</label><input type="text" id="vehicleBrand" value="${plate.vehicle_brand || ''}"></div>
        <div class="form-group"><label>Colore:</label><input type="text" id="vehicleColor" value="${plate.vehicle_color || ''}"></div>
        <div class="form-group">
          <label>Posizione:</label>
          <select id="vehiclePosition">
            <option value="" ${!plate.vehicle_position ? 'selected' : ''}>--</option>
            <option value="P-1" ${plate.vehicle_position === 'P-1' ? 'selected' : ''}>P-1</option>
            <option value="P-2" ${plate.vehicle_position === 'P-2' ? 'selected' : ''}>P-2</option>
            <option value="P-3" ${plate.vehicle_position === 'P-3' ? 'selected' : ''}>P-3</option>
            <option value="P-4" ${plate.vehicle_position === 'P-4' ? 'selected' : ''}>P-4</option>
            <option value="P-5" ${plate.vehicle_position === 'P-5' ? 'selected' : ''}>P-5</option>
            <option value="P-6" ${plate.vehicle_position === 'P-6' ? 'selected' : ''}>P-6</option>
          </select>
        </div>
      </div>
    </div>
    <!-- ABBONAMENTO -->
    <div class="accordion-section">
      <div class="accordion-header" onclick="toggleAccordion(this)">
        <span class="accordion-icon">▶</span>
        <h3>🎫 Abbonamento</h3>
      </div>
      <div class="accordion-content" style="display:none;">
        <div class="form-group">
            <label>Abbonamento:</label>
            <select id="subscription">
                <option value="" ${!plate.subscription ? 'selected' : ''}>-- Seleziona --</option>
                <option value="Mensile" ${plate.subscription === 'Mensile' ? 'selected' : ''}>Mensile</option>
                <option value="Trimestrale" ${plate.subscription === 'Trimestrale' ? 'selected' : ''}>Trimestrale</option>
                <option value="Annuale" ${plate.subscription === 'Annuale' ? 'selected' : ''}>Annuale</option>
                <option value="Giornaliero" ${plate.subscription === 'Giornaliero' ? 'selected' : ''}>Giornaliero</option>
            </select>
        </div>
        <div class="form-group"><label>Valido dal:</label><input type="date" id="subscriptionFrom" value="${plate.subscription_from || ''}"></div>
        <div class="form-group"><label>al:</label><input type="date" id="subscriptionTo" value="${plate.subscription_to || ''}"></div>
        <div id="subscriptionAlert" class="alert-warning" style="display:none;margin-top:4px;font-size:11px;color:#b91c1c;">
            ⚠️ ABBONAMENTO SCADUTO
        </div>
      </div>
    </div>
    <!-- TESSERA SCALARE -->
    <div class="accordion-section">
      <div class="accordion-header" onclick="toggleAccordion(this)">
        <span class="accordion-icon">▶</span>
        <h3>💳 Tessera a Scalare</h3>
      </div>
      <div class="accordion-content" style="display:none;">
        <div class="form-group">
          <label>
            <input type="checkbox" id="ticketPrepaid" ${plate.ticket_prepaid == 1 ? "checked" : ""}>
            Tessera a scalare attiva
          </label>
        </div>
        <div class="form-group"><label>Valida da:</label><input type="date" id="ticketFrom" value="${plate.ticket_from || ''}"></div>
        <div class="form-group"><label>al:</label><input type="date" id="ticketTo" value="${plate.ticket_to || ''}"></div>
        <div class="form-group"><label>Importo Residuo €:</label>
          <input type="number" id="ticketBalance" value="${plate.ticket_balance || '0.00'}" step="0.01" min="0" placeholder="0,00">
        </div>
        <div id="ticketPrepaidAlert" class="alert-warning" style="display:none;margin-top:4px;font-size:11px;color:#b45309;">
            ⚠️ TESSERA A SCALARE SCADUTA
        </div>
      </div>
    </div>
    <!-- VEICOLO AUTORIZZATO -->
    <div class="accordion-section">
      <div class="accordion-header" onclick="toggleAccordion(this)">
        <span class="accordion-icon">▶</span>
        <h3>🚗 Veicolo Autorizzato</h3>
      </div>
      <div class="accordion-content" style="display:none;">
        <div class="form-group">
            <label>Diritto di Passaggio:</label>
            <select id="authorizedVehicle">
                <option value="" ${!plate.authorized_vehicle ? 'selected' : ''}>-- Seleziona --</option>
                <option value="Residente" ${plate.authorized_vehicle === 'Residente' ? 'selected' : ''}>Residente</option>
                <option value="Ospite" ${plate.authorized_vehicle === 'Ospite' ? 'selected' : ''}>Ospite</option>
                <option value="Operatore" ${plate.authorized_vehicle === 'Operatore' ? 'selected' : ''}>Operatore</option>
                <option value="Accreditato" ${plate.authorized_vehicle === 'Accreditato' ? 'selected' : ''}>Accreditato</option>
                <option value="Non Autorizzato" ${plate.authorized_vehicle === 'Non Autorizzato' ? 'selected' : ''}>Non Autorizzato</option>
            </select>
        </div>
      </div>
    </div>
    <!-- NOTE -->
    <div class="accordion-section">
      <div class="accordion-header" onclick="toggleAccordion(this)">
        <span class="accordion-icon">▶</span>
        <h3>📝 Note</h3>
      </div>
      <div class="accordion-content" style="display:none;">
        <div class="form-group"><label>Testo (255 car):</label><textarea id="notes" maxlength="255">${plate.notes || ""}</textarea></div>
      </div>
    </div>
    `;

    // SEZIONE IMMAGINI META
    if (isRilevata) renderImageBoxMeta(plate, 'targa_rilevata');
    else renderImageBoxMeta(plate, 'manuale');

    setTimeout(() => {
        const annulCB = document.getElementById('annullato');
        const motivoInput = document.getElementById('motivo');
        if (annulCB && motivoInput) {
            motivoInput.disabled = !annulCB.checked;
            annulCB.addEventListener('change', function () {
                motivoInput.disabled = !annulCB.checked;
                if (!annulCB.checked) motivoInput.value = '';
            });
        }
        const cashCB = document.getElementById('pay_cash');
        const eleCB = document.getElementById('pay_electronic');
        if (cashCB && eleCB) {
            cashCB.addEventListener('change', e => { if (e.target.checked) eleCB.checked = false; });
            eleCB.addEventListener('change', e => { if (e.target.checked) cashCB.checked = false; });
        }
    }, 100);

    // --- BOTTONI SALVA/CHIUDI ---
    let buttonContainer = document.getElementById('detailsButtonContainer');
    if (!buttonContainer) {
        buttonContainer = document.createElement('div');
        buttonContainer.id = 'detailsButtonContainer';
        buttonContainer.className = 'form-buttons';
        detailsPanel.parentNode.appendChild(buttonContainer);
    }
    buttonContainer.innerHTML = `
        <button onclick="saveFinalTicket(selectedPlateId)" class="btn-save">💾 Salva</button>
        <button onclick="closeDetails()" class="btn-close">✕ Chiudi</button>
    `;
    buttonContainer.style.display = 'flex';
}

async function emitReceiptPassage() {
    const passageId = selectedPassageId; // oppure prendi il vero id selezionato!
    const price = parseFloat(document.getElementById('passagePrice')?.value || '0');
    if (!passageId || isNaN(price) || price <= 0) {
        showToast("Imposta prezzo valido e seleziona un passaggio", "error");
        return;
    }
    showToast('⏳ Emissione ricevuta...', 'info', 2000);
    try {
        const response = await fetch(`${API_BASE}/emit_receipt_passage.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ passage_id: passageId, price: price })
        });
        const result = await response.json();
        if (result.success) {
            showToast('📄 Ricevuta: ' + result.data.receipt_code, 'success', 4000);

            // PATCH: Ricarica i dati aggiornati e aggiorna la UI
            fetch(`${API_BASE}/get_passage.php?id=${passageId}&t=${Date.now()}`, {cache: 'no-store'})
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        renderPassageDetails(data.data); // aggiorna pannello
                        // Se hai funzioni per aggiornare lato/terza colonna, chiamale qui
                        // es. updateRightColumn(data.data) oppure updateRiepilogo(data.data);
                    }
                });

            // Ricarica anche la lista generale così aggiorna il colore/stato
            loadPlates();

            // (opzionale: chiudi dopo un delay se vuoi, oppure lascia aperto)
            // closeDetails();
        } else {
            showToast('❌ ' + (result.message || 'Errore emissione ricevuta passaggio'), 'error');
        }
    } catch (error) {
        showToast('❌ Errore emissione ricevuta', 'error');
    }
}

function renderPassageDetails(passage, cassa) {
    // selectedPassageId = passage.id; // ❌ PATCH: NON usare più variabile globale!
    console.log("DEBUG Dettaglio Passaggio - ID:", passage.id, passage); // PATCH: debug attivo

    const detailsPanel = document.getElementById('detailsPanel');
    if (!detailsPanel) return;

    // === Recupero fasce orarie per select ===
    let fasceData = [];
    let globalNore = 5;
    let fasciaOptions = '';
    let selectedFascia = (cassa && cassa.fascia) ? cassa.fascia : (passage.fascia || 'F1');
    try {
        const xhr = new XMLHttpRequest();
        xhr.open('GET', `${API_BASE}/get_fasce.php`, false);
        xhr.send(null);
        const fasceResponse = xhr.responseText ? JSON.parse(xhr.responseText) : {};
        if (fasceResponse.success && Array.isArray(fasceResponse.data)) {
            fasceData = fasceResponse.data;
            globalNore = fasceResponse.nore || 5;
        }
    } catch(e) {
        fasceData = [];
    }
    fasciaOptions = fasceData.map(fascia => `
        <option value="${fascia.codice}" ${fascia.codice === selectedFascia ? 'selected' : ''} 
            data-testo="${fascia.testo}" data-prezzo="${fascia.prezzo}" data-prezzo-day="${fascia.prezzo_day}" data-tolleranza="${fascia.tolleranza || 5}">
            ${fascia.codice} ${fascia.testo} - H. €${parseFloat(fascia.prezzo).toFixed(2)} - D. €${parseFloat(fascia.prezzo_day).toFixed(2)}
        </option>
    `).join('');

    // Prendi i dati di ingresso dal passaggio
    let entryDate = '', entryTime = '';
    if (passage.entry_datetime) {
        const dtIn = new Date(passage.entry_datetime);
        entryDate = dtIn.toISOString().slice(0, 10);
        entryTime = dtIn.toTimeString().slice(0, 5);
    }

    // Prendi i dati bloccati da CASSA
    const prezzoVal   = (cassa && cassa.prezzo != null) ? cassa.prezzo : '';
    const fasciaVal   = (cassa && cassa.fascia) ? cassa.fascia : '';
    const uscitaData  = (cassa && cassa.datacassa) ? cassa.datacassa : '';
    const uscitaOra   = (cassa && cassa.oraincasso) ? cassa.oraincasso : '';
    const pagato      = (cassa && parseInt(cassa.Tpaid)) === 1;
    const cash        = (cassa && parseInt(cassa.TpayC)) === 1;
    const elett       = (cassa && parseInt(cassa.TpayE)) === 1;
    const annullato   = (cassa && parseInt(cassa.Tannullato)) === 1;
    const motivo      = (cassa && cassa.Tannultxt) ? cassa.Tannultxt : '';
    const invoiceCode = cassa && cassa.invoice_code ? cassa.invoice_code : '';

    // Calcolo durata (giorni/ore/min) tra ingresso e uscita-storicizzata
    let giorni = 0, ore = 0, min = 0;
    if (passage.entry_datetime && uscitaData && uscitaOra) {
        const dtIn = new Date(passage.entry_datetime);
        const dtOut = new Date(`${uscitaData}T${uscitaOra}:00`);
        const totMin = Math.max(0, Math.floor((dtOut - dtIn) / 60000));
        giorni = Math.floor(totMin / 1440);
        ore = Math.floor((totMin % 1440) / 60);
        min = totMin % 60;
    }

    // Costruisci la UI (campi bloccati dopo ricevuta)
    function fieldLock(attr) {
        return invoiceCode && !["annullato","motivo","paid","pay_cash","pay_electronic"].includes(attr) ? 'readonly disabled' : '';
    }

    detailsPanel.innerHTML = `
    <div class="accordion-section open">
      <div class="accordion-header" onclick="toggleAccordion(this)">
        <span class="accordion-icon">▶</span>
        <h3>🚶 Dettaglio Passaggio</h3>
      </div>
      <div class="accordion-content" style="display:block;">
        <div class="passaggio-row" style="display: flex; flex-wrap: wrap;align-items: flex-end; gap:12px;">
          <div class="form-group">
            <label>DATA INGRESSO</label>
            <input type="date" id="passageEntryDate" value="${entryDate}" ${fieldLock("entryDate")}>
          </div>
          <div class="form-group">
            <label>ORA INGRESSO</label>
            <input type="time" id="passageEntryTime" value="${entryTime}" ${fieldLock("entryTime")}>
          </div>
          <div class="form-group">
            <label>DATA USCITA</label>
            <input type="date" id="passageExitDate" value="${uscitaData}" ${fieldLock("exitDate")}readonly disabled>
          </div>
          <div class="form-group">
            <label>ORA USCITA</label>
            <input type="time" id="passageExitTime" value="${uscitaOra}" ${fieldLock("exitTime")}readonly disabled>
          </div>
          <div class="form-group">
            <label>FASCIA</label>
            <select id="passageFascia" ${fieldLock("fascia")}>
                ${fasciaOptions}
            </select>
          </div>
          <div class="form-group-small">
            <label>GIORNI</label>
            <input type="number" id="durataGiorni" value="${giorni}" readonly style="width:50px">
          </div>
          <div class="form-group-small">
            <label>ORE</label>
            <input type="number" id="durataOre" value="${ore}" readonly style="width:45px">
          </div>
          <div class="form-group-small">
            <label>MIN</label>
            <input type="number" id="durataMin" value="${min}" readonly style="width:45px">
          </div>
          <div class="form-group-costo" style="min-width:110px;">
            <label>PREZZO €</label>
            <input type="number" id="passagePrice" value="${prezzoVal}" step="0.01" min="0" ${fieldLock("prezzo")}>
          </div>
          <div class="form-group" style="display:flex;flex-direction:column;">
            <label><input type="checkbox" id="paid" ${pagato ? "checked" : ""}> Pagato</label>
            <label><input type="checkbox" id="pay_cash" ${cash ? "checked" : ""}> Cash</label>
            <label><input type="checkbox" id="pay_electronic" ${elett ? "checked" : ""}> Elettr.</label>
          </div>
          <div class="form-group" style="display:flex;flex-direction:column;">
            <label><input type="checkbox" id="annullato" ${annullato ? "checked" : ""}> Annullato</label>
            <input type="text" id="motivo" value="${motivo}" maxlength="40" placeholder="Motivo annullamento" ${fieldLock("motivo")}>
          </div>
        </div>
        <div style="display:flex; gap:18px; margin:15px 0; flex-wrap:wrap;">
          ${!invoiceCode ? `<button type="button" onclick="calculatePassagePrice()" class="btn-small" style="background:#667eea;color:white;" ${invoiceCode ? "disabled" : ""}>🧮 Calcola</button>` : ""}
          ${!invoiceCode ? `<button type="button" onclick="emitReceiptPassage()" class="btn-small" style="background:#22c55e; color:white;">📄 Ricevuta</button>` : ""}
          ${invoiceCode ? `<button type="button" onclick="ristampaRicevutaPassaggio('${invoiceCode}')" class="btn-small" style="background:#f59e42; color:white;">🖨️ Ristampa</button>` : ""}
        </div>
      </div>
    </div>
    `;

    // Pulsanti fissi
    let buttonContainer = document.getElementById('detailsButtonContainer');
    if (!buttonContainer) {
        buttonContainer = document.createElement('div');
        buttonContainer.id = 'detailsButtonContainer';
        buttonContainer.className = 'form-buttons';
        detailsPanel.parentNode.appendChild(buttonContainer);
    }
    // PATCH: sostituisci selectedPassageId con passage.id diretto (sempre corretto!)
    buttonContainer.innerHTML = `
        <button onclick="savePassageDataNew(${passage.id}, '${invoiceCode}')" class="btn-save">💾 Salva</button>
        <button onclick="closeDetails()" class="btn-close">✕ Chiudi</button>
    `;
    buttonContainer.style.display = 'flex';

    // Gestione annullato/motivo
    setTimeout(() => {
        const annulCB = document.getElementById('annullato');
        const motivoInput = document.getElementById('motivo');
        if (annulCB && motivoInput) {
            motivoInput.disabled = !annulCB.checked;
            annulCB.addEventListener('change', function () {
                motivoInput.disabled = !annulCB.checked;
                if (!annulCB.checked) motivoInput.value = '';
            });
        }
        const cashCB = document.getElementById('pay_cash');
        const eleCB = document.getElementById('pay_electronic');
        if (cashCB && eleCB) {
            cashCB.addEventListener('change', e => { if (e.target.checked) eleCB.checked = false; });
            eleCB.addEventListener('change', e => { if (e.target.checked) cashCB.checked = false; });
        }
    }, 100);

    // Mostra anche box immagini/meta
    renderImageBoxMeta(passage, 'passaggio');
}

// Funzione per le immagini targa rilevata (già fornita nelle risposte precedenti).

// ========== EMISSIONE RICEVUTA TARGA =============
async function emettiRicevutaTarga() {
    const plateId = selectedPlateId;
    const entryDateEl = document.getElementById('entryDate');
    const entryTimeEl = document.getElementById('entryTime');
    const exitDateEl  = document.getElementById('exitDate');
    const exitTimeEl  = document.getElementById('exitTime');
    // PATCH: Valorizza sempre se vuoti
    const now = new Date();
    if (!entryDateEl.value) entryDateEl.value = now.toISOString().slice(0, 10);
    if (!entryTimeEl.value) entryTimeEl.value = now.toTimeString().slice(0, 5);
    if (!exitDateEl.value)  exitDateEl.value  = now.toISOString().slice(0, 10);
    if (!exitTimeEl.value)  exitTimeEl.value  = now.toTimeString().slice(0, 5);

    const price = parseFloat(document.getElementById('prezzo')?.value || '0');
    if (!plateId || isNaN(price) || price <= 0) {
        showToast("Imposta prezzo valido e seleziona una targa", "error");
        return;
    }
    showToast('⏳ Emissione ricevuta...', 'info', 2000);
    try {
        const response = await fetch(`${API_BASE}/emit_receipt_plate.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ plate_id: plateId, price: price })
        });
        const result = await response.json();
        if (result.success) {
            showToast('📄 Ricevuta: ' + result.data.receipt_code, 'success', 4000);

            // PATCH: Ricarica i dati targa
            fetch(`${API_BASE}/get_plate.php?id=${plateId}&t=${Date.now()}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) renderDetails(data.data);
                });

            loadPlates();
        } else {
            showToast('❌ ' + (result.message || 'Errore emissione ricevuta'), 'error');
        }
    } catch (error) {
        showToast('❌ Errore emissione ricevuta', 'error');
    }
}

// ========== CALCOLA PREZZO/USCITA TARGA ==========
function calcolaUscitaPerTarga() {
    // Aggiorna data e ora di uscita nei campi
    const now = new Date();
    const dateStr = now.toISOString().slice(0, 10);
    const timeStr = now.toTimeString().slice(0, 5);
    document.getElementById('exitDate').value = dateStr;
    document.getElementById('exitTime').value = timeStr;

    // Aggiorna/Salva sul DB la data/ora di uscita
    fetch(`${API_BASE}/save_ticket.php`, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        plate_id: selectedPlateId,
        exit_date: dateStr,
        exit_time: timeStr
      })
    });

    // Blocca sempre il campo per impedire modifiche successive
    document.getElementById('exitDate').disabled = true;
    document.getElementById('exitTime').disabled = true;

    // PATCH: calcola anche il prezzo subito dopo aver aggiornato l’uscita
    if (typeof calcolaPrezzoTarga === "function") {
        calcolaPrezzoTarga();
    }
}

function calcolaPrezzoTarga() {
    const fasciaSelect  = document.getElementById('fascia');
    const priceInput    = document.getElementById('prezzo');
    const entryDateEl   = document.getElementById('entryDate');
    const entryTimeEl   = document.getElementById('entryTime');
    const exitDateEl    = document.getElementById('exitDate');
    const exitTimeEl    = document.getElementById('exitTime');

    if (!fasciaSelect || !fasciaSelect.value) {
        showToast("Seleziona una fascia oraria", "warning");
        priceInput.value = "0.00";
        return;
    }
    const now         = new Date();
    const currentDate = now.toISOString().slice(0, 10);
    const currentTime = now.toTimeString().slice(0, 5);

    if (!entryDateEl.value) entryDateEl.value = currentDate;
    if (!entryTimeEl.value) entryTimeEl.value = currentTime;
    if (!exitDateEl.value)  exitDateEl.value  = currentDate;
    if (!exitTimeEl.value)  exitTimeEl.value  = currentTime;

    const entryDate = entryDateEl.value || currentDate;
    const entryTime = entryTimeEl.value || currentTime;
    const exitDate  = exitDateEl.value  || currentDate;
    const exitTime  = exitTimeEl.value  || currentTime;

    // -------- PATCH: costruisci sempre formato ISO senza spazio ----------
    function safeDate(dateVal, timeVal) {
        let t = (timeVal && timeVal.length === 5) ? `${timeVal}:00` : timeVal;
        return new Date(`${dateVal}T${t}`);
    }
    const entry = safeDate(entryDate, entryTime);
    const exit  = safeDate(exitDate, exitTime);

    // ------ Validazioni --------
    if (isNaN(entry.getTime()) || isNaN(exit.getTime())) {
        showToast("Errore formato data/ora!", "error");
        priceInput.value = "0.00";
        return;
    }
    if (entry > exit) {
        showToast("La data/ora di INGRESSO non può essere dopo l'USCITA!", "error");
        priceInput.value = "0.00";
        return;
    }

    // ------ Calcolo prezzo (come già tuo) -------
    const selectedOption   = fasciaSelect.options[fasciaSelect.selectedIndex];
    const fasciaPrezzo     = parseFloat(selectedOption.dataset.prezzo) || 0;
    const fasciaPrezzoDay  = parseFloat(selectedOption.dataset.prezzoDay) || 0;
    const tolleranza       = parseInt(selectedOption.dataset.tolleranza) || 5;
    const nore             = 5;

    const minutiTotali = Math.max(0, Math.floor((exit - entry) / 60000));
    const noreMinuti = nore * 60;
    let prezzoFinale = 0;

    if (minutiTotali < tolleranza) {
        priceInput.value = '0.00';
        showToast('Sosta troppo breve per calcolare', 'warning');
        return;
    }
    if (minutiTotali <= noreMinuti) {
        let oreArrotondate = Math.ceil(minutiTotali / 60);
        prezzoFinale = oreArrotondate * fasciaPrezzo;
    } else {
        const giorni = Math.floor(minutiTotali / (24 * 60));
        const minResidui = minutiTotali % (24 * 60);
        prezzoFinale = giorni * fasciaPrezzoDay;
        if (minResidui > 0) prezzoFinale += fasciaPrezzoDay;
    }
    priceInput.value = prezzoFinale.toFixed(2);
    showToast(`Prezzo calcolato: €${prezzoFinale.toFixed(2)}`, 'success');
}

function renderImageBoxMeta(obj, tipo = 'targa') {
    const imageBox = document.getElementById('imageBox');
    if (!imageBox) return;

    // Adatta label top
    const label = obj.plate_corrected || obj.plate_number || obj.plate || obj.label || '';
    // Ingresso/Uscita
    let ingresso = '-', uscita = '-';
    if (obj.entry_datetime) {
        ingresso = (new Date(obj.entry_datetime)).toLocaleString('it-IT');
    } else if(obj.entryDate && obj.entryTime) {
        ingresso = `${obj.entryDate} ${obj.entryTime}`;
    } else if(obj.date_detected) {
        ingresso = (new Date(obj.date_detected)).toLocaleString('it-IT');
    }

    if (obj.exit_datetime) {
        uscita = (new Date(obj.exit_datetime)).toLocaleString('it-IT');
    } else if(obj.exitDate && obj.exitTime) {
        uscita = `${obj.exitDate} ${obj.exitTime}`;
    }

    // Ticket/ricevuta
    const ticketCode = obj.ticket_code || obj.ticketCode || '-';
    const invoiceCode = obj.invoice_code || '-';

    // Calcola durata
    let durata = '-';
    try {
        let dtIn = null, dtOut = null;
        if (obj.entry_datetime && obj.exit_datetime) {
            dtIn = new Date(obj.entry_datetime); dtOut = new Date(obj.exit_datetime);
        } else if(obj.entryDate && obj.entryTime && obj.exitDate && obj.exitTime) {
            dtIn = new Date(`${obj.entryDate}T${obj.entryTime}:00`);
            dtOut = new Date(`${obj.exitDate}T${obj.exitTime}:00`);
        }
        if(dtIn && dtOut) {
            const min = Math.max(0, Math.floor((dtOut-dtIn)/60000));
            const gg = Math.floor(min / 1440);
            const hh = Math.floor((min % 1440)/60);
            const mm = min % 60;
            durata = (gg ? gg + 'g ' : '') + (hh ? hh + 'h ' : '') + mm + 'm';
        }
    } catch(e){}

    // Meta box (sempre)
        // Meta box (sempre)
    let html = `
      <div class="image-meta">
        <div class="image-meta-plate">${label}</div>
        <div class="image-meta-row"><span class="image-meta-label">Ingresso</span><span class="image-meta-value">${ingresso}</span></div>
        <div class="image-meta-row"><span class="image-meta-label">Uscita</span><span class="image-meta-value">${uscita}</span></div>
        <div class="image-meta-row"><span class="image-meta-label">Codice Ticket</span><span class="image-meta-value">${ticketCode}</span></div>
        <div class="image-meta-row"><span class="image-meta-label">Codice Ricevuta</span><span class="image-meta-value">${invoiceCode}</span></div>
        <div class="image-meta-row"><span class="image-meta-label">Durata Sosta</span><span class="image-meta-value">${durata}</span></div>
      </div>
    `;
    // Solo per targa rilevata mostra immagini
    if (tipo === 'targa_rilevata' && obj.id) {
        const imageUrl = `${API_BASE}/get_image.php?id=${obj.id}&t=${Date.now()}`;
        const plateImageUrl = `${API_BASE}/get_plate_image.php?id=${obj.id}&t=${Date.now()}`;
        html += `
          <div class="image-box-inner" style="margin-bottom:8px;">
            <img src="${imageUrl}" alt="${label} (ANPR)" onclick="openImageModal(this, '${label}')" />
          </div>
          <div class="image-box-inner">
            <img src="${plateImageUrl}" alt="${label} (targa)" onclick="openImageModal(this, '${label}')" />
          </div>
        `;
    } else {
        html += `<div class="image-box-inner"></div>`;
    }
    imageBox.innerHTML = html;
}

function calculatePassagePrice() {
    const fasciaSelect = document.getElementById('passageFascia');
    const priceInput = document.getElementById('passagePrice');
    const entryDateEl = document.getElementById('passageEntryDate');
    const entryTimeEl = document.getElementById('passageEntryTime');
    const exitDateEl = document.getElementById('passageExitDate');
    const exitTimeEl = document.getElementById('passageExitTime');
    const giorniInput = document.getElementById('durataGiorni');
    const oreInput = document.getElementById('durataOre');
    const minInput = document.getElementById('durataMin');

    if (!fasciaSelect || !fasciaSelect.value) {
        showToast("Seleziona una fascia oraria", "warning");
        priceInput.value = "0.00";
        return;
    }

    // PATCH: aggiorna DATA e ORA USCITA a questo istante
    const now = new Date();
    exitDateEl.value = now.toISOString().slice(0, 10);
    exitTimeEl.value = now.toTimeString().slice(0, 5);

    // Blocca i campi uscita
    exitDateEl.readOnly = true;
    exitDateEl.disabled = true;
    exitTimeEl.readOnly = true;
    exitTimeEl.disabled = true;

    // Valori per il calcolo
    const entryDate = entryDateEl.value;
    const entryTime = entryTimeEl.value;
    const exitDate = exitDateEl.value;
    const exitTime = exitTimeEl.value;

    // Blocca se ingresso dopo uscita
    const entry = new Date(`${entryDate}T${entryTime}:00`);
    const exit = new Date(`${exitDate}T${exitTime}:00`);
    if (entry.getTime() > exit.getTime()) {
        priceInput.value = "0.00";
        showToast("La data/ora di INGRESSO non può essere dopo l'USCITA!", "error");
        if (giorniInput) giorniInput.value = 0;
        if (oreInput) oreInput.value = 0;
        if (minInput) minInput.value = 0;
        return;
    }

    const selectedOption = fasciaSelect.options[fasciaSelect.selectedIndex];
    const fasciaPrezzo = parseFloat(selectedOption.dataset.prezzo) || 0;
    const fasciaPrezzoDay = parseFloat(selectedOption.dataset.prezzoDay) || 0;
    const tolleranza = parseInt(selectedOption.dataset.tolleranza) || 5;
    const nore = 5;

    const minutiTotali = Math.max(0, Math.floor((exit - entry) / 60000));
    const noreMinuti = nore * 60;
    let prezzoFinale = 0;

    // Aggiorna durata visualizzata
    const giorni = Math.floor(minutiTotali / 1440);
    const ore = Math.floor((minutiTotali % 1440) / 60);
    const min = minutiTotali % 60;
    if (giorniInput) giorniInput.value = giorni;
    if (oreInput) oreInput.value = ore;
    if (minInput) minInput.value = min;

    if (minutiTotali < tolleranza) {
        priceInput.value = '0.00';
        showToast('Sosta troppo breve per calcolare', 'warning');
        return;
    }
    if (minutiTotali <= noreMinuti) {
        let oreArrotondate = Math.ceil(minutiTotali / 60);
        prezzoFinale = oreArrotondate * fasciaPrezzo;
    } else {
        const giorni = Math.floor(minutiTotali / (24 * 60));
        const minResidui = minutiTotali % (24 * 60);
        prezzoFinale = giorni * fasciaPrezzoDay;
        if (minResidui > 0) prezzoFinale += fasciaPrezzoDay;
    }
    priceInput.value = prezzoFinale.toFixed(2);
    showToast(`Prezzo calcolato: €${prezzoFinale.toFixed(2)}`, 'success');
}

function calcolaUscitaTarga_Fissa() {
    const exitDateEl = document.getElementById('exitDate');
    const exitTimeEl = document.getElementById('exitTime');
    const invoiceCode = document.getElementById('invoiceCode') ? document.getElementById('invoiceCode').value : '';
    if (!invoiceCode) {
        const now = new Date();
        exitDateEl.value = now.toISOString().slice(0, 10);
        exitTimeEl.value = now.toTimeString().slice(0, 5);
    }
    // NON permettere mai la modifica manuale!
    exitDateEl.readOnly = true;
    exitTimeEl.readOnly = true;
    exitDateEl.disabled = true;
    exitTimeEl.disabled = true;
    // ... poi eventuale calcolo prezzo
}

function calculatePassagePrice() {
    const fasciaSelect = document.getElementById('passageFascia');
    const priceInput = document.getElementById('passagePrice'); // <-- deve essere uguale nel markup!
    const entryDateEl = document.getElementById('passageEntryDate');
    const entryTimeEl = document.getElementById('passageEntryTime');
    const exitDateEl = document.getElementById('passageExitDate');
    const exitTimeEl = document.getElementById('passageExitTime');
    const giorniInput = document.getElementById('durataGiorni');
    const oreInput = document.getElementById('durataOre');
    const minInput = document.getElementById('durataMin');

    if (!fasciaSelect || !fasciaSelect.value) {
        showToast("Seleziona una fascia oraria", "warning");
        if(priceInput) priceInput.value = "0.00";
        return;
    }

    // Aggiorna data/ora uscita all'adesso
    const now = new Date();
    exitDateEl.value = now.toISOString().slice(0, 10);
    exitTimeEl.value = now.toTimeString().slice(0, 5);
    exitDateEl.readOnly = true;
    exitDateEl.disabled = true;
    exitTimeEl.readOnly = true;
    exitTimeEl.disabled = true;

    // Calcolo prezzo
    const entryDate = entryDateEl.value;
    const entryTime = entryTimeEl.value;
    const exitDate = exitDateEl.value;
    const exitTime = exitTimeEl.value;

    const entry = new Date(`${entryDate}T${entryTime}:00`);
    const exit = new Date(`${exitDate}T${exitTime}:00`);
    if (entry.getTime() > exit.getTime()) {
        if(priceInput) priceInput.value = "0.00";
        showToast("La data/ora di INGRESSO non può essere dopo l'USCITA!", "error");
        if (giorniInput) giorniInput.value = 0;
        if (oreInput) oreInput.value = 0;
        if (minInput) minInput.value = 0;
        return;
    }

    const selectedOption = fasciaSelect.options[fasciaSelect.selectedIndex];
    const fasciaPrezzo = parseFloat(selectedOption.dataset.prezzo) || 0;
    const fasciaPrezzoDay = parseFloat(selectedOption.dataset.prezzoDay) || 0;
    const tolleranza = parseInt(selectedOption.dataset.tolleranza) || 5;
    const nore = 5;

    const minutiTotali = Math.max(0, Math.floor((exit - entry) / 60000));
    const noreMinuti = nore * 60;
    let prezzoFinale = 0;

    // Aggiorna durata visualizzata
    const giorni = Math.floor(minutiTotali / 1440);
    const ore = Math.floor((minutiTotali % 1440) / 60);
    const min = minutiTotali % 60;
    if (giorniInput) giorniInput.value = giorni;
    if (oreInput) oreInput.value = ore;
    if (minInput) minInput.value = min;

    if (minutiTotali < tolleranza) {
        if(priceInput) priceInput.value = '0.00';
        showToast('Sosta troppo breve per calcolare', 'warning');
        return;
    }
    if (minutiTotali <= noreMinuti) {
        let oreArrotondate = Math.ceil(minutiTotali / 60);
        prezzoFinale = oreArrotondate * fasciaPrezzo;
    } else {
        const giorni = Math.floor(minutiTotali / (24 * 60));
        const minResidui = minutiTotali % (24 * 60);
        prezzoFinale = giorni * fasciaPrezzoDay;
        if (minResidui > 0) prezzoFinale += fasciaPrezzoDay;
    }

    if(priceInput) priceInput.value = prezzoFinale.toFixed(2);
    showToast(`Prezzo calcolato: €${prezzoFinale.toFixed(2)}`, 'success');
}
function calculateExitDateTime(invoiceCodeField, exitDateField, exitTimeField) {
    const invoiceCode = invoiceCodeField && invoiceCodeField.value ? invoiceCodeField.value : "";
    if (!invoiceCode) {
        // Solo se la ricevuta NON esiste
        const now = new Date();
        exitDateField.value = now.toISOString().slice(0, 10);
        exitTimeField.value = now.toTimeString().slice(0, 5);
    }
    // Blocca sempre i campi
    exitDateField.readOnly = true;
    exitDateField.disabled = true;
    exitTimeField.readOnly = true;
    exitTimeField.disabled = true;
}

function bindCalcolaBtn() {
    // Prova a prendere tutti gli elementi necessari
    const btn              = document.getElementById("calcolaBtn");
    const invoiceCodeField = document.getElementById("invoiceCode");
    const exitDateField    = document.getElementById("exitDate");
    const exitTimeField    = document.getElementById("exitTime");

    // Se manca anche solo il bottone, esci silenziosamente
    if (!btn || !exitDateField || !exitTimeField) return;

    btn.onclick = function() {
        // Blocca SEMPRE i campi di uscita, anche prima del calcolo
        exitDateField.readOnly  = true;
        exitDateField.disabled  = true;
        exitTimeField.readOnly  = true;
        exitTimeField.disabled  = true;

        // Solo se NON esiste la ricevuta aggiorna la data/ora
        if (!invoiceCodeField || !invoiceCodeField.value) {
            const now = new Date();
            exitDateField.value = now.toISOString().slice(0, 10);
            exitTimeField.value = now.toTimeString().slice(0, 5);
        }
    };
}

async function savePassageDataNew(passageId, invoiceCode = '') {
    if (!passageId) {
        showToast('ID passaggio mancante', 'error');
        return;
    }
    // Raccogli tutti i dati dal form dei dettagli passaggio
    const getValue = id => {
        const el = document.getElementById(id);
        if (!el) return '';
        if (el.type === 'checkbox') return el.checked ? 1 : 0;
        return el.value;
    };

    const data = {
        passage_id: passageId,
        Tpaid: getValue('paid'),
        TpayC: getValue('pay_cash'),
        TpayE: getValue('pay_electronic'),
        Tannullato: getValue('annullato'),
        Tannultxt: getValue('motivo'),
        prezzo: getValue('passagePrice'),
        fascia: getValue('passageFascia'),
        entry_datetime: (getValue('passageEntryDate') && getValue('passageEntryTime')) ?
            getValue('passageEntryDate') + ' ' + getValue('passageEntryTime') + ':00' : null,
        exit_datetime: (getValue('passageExitDate') && getValue('passageExitTime')) ?
            getValue('passageExitDate') + ' ' + getValue('passageExitTime') + ':00' : null,
        info: getValue('ticketInfo'),
        note: getValue('notes')
    };
    // puoi aggiungere sopra tutti i campi che ti servono!

    showToast('⏳ Salvataggio dati passaggio...', 'info');
    try {
        const response = await fetch(`${API_BASE}/save_passage.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await response.json();
        if (result.success) {
            showToast('✅ Passaggio salvato', 'success');
            // (opzionale) aggiorna pannello/lista
            fetch(`${API_BASE}/get_passage.php?id=${passageId}&t=${Date.now()}`, { cache: 'no-store' })
                .then(r => r.json())
                .then(res => {
                    if (res.success) renderPassageDetails(res.data);
                });
            loadPlates();
        } else {
            showToast('❌ ' + (result.message || 'Errore salvataggio passaggio'), 'error');
        }
    } catch (error) {
        showToast('❌ Errore salvataggio dati passaggio', 'error');
    }
}
function renderPassageDetails(data) {
    selectedPassageId = data.id;
    const passage = data;
    const cassa = data;

    // === Recupero fasce orarie per select ===
    let fasceData = [];
    let globalNore = 5;
    let fasciaOptions = '';
    let selectedFascia = (cassa && cassa.fascia) ? cassa.fascia : (passage.fascia || 'F1');
    try {
        const xhr = new XMLHttpRequest();
        xhr.open('GET', `${API_BASE}/get_fasce.php`, false);
        xhr.send(null);
        const fasceResponse = xhr.responseText ? JSON.parse(xhr.responseText) : {};
        if (fasceResponse.success && Array.isArray(fasceResponse.data)) {
            fasceData = fasceResponse.data;
            globalNore = fasceResponse.nore || 5;
        }
    } catch (e) {
        fasceData = [];
    }
    fasciaOptions = fasceData.map(fascia => `
        <option value="${fascia.codice}" ${fascia.codice === selectedFascia ? 'selected' : ''}
            data-testo="${fascia.testo}" data-prezzo="${fascia.prezzo}" data-prezzo-day="${fascia.prezzo_day}" data-tolleranza="${fascia.tolleranza || 5}">
            ${fascia.codice} ${fascia.testo} - H. €${parseFloat(fascia.prezzo).toFixed(2)} - D. €${parseFloat(fascia.prezzo_day).toFixed(2)}
        </option>
    `).join('');

    // Prendi i dati di ingresso dal passaggio
    let entryDate = '', entryTime = '';
    if (passage.entry_datetime) {
        const dtIn = new Date(passage.entry_datetime);
        entryDate = dtIn.toISOString().slice(0, 10);
        entryTime = dtIn.toTimeString().slice(0, 5);
    }

    // Prendi i dati bloccati da CASSA
    const prezzoVal   = (cassa && cassa.prezzo != null) ? cassa.prezzo : '';
    const fasciaVal   = (cassa && cassa.fascia) ? cassa.fascia : '';
    const uscitaData  = (cassa && cassa.datacassa) ? cassa.datacassa : '';
    const uscitaOra   = (cassa && cassa.oraincasso) ? cassa.oraincasso : '';
    const pagato      = (cassa && parseInt(cassa.Ppaid)) === 1;
    const cash        = (cassa && parseInt(cassa.PpayC)) === 1;
    const elett       = (cassa && parseInt(cassa.PpayE)) === 1;
    const annullato   = (cassa && parseInt(cassa.Pannullato)) === 1;
    const motivo      = (cassa && cassa.Pannultxt) ? cassa.Pannultxt : '';
    const invoiceCode = cassa && cassa.invoice_code ? cassa.invoice_code : '';

    // Calcolo durata (giorni/ore/min) tra ingresso e uscita-storicizzata
    let giorni = 0, ore = 0, min = 0;
    if (passage.entry_datetime && uscitaData && uscitaOra) {
        const dtIn = new Date(passage.entry_datetime);
        const dtOut = new Date(`${uscitaData}T${uscitaOra}:00`);
        const totMin = Math.max(0, Math.floor((dtOut - dtIn) / 60000));
        giorni = Math.floor(totMin / 1440);
        ore = Math.floor((totMin % 1440) / 60);
        min = totMin % 60;
    }

    // Costruisci la UI (campi bloccati dopo ricevuta)
    function fieldLock(attr) {
        return invoiceCode && !["annullato","motivo","paid","pay_cash","pay_electronic"].includes(attr) ? 'readonly disabled' : '';
    }

    const detailsPanel = document.getElementById('detailsPanel');
    if (!detailsPanel) return;

    detailsPanel.innerHTML = `
    <div class="accordion-section open">
      <div class="accordion-header" onclick="toggleAccordion(this)">
        <span class="accordion-icon">▶</span>
        <h3>🚶 Dettaglio Passaggio</h3>
      </div>
      <div class="accordion-content" style="display:block;">
        <div class="passaggio-row" style="display: flex; flex-wrap: wrap;align-items: flex-end; gap:12px;">
          <div class="form-group">
            <label>DATA INGRESSO</label>
            <input type="date" id="passageEntryDate" value="${entryDate}" ${fieldLock("entryDate")}>
          </div>
          <div class="form-group">
            <label>ORA INGRESSO</label>
            <input type="time" id="passageEntryTime" value="${entryTime}" ${fieldLock("entryTime")}>
          </div>
          <div class="form-group">
            <label>DATA USCITA</label>
            <input type="date" id="passageExitDate" value="${uscitaData}" ${fieldLock("exitDate")}readonly disabled>
          </div>
          <div class="form-group">
            <label>ORA USCITA</label>
            <input type="time" id="passageExitTime" value="${uscitaOra}" ${fieldLock("exitTime")}readonly disabled>
          </div>
          <div class="form-group">
            <label>FASCIA</label>
            <select id="passageFascia" ${fieldLock("fascia")}>
                ${fasciaOptions}
            </select>
          </div>
          <div class="form-group-small">
            <label>GIORNI</label>
            <input type="number" id="durataGiorni" value="${giorni}" readonly style="width:50px">
          </div>
          <div class="form-group-small">
            <label>ORE</label>
            <input type="number" id="durataOre" value="${ore}" readonly style="width:45px">
          </div>
          <div class="form-group-small">
            <label>MIN</label>
            <input type="number" id="durataMin" value="${min}" readonly style="width:45px">
          </div>
          <div class="form-group-costo" style="min-width:110px;">
            <label>PREZZO €</label>
            <input type="number" id="passagePrice" value="${prezzoVal}" step="0.01" min="0" ${fieldLock("prezzo")}>
          </div>
          <div class="form-group" style="display:flex;flex-direction:column;">
            <label><input type="checkbox" id="paid" ${pagato ? "checked" : ""}> Pagato</label>
            <label><input type="checkbox" id="pay_cash" ${cash ? "checked" : ""}> Cash</label>
            <label><input type="checkbox" id="pay_electronic" ${elett ? "checked" : ""}> Elettr.</label>
          </div>
          <div class="form-group" style="display:flex;flex-direction:column;">
            <label><input type="checkbox" id="annullato" ${annullato ? "checked" : ""}> Annullato</label>
            <input type="text" id="motivo" value="${motivo}" maxlength="40" placeholder="Motivo annullamento" ${fieldLock("motivo")}>
          </div>
        </div>
        <div style="display:flex; gap:18px; margin:15px 0; flex-wrap:wrap;">
          ${!invoiceCode ? `<button type="button" onclick="calculatePassagePrice()" class="btn-small" style="background:#667eea;color:white;" ${invoiceCode ? "disabled" : ""}>🧮 Calcola</button>` : ""}
          ${!invoiceCode ? `<button type="button" onclick="emitReceiptPassage()" class="btn-small" style="background:#22c55e; color:white;">📄 Ricevuta</button>` : ""}
          ${invoiceCode ? `<button type="button" onclick="ristampaRicevutaPassaggio('${invoiceCode}')" class="btn-small" style="background:#f59e42; color:white;">🖨️ Ristampa</button>` : ""}
        </div>
      </div>
    </div>
    `;

    // Pulsanti fissi
    let buttonContainer = document.getElementById('detailsButtonContainer');
    if (!buttonContainer) {
        buttonContainer = document.createElement('div');
        buttonContainer.id = 'detailsButtonContainer';
        buttonContainer.className = 'form-buttons';
        detailsPanel.parentNode.appendChild(buttonContainer);
    }
    buttonContainer.innerHTML = `
        <button onclick="savePassageDataNew(selectedPassageId, '${invoiceCode}')" class="btn-save">💾 Salva</button>
        <button onclick="closeDetails()" class="btn-close">✕ Chiudi</button>
    `;
    buttonContainer.style.display = 'flex';

    // Gestione annullato/motivo
    setTimeout(() => {
        const annulCB = document.getElementById('annullato');
        const motivoInput = document.getElementById('motivo');
        if (annulCB && motivoInput) {
            motivoInput.disabled = !annulCB.checked;
            annulCB.addEventListener('change', function () {
                motivoInput.disabled = !annulCB.checked;
                if (!annulCB.checked) motivoInput.value = '';
            });
        }
        const cashCB = document.getElementById('pay_cash');
        const eleCB = document.getElementById('pay_electronic');
        if (cashCB && eleCB) {
            cashCB.addEventListener('change', e => { if (e.target.checked) eleCB.checked = false; });
            eleCB.addEventListener('change', e => { if (e.target.checked) cashCB.checked = false; });
        }
    }, 100);

    // Mostra anche box immagini/meta
    renderImageBoxMeta(passage, 'passaggio');
}

// ========== (ALTRE FUNZIONI SALVA ECC. INVARIATE) ===========

console.log('✅ details.js caricato correttamente!');