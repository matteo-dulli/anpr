console.log('🖼 details_ui.js caricato');

// ===== TOGGLE ACCORDION =====
function toggleAccordion(header) {
    const content = header.nextElementSibling;
    if (!content) return;
    
    const icon = header.querySelector('.accordion-icon');
    const isOpen = content.style.display !== 'none';
    
    content.style.display = isOpen ? 'none' : 'block';
    if (icon) {
        icon.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(90deg)';
        icon.style.transition = 'transform 0.2s ease';
    }
}

// ===== RENDER DETTAGLI TARGA (accordion + meta immagine + doppia immagine) =====
function renderDetails(plate) {
    const detailsPanel = document.getElementById('detailsPanel');
    if (!detailsPanel) return;

    const displayPlate = plate.plate_corrected || plate.plate_number || '';
    const detectedDate = new Date(plate.date_detected);

    const plateText = displayPlate || '-';
    const dateText = isNaN(detectedDate) ? '-' : detectedDate.toLocaleString('it-IT');
    const sourceText = plate.is_manual ? '✏️ Inserita manualmente' : '📸 Rilevata dalla telecamera';

    const html = `
        <!-- CAMPO TARGA NASCOSTO PER IL SALVATAGGIO -->
        <input type="hidden" id="plateNumber" value="${plateText}">

        <!-- 1. INGRESSO -->
        <div class="accordion-section">
            <div class="accordion-header" onclick="toggleAccordion(this)">
                <span class="accordion-icon">▶</span>
                <h3>🚪 Ingresso</h3>
            </div>
            <div class="accordion-content" style="display:none;">
                <div class="form-group">
                    <label>Data:</label>
                    <input type="date" id="entryDate">
                </div>
                <div class="form-group">
                    <label>Ora:</label>
                    <input type="time" id="entryTime">
                    <div class="button-group">
                        <button type="button" onclick="setEntryTimeFromDetected()">Ora rilevata</button>
                        <button type="button" onclick="setEntryTimeNow()">Adesso</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. USCITA -->
        <div class="accordion-section">
            <div class="accordion-header" onclick="toggleAccordion(this)">
                <span class="accordion-icon">▶</span>
                <h3>🚪 Uscita</h3>
            </div>
            <div class="accordion-content" style="display:none;">
                <div class="form-group">
                    <label>Data:</label>
                    <input type="date" id="exitDate">
                </div>
                <div class="form-group">
                    <label>Ora:</label>
                    <input type="time" id="exitTime">
                    <div class="button-group">
                        <button type="button" onclick="setExitTimeFromDetected()">Ora rilevata</button>
                        <button type="button" onclick="setExitTimeNow()">Adesso</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. TICKET -->
        <div class="accordion-section">
            <div class="accordion-header" onclick="toggleAccordion(this)">
                <span class="accordion-icon">▶</span>
                <h3>💳 Ticket</h3>
            </div>
            <div class="accordion-content" style="display:none;">
                <div class="form-group"><label>Info:</label><input type="text" id="ticketInfo" maxlength="50"></div>
                <div class="form-group">
                    <label>Codice Ticket:</label>
                    <input type="text" id="ticketCode" maxlength="40" placeholder="Es: TK20250412001234">
                </div>
                <div class="form-group"><label>Pagato:</label><input type="checkbox" id="paid"></div>
            </div>
        </div>

        <!-- 4. VEICOLO -->
        <div class="accordion-section">
            <div class="accordion-header" onclick="toggleAccordion(this)">
                <span class="accordion-icon">▶</span>
                <h3>🚙 Veicolo</h3>
            </div>
            <div class="accordion-content" style="display:none;">
                <div class="form-group"><label>Tipo:</label><input type="text" id="vehicleType" placeholder="Es: Auto"></div>
                <div class="form-group"><label>Marca:</label><input type="text" id="vehicleBrand" placeholder="Es: Fiat"></div>
                <div class="form-group"><label>Colore:</label><input type="text" id="vehicleColor" placeholder="Es: Rosso"></div>
                <div class="form-group">
                    <label>Posizione:</label>
                    <select id="vehiclePosition">
                        <option value="">--</option>
                        <option value="P-1">P-1</option>
                        <option value="P-2">P-2</option>
                        <option value="P-3">P-3</option>
                        <option value="P-4">P-4</option>
                        <option value="P-5">P-5</option>
                        <option value="P-6">P-6</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- 5. ABBONAMENTO -->
        <div class="accordion-section">
            <div class="accordion-header" onclick="toggleAccordion(this)">
                <span class="accordion-icon">▶</span>
                <h3>🎫 Abbonamento</h3>
            </div>
            <div class="accordion-content" style="display:none;">
                <div class="form-group">
                    <label>Abbonamento:</label>
                    <select id="subscription">
                        <option value="">-- Seleziona --</option>
                        <option value="Mensile">Mensile</option>
                        <option value="Trimestrale">Trimestrale</option>
                        <option value="Annuale">Annuale</option>
                        <option value="Giornaliero">Giornaliero</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Valido dal:</label>
                    <input type="date" id="subscriptionFrom">
                </div>
                <div class="form-group">
                    <label>al:</label>
                    <input type="date" id="subscriptionTo">
                </div>

                <div id="subscriptionAlert" class="alert-warning" style="display:none; margin-top:4px; font-size:11px; color:#b91c1c;">
                    ⚠️ ABBONAMENTO SCADUTO
                </div>
            </div>
        </div>

        <!-- 6. TESSERA A SCALARE -->
        <div class="accordion-section">
            <div class="accordion-header" onclick="toggleAccordion(this)">
                <span class="accordion-icon">▶</span>
                <h3>💳 Tessera a Scalare</h3>
            </div>
            <div class="accordion-content" style="display:none;">
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="ticketPrepaid">
                        Tessera a scalare attiva
                    </label>
                </div>
                <div class="form-group">
                    <label>Valida da:</label>
                    <input type="date" id="ticketFrom">
                </div>
                <div class="form-group">
                    <label>al:</label>
                    <input type="date" id="ticketTo">
                </div>
                <div class="form-group">
                    <label>Importo Residuo €:</label>
                    <input type="number" id="ticketBalance" value="0.00" step="0.01" min="0" placeholder="0,00">
                </div>

                <div id="ticketPrepaidAlert" class="alert-warning" style="display:none; margin-top:4px; font-size:11px; color:#b45309;">
                    ⚠️ TESSERA A SCALARE SCADUTA
                </div>
            </div>
        </div>

        <!-- 7. VEICOLO AUTORIZZATO -->
        <div class="accordion-section">
            <div class="accordion-header" onclick="toggleAccordion(this)">
                <span class="accordion-icon">▶</span>
                <h3>🚗 Veicolo Autorizzato</h3>
            </div>
            <div class="accordion-content" style="display:none;">
                <div class="form-group">
                    <label>Diritto di Passaggio:</label>
                    <select id="authorizedVehicle">
                        <option value="">-- Seleziona --</option>
                        <option value="Residente">Residente</option>
                        <option value="Ospite">Ospite</option>
                        <option value="Operatore">Operatore</option>
                        <option value="Accreditato">Accreditato</option>
                        <option value="Non Autorizzato">Non Autorizzato</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- 8. NOTE -->
        <div class="accordion-section">
            <div class="accordion-header" onclick="toggleAccordion(this)">
                <span class="accordion-icon">▶</span>
                <h3>📝 Note</h3>
            </div>
            <div class="accordion-content" style="display:none;">
                <div class="form-group"><label>Testo (255 car):</label><textarea id="notes" maxlength="255"></textarea></div>
            </div>
        </div>
    `;

    detailsPanel.innerHTML = html;

    let buttonContainer = document.getElementById('detailsButtonContainer');
    if (!buttonContainer) {
        buttonContainer = document.createElement('div');
        buttonContainer.id = 'detailsButtonContainer';
        buttonContainer.className = 'form-buttons';
        detailsPanel.parentNode.appendChild(buttonContainer);
    }
    
    const deleteButton = selectedPlateId > 0 && plate.is_manual === 1 ? `
        <button onclick="confirmDeletePlate(${selectedPlateId})" class="btn-delete">🗑️ Elimina Targa</button>
    ` : '';

    buttonContainer.innerHTML = `
        ${deleteButton}
        <button onclick="saveFinalTicket(${selectedPlateId || 0})" class="btn-save">💾 Salva</button>
        <button onclick="closeDetails()" class="btn-close">✕ Chiudi Schede Targhe</button>
    `;
    buttonContainer.style.display = 'flex';

    const imageBox = document.getElementById('imageBox');
    const imageUrl = plate.id ? `${API_BASE}/get_image.php?id=${plate.id}&t=${Date.now()}` : '';
    const plateImageUrl = plate.id ? `${API_BASE}/get_plate_image.php?id=${plate.id}&t=${Date.now()}` : '';

    if (!imageBox) {
        initSubscriptionExpiryWatcher();
        initTicketPrepaidExpiryWatcher();
        return;
    }

    if (!imageUrl) {
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
        initSubscriptionExpiryWatcher();
        initTicketPrepaidExpiryWatcher();
        return;
    }

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

        <!-- IMMAGINE CLASSICA SOPRA -->
        <div class="image-box-inner" style="margin-bottom:8px;">
            <img src="${imageUrl}" alt="${plateText} (ANPR)"
                 onclick="openImageModal(this, '${plateText}')" />
        </div>

        <!-- IMMAGINE TARGA SOTTO -->
        <div class="image-box-inner">
            <img src="${plateImageUrl}" alt="${plateText} (targa)"
                 onclick="openImageModal(this, '${plateText}')" />
        </div>
    `;

    initSubscriptionExpiryWatcher();
    initTicketPrepaidExpiryWatcher();
    
    if (plate.id && !plate.is_passage) {
        loadAndPopulateTicketCode(plate.id);
    }
}

// ===== CARICA E POPOLA TICKET CODE PER TARGHE NORMALI =====
async function loadAndPopulateTicketCode(plateId) {
    try {
        const response = await fetch(`${API_BASE}/get_tickets.php?plate_id=${plateId}`);
        const data = await response.json();
        
        if (data.success && data.data && data.data.ticket_code) {
            const ticketCodeInput = document.getElementById('ticketCode');
            if (ticketCodeInput) {
                ticketCodeInput.value = data.data.ticket_code;
                ticketCodeInput.disabled = true;
                ticketCodeInput.style.background = '#f3f4f6';
                ticketCodeInput.style.color = '#6b7280';
                ticketCodeInput.title = '❌ Ticket già emesso - Non modificabile';
            }
        }
    } catch (err) {
        console.error('loadAndPopulateTicketCode error:', err);
    }
}

// ===== RENDER DETTAGLI PASSAGGIO =====
function renderPassageDetails(passage) {
    const panel = document.getElementById('detailsPanel');
    if (!panel) return;

    console.log('🚶 renderPassageDetails:', passage);

    const dt = new Date(passage.entry_datetime || passage.date_detected);
    const dateStr = dt.toLocaleDateString('it-IT');
    const timeStr = dt.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
    
    const ticketCodeValue = passage.ticket_code || '';
    const entryDateTime = passage.entry_datetime || '';

    console.log('🎟️ ticketCodeValue nel render:', ticketCodeValue);

    panel.innerHTML = `
        <div class="details-box">
            <h3>🚶 Passaggio #${passage.id}</h3>
            <div class="form-group">
                <label>Data ingresso</label>
                <input type="text" id="passageEntryDate" value="${dateStr}" placeholder="gg/mm/aaaa">
            </div>
            <div class="form-group">
                <label>Ora ingresso</label>
                <input type="text" id="passageEntryTime" value="${timeStr}" placeholder="hh:mm">
            </div>
            <div class="form-group">
                <label>Codice Ticket</label>
                <input type="text" id="passageTicketCode" value="${ticketCodeValue}" placeholder="Es: T20260413-104705" ${ticketCodeValue ? 'readonly' : ''} style="${ticketCodeValue ? 'background:#f3f4f6; color:#6b7280;' : ''}">
            </div>
        </div>
    `;

    // ===== IMMAGINE =====
    const imageBox = document.getElementById('imageBox');
    if (imageBox) {
        imageBox.innerHTML = `
            <div class="image-meta">
                <div class="image-meta-plate">🚶 PASSAGGIO #${passage.id}</div>
                <div class="image-meta-row">
                    <span class="image-meta-label">Data ingresso</span>
                    <span class="image-meta-value">${dateStr} ${timeStr}</span>
                </div>
                <div class="image-meta-row">
                    <span class="image-meta-label">Codice Ticket</span>
                    <span class="image-meta-value">${ticketCodeValue || '-'}</span>
                </div>
                <div class="image-meta-row">
                    <span class="image-meta-label">Entry DateTime</span>
                    <span class="image-meta-value">${entryDateTime || '-'}</span>
                </div>
                <div class="image-meta-row">
                    <span class="image-meta-label">Origine</span>
                    <span class="image-meta-value">Ticket senza targa</span>
                </div>
            </div>
            <div class="image-box-inner">
                <span class="image-placeholder">Nessuna immagine per il passaggio</span>
            </div>
        `;
    }

    // ===== BOTTONI =====
    let buttonContainer = document.getElementById('detailsButtonContainer');
    if (!buttonContainer) {
        buttonContainer = document.createElement('div');
        buttonContainer.id = 'detailsButtonContainer';
        buttonContainer.className = 'form-buttons';
        const detailsSection = document.querySelector('.details-section');
        if (detailsSection) {
            detailsSection.appendChild(buttonContainer);
        }
    }

    buttonContainer.innerHTML = `
        <button onclick="deletePassageConfirm(${passage.id})" class="btn-delete">🗑️ Elimina Passaggio</button>
        <button onclick="savePassage(${passage.id})" class="btn-save">💾 Salva passaggio</button>
        <button onclick="closeDetails()" class="btn-close">✕ Chiudi</button>
    `;
    buttonContainer.style.display = 'flex';
}

// ===== SALVA PASSAGGIO =====
async function savePassage(passageId) {
    const dateEl = document.getElementById('passageEntryDate');
    const timeEl = document.getElementById('passageEntryTime');
    const noteEl = document.getElementById('passageNote');

    const date = dateEl ? dateEl.value.trim() : '';
    const time = timeEl ? timeEl.value.trim() : '';
    const note = noteEl ? noteEl.value.trim() : '';

    if (!date || !time) {
        showToast('⚠️ Inserisci data e ora di ingresso', 'error');
        return;
    }

    try {
        const resp = await fetch(`${API_BASE}/update_passage.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: passageId,
                entry_date: date,
                entry_time: time,
                note: note
            })
        });

        const res = await resp.json();
        if (!res.success) {
            showToast(res.message || '❌ Errore salvataggio passaggio', 'error', 3000);
            return;
        }

        showToast('✅ Passaggio aggiornato', 'success', 2000);
        loadPlates();

    } catch (err) {
        console.error('savePassage error:', err);
        showToast('❌ Errore salvataggio passaggio', 'error', 3000);
    }
}

// ===== ELIMINA PASSAGGIO =====
async function deletePassageConfirm(passageId) {
    if (!confirm('⚠️ Sei sicuro di voler eliminare questo passaggio?')) {
        console.log('Delete cancelled by user');
        return;
    }

    try {
        console.log('🗑️ Eliminando passaggio:', passageId);
        showToast('⏳ Eliminazione passaggio in corso...', 'info', 2000);

        const response = await fetch(`${API_BASE}/delete_passage.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: passageId })
        });

        const result = await response.json();
        console.log('✅ Delete passage response:', result);

        if (result.success) {
            showToast('🗑️ ' + result.message, 'success', 3000);

            allPlates = allPlates.filter(p => {
                return !(p.is_passage === 1 && p.passage_id === passageId);
            });
            
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
        console.error('❌ Delete passage error:', error);
        showToast('❌ Errore eliminazione passaggio', 'error', 3000);
    }
}
function renderPassageDetails(passage) {
    const detailsPanel = document.getElementById('detailsPanel');
    if (!detailsPanel) return;

    // --- IMPORTANTE: ID dei campi devono essere quelli attesi dalle funzioni di calcolo/salvataggio! ---

    // Estrai info
    const invoiceCode = passage.invoice_code || '';
    const fasciaSelected = passage.fascia || 'F1';
    const prezzo = passage.prezzo || '';
    const paid = passage.paid == 1 ? "checked" : "";
    const pay_cash = passage.pay_cash == 1 ? "checked" : "";
    const pay_electronic = passage.pay_electronic == 1 ? "checked" : "";
    const annullato = passage.annullato == 1 ? "checked" : "";
    const motivo = passage.motivo || '';

    // Date e orari
    let entryDate = '', entryTime = '', exitDate = '', exitTime = '';
    if (passage.entry_datetime) {
        const dtIn = new Date(passage.entry_datetime);
        entryDate = dtIn.toISOString().slice(0, 10);
        entryTime = dtIn.toTimeString().slice(0, 5);
    }
    if (passage.exit_datetime) {
        const dtOut = new Date(passage.exit_datetime);
        exitDate = dtOut.toISOString().slice(0, 10);
        exitTime = dtOut.toTimeString().slice(0, 5);
    }

    // --- FASCE ORARIE (Carica sincrono: se asincrono vedi nota sotto) ---
    let fasceData = window._fasceOrariePassaggi || [
        { codice: "F1", testo: "", prezzo: 2.50, prezzo_day: 15.00, tolleranza: 5 },
        { codice: "F2", testo: "Notte", prezzo: 1.50, prezzo_day: 9.00, tolleranza: 5 }
    ];
    let fascieOptions = fasceData.map(fascia => `
        <option value="${fascia.codice}" ${fascia.codice == fasciaSelected ? "selected" : ""}
            data-prezzo="${fascia.prezzo}" 
            data-prezzo-day="${fascia.prezzo_day}" 
            data-tolleranza="${fascia.tolleranza || 5}">
            ${fascia.codice} ${fascia.testo} - H. €${parseFloat(fascia.prezzo).toFixed(2)} - D. €${parseFloat(fascia.prezzo_day).toFixed(2)}
        </option>
    `).join('');

    // Calcolo durata
    let giorni = 0, ore = 0, min = 0;
    if (entryDate && entryTime && exitDate && exitTime) {
        const dtIn = new Date(`${entryDate}T${entryTime}:00`);
        const dtOut = new Date(`${exitDate}T${exitTime}:00`);
        const totMin = Math.max(0, Math.floor((dtOut - dtIn) / 60000));
        giorni = Math.floor(totMin / 1440);
        ore = Math.floor((totMin % 1440) / 60);
        min = totMin % 60;
    }

    // UI dettagliata
    detailsPanel.innerHTML = `
    <div class="details-box">
        <h3>🚶 Dettaglio Passaggio #${passage.id || ''}</h3>
        <div class="passaggio-row" style="display: flex; flex-wrap: wrap; align-items: flex-end; gap:16px;">
          <div class="form-group">
            <label>DATA INGRESSO</label>
            <input type="date" id="passageEntryDate" value="${entryDate}">
          </div>
          <div class="form-group">
            <label>ORA INGRESSO</label>
            <input type="time" id="passageEntryTime" value="${entryTime}">
          </div>
          <div class="form-group">
            <label>DATA USCITA</label>
            <input type="date" id="passageExitDate" value="${exitDate}">
          </div>
          <div class="form-group">
            <label>ORA USCITA</label>
            <input type="time" id="passageExitTime" value="${exitTime}">
          </div>
          <div class="form-group">
            <label>FASCIA ORARIA</label>
            <select id="passageFascia">${fascieOptions}</select>
          </div>
          <div class="form-group-small">
            <label>GIORNI</label>
            <input type="number" id="durataGiorni" value="${giorni}" readonly>
          </div>
          <div class="form-group-small">
            <label>ORE</label>
            <input type="number" id="durataOre" value="${ore}" readonly>
          </div>
          <div class="form-group-small">
            <label>MIN</label>
            <input type="number" id="durataMin" value="${min}" readonly>
          </div>
          <div class="form-group-costo">
            <label>PREZZO €</label>
            <input type="number" id="passagePrice" value="${prezzo}" step="0.01" min="0">
          </div>
        </div>
        <div class="passaggio-row" style="display:flex;gap:22px;margin:14px 0 6px 0;">
          <label><input type="checkbox" id="paid" ${paid}> Pagato</label>
          <label><input type="checkbox" id="pay_cash" ${pay_cash}> Cash</label>
          <label><input type="checkbox" id="pay_electronic" ${pay_electronic}> Elettr.</label>
          <label>
            <input type="checkbox" id="annullato" ${annullato}> Annullato
            <input type="text" id="motivo" value="${motivo}" maxlength="40" placeholder="Motivo annullamento" style="margin-left:7px;width:160px;">
          </label>
        </div>
        <div class="passaggio-row" style="margin:14px 0">
          <label style="margin-right: 14px;">Note: <input type="text" id="passageNote" value="${passage.note || ''}" style="width:300px;"></label>
        </div>
        <div class="passaggio-buttons-row">
          <button type="button" onclick="calculatePassagePrice()" class="btn-small btn-calc">🧮 Calcola</button>
          ${!invoiceCode ? `<button type="button" onclick="emitReceiptPassage()" class="btn-small btn-receipt" style="background:#22c55e;">📄 Ricevuta</button>` : ""}
          ${invoiceCode ? `<button type="button" onclick="reprintPassageReceipt('${invoiceCode}')" class="btn-small btn-receipt" style="background:#f59e42;">🖨️ Ristampa</button>` : ""}
        </div>
    </div>
    `;

    // --- Bottoni SALVA e CHIUDI come per targa ---
    let buttonContainer = document.getElementById('detailsButtonContainer');
    if (!buttonContainer) {
        buttonContainer = document.createElement('div');
        buttonContainer.id = 'detailsButtonContainer';
        buttonContainer.className = 'form-buttons';
        detailsPanel.parentNode.appendChild(buttonContainer);
    }
    buttonContainer.innerHTML = `
        <button onclick="savePassage(${passage.id})" class="btn-save">💾 Salva</button>
        <button onclick="closeDetails()" class="btn-close">✕ Chiudi</button>
    `;
    buttonContainer.style.display = 'flex';

    // BOX INFO/META/IMMAGINI terza colonna
    renderImageBoxMeta(passage, 'passaggio');

    // -- Gestione abilitazioni motivazione annullamento --
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
        // Mutua esclusione cash/elettr.
        const cashCB = document.getElementById('pay_cash');
        const eleCB = document.getElementById('pay_electronic');
        if (cashCB && eleCB) {
            cashCB.addEventListener('change', e => { if (e.target.checked) eleCB.checked = false; });
            eleCB.addEventListener('change', e => { if (e.target.checked) cashCB.checked = false; });
        }
    }, 100);
}

// === Per chiamare window._fasceOrariePassaggi, puoi popolarlo all’avvio con le fasce orarie dal server! ===
// Oppure, se vuoi caricarle async, chiama una funzione e re-renderizza dopo.

function reprintPassageReceipt(invoiceCode) {
    window.open(`${API_BASE}/invoice/RECEIPT_${invoiceCode}.txt`, '_blank');
}