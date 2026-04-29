console.log('📝 details.js caricato (dinamico e lock post-ricevuta)');

var selectedPassageId = null;

const FIELDS_ALWAYS_ENABLED = [
  'annullato', 'motivo', 'paid', 'pay_cash', 'pay_electronic'
];

const W = window.ANPR_THUMB_W || 320;
const Q = window.ANPR_THUMB_Q || 70;

// ✅ FIX: obj può non esistere → fallback robusti + supporto data-plate-id / data-passage-id
function getCurrentDetailsId() {
  const obj = window.obj || {};
  const detailsRoot = document.getElementById('details-root');

  const plateIdFromDom = detailsRoot?.dataset?.plateId ? parseInt(detailsRoot.dataset.plateId, 10) : null;
  const passageIdFromDom = detailsRoot?.dataset?.passageId ? parseInt(detailsRoot.dataset.passageId, 10) : null;
  const genericIdFromDom = detailsRoot?.dataset?.id ? parseInt(detailsRoot.dataset.id, 10) : null;

  // priorità: obj.id > PASSAGE > dom passage > PLATE > dom plate > dom id
  return (
    (obj && obj.id) ||
    window.PASSAGE_ID ||
    passageIdFromDom ||
    window.PLATE_ID ||
    plateIdFromDom ||
    genericIdFromDom ||
    null
  );
}

let currentId = getCurrentDetailsId();

let imageUrl = null;
if (!currentId) {
  // ⚠️ all'avvio è normale: diventa disponibile dopo click
  console.warn('details.js: ID non ancora disponibile (ok finché non selezioni una targa/passaggio).');
} else {
  imageUrl = `${API_BASE}/get_image.php?id=${currentId}&w=${W}&q=${Q}`;
}

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

function DEBUG_MOSTRA_DURATA(plate) {
    console.log('🟠 DEBUG_MOSTRA_DURATA chiamato', plate);

    // Lettura campi da DOM, fallback da plate se vuoto
    let entryDate = document.getElementById('entryDate')?.value || (plate?.entry_date || plate?.entryDate || '');
    let entryTime = document.getElementById('entryTime')?.value || (plate?.entry_time || plate?.entryTime || '');
    let exitDate  = document.getElementById('exitDate')?.value  || (plate?.exit_date || plate?.exitDate || '');
    let exitTime  = document.getElementById('exitTime')?.value  || (plate?.exit_time || plate?.exitTime || '');

    // Se chiuso (ricevuta/lockata) e hai dati DB, USA QUELLI
    let giorni = (plate && typeof plate.giorni !== 'undefined') ? plate.giorni : null;
    let ore    = (plate && typeof plate.ore    !== 'undefined') ? plate.ore    : null;
    let min    = (plate && typeof plate.min    !== 'undefined') ? plate.min    : null;

    // Se dati DB non ci sono, calcola live
    if (giorni === null || ore === null || min === null) {
        if (entryDate && entryTime && exitDate && exitTime) {
            const dtIn  = new Date(`${entryDate}T${entryTime}:00`);
            const dtOut = new Date(`${exitDate}T${exitTime}:00`);
            const totMin = Math.max(0, Math.floor((dtOut-dtIn)/60000));
            giorni = Math.floor(totMin / 1440);
            ore    = Math.floor((totMin % 1440) / 60);
            min    = totMin % 60;
        } else {
            giorni = ore = min = '-';
        }
    }

    // DEBUG PRINT/SET
    console.log('🟠 Durata (mostra):', giorni, ore, min);

    // Campi selettori
    const elG = document.getElementById('giorni'); if (elG) elG.value = giorni;
    const elO = document.getElementById('ore');    if (elO) elO.value = ore;
    const elM = document.getElementById('minuti'); if (elM) elM.value = min;
    if (elG) elG.style.background = 'orange';
    if (elO) elO.style.background = 'orange';
    if (elM) elM.style.background = 'orange';

    // Colonna durata sosta
    setTimeout(() => {
        const imageBox = document.getElementById('imageBox');
        if (imageBox) {
            const rows = imageBox.querySelectorAll('.image-meta-row');
            rows.forEach(row => {
                const label = row.querySelector('.image-meta-label');
                const val   = row.querySelector('.image-meta-value');
                if (label && val && label.textContent.toLowerCase().includes('durata sosta')) {
                    val.textContent = `${giorni}g ${ore}h ${min}m`;
                    label.style.background = 'orange';
                    val.style.background = 'orange';
                }
            });
        }
    }, 100);
}


function aggiornaDurataGarage(plate) {
  // 1) Durata da DB se disponibile (ricevuta emessa)
  let giorni = 0, ore = 0, min = 0;

  if (
    plate &&
    (plate.invoice_code || plate.ricevuta_emessa) &&
    typeof plate.giorni !== 'undefined' &&
    typeof plate.ore !== 'undefined' &&
    typeof plate.min !== 'undefined'
  ) {
    giorni = Number(plate.giorni) || 0;
    ore = Number(plate.ore) || 0;
    min = Number(plate.min) || 0;
  } else {
    // 2) Calcolo live da campi DOM
    const entryDate = document.getElementById('entryDate')?.value;
    const entryTime = document.getElementById('entryTime')?.value;
    const exitDate  = document.getElementById('exitDate')?.value;
    const exitTime  = document.getElementById('exitTime')?.value;

    if (entryDate && entryTime && exitDate && exitTime) {
      const dtIn  = new Date(`${entryDate}T${entryTime}:00`);
      const dtOut = new Date(`${exitDate}T${exitTime}:00`);
      const totMin = Math.max(0, Math.floor((dtOut - dtIn) / 60000));
      giorni = Math.floor(totMin / 1440);
      ore = Math.floor((totMin % 1440) / 60);
      min = totMin % 60;
    }
  }

  // 3) Scrivi nei selettori
  const elG = document.getElementById('giorni');
  const elO = document.getElementById('ore');
  const elM = document.getElementById('minuti');
  if (elG) elG.value = giorni;
  if (elO) elO.value = ore;
  if (elM) elM.value = min;

  // 4) Terza colonna: aggiorna "Durata Sosta" (senza selector non standard)
  setTimeout(() => {
    const imageBox = document.getElementById('imageBox');
    if (!imageBox) return;

    const rows = imageBox.querySelectorAll('.image-meta-row');
    rows.forEach(row => {
      const label = row.querySelector('.image-meta-label');
      const val   = row.querySelector('.image-meta-value');
      if (!label || !val) return;

      if (label.textContent.trim().toLowerCase() === 'durata sosta') {
        val.textContent = `${giorni}g ${ore}h ${min}m`;
      }
    });
  }, 100);
}

function calcolaDurata(entryDate, entryTime, exitDate, exitTime) {
    let giorni = 0, ore = 0, min = 0;
    if (entryDate && entryTime && exitDate && exitTime) {
        const dtIn = new Date(`${entryDate}T${entryTime}:00`);
        const dtOut = new Date(`${exitDate}T${exitTime}:00`);
        const totMin = Math.max(0, Math.floor((dtOut - dtIn) / 60000));
        giorni = Math.floor(totMin / 1440);
        ore = Math.floor((totMin % 1440) / 60);
        min = totMin % 60;
    }
    return {giorni, ore, min};
}
function aggiornaSelettoriDurataRobusta(plate) {
    const elG = document.getElementById('giorni');
    const elO = document.getElementById('ore');
    const elM = document.getElementById('minuti');
    // Se ricevuta emessa (o lock), mostra dati DB
    if (plate && plate.invoice_code && plate.giorni !== undefined) {
        if (elG) elG.value = plate.giorni;
        if (elO) elO.value = plate.ore;
        if (elM) elM.value = plate.min;
    } else {
        // Calcola live
        const entryDate = document.getElementById('entryDate')?.value;
        const entryTime = document.getElementById('entryTime')?.value;
        const exitDate  = document.getElementById('exitDate')?.value;
        const exitTime  = document.getElementById('exitTime')?.value;
        const durata = calcolaDurata(entryDate, entryTime, exitDate, exitTime);
        if (elG) elG.value = durata.giorni;
        if (elO) elO.value = durata.ore;
        if (elM) elM.value = durata.min;
    }
}
// Utility aggiorna i selettori Giorni/Ore/Minuti in modo robusto
function aggiornaSelettoriDurataFromDom() {
    setTimeout(() => {
        const entryDate = document.getElementById('entryDate')?.value;
        const entryTime = document.getElementById('entryTime')?.value;
        const exitDate  = document.getElementById('exitDate')?.value;
        const exitTime  = document.getElementById('exitTime')?.value;
        let valG = 0, valO = 0, valM = 0;
        if (entryDate && entryTime && exitDate && exitTime) {
            const dtIn  = new Date(`${entryDate}T${entryTime}:00`);
            const dtOut = new Date(`${exitDate}T${exitTime}:00`);
            const totMin = Math.max(0, Math.floor((dtOut - dtIn) / 60000));
            valG = Math.floor(totMin / 1440);
            valO = Math.floor((totMin % 1440) / 60);
            valM = totMin % 60;
        }
        const elG = document.getElementById("giorni");
        const elO = document.getElementById("ore");
        const elM = document.getElementById("minuti");
        if (elG) elG.value = isNaN(valG) ? 0 : valG;
        if (elO) elO.value = isNaN(valO) ? 0 : valO;
        if (elM) elM.value = isNaN(valM) ? 0 : valM;
    }, 50);
}

// =============== IMMAGINE BOX ===============
function renderTargaImageBox(plate) {
    const imageBox = document.getElementById('imageBox');
    const plateText = plate.plate_corrected || plate.plate_number || '';
    const dateObj = new Date(plate.date_detected);
    const dateText = isNaN(dateObj) ? '-' : dateObj.toLocaleString('it-IT');
    const sourceText = "📸 Rilevata dalla telecamera";
    const imageUrl = plate.id ? `${API_BASE}/get_image.php?id=${plate.id}&w=320&q=75` : '';
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
// =============== RISTAMPA RICEVUTA ===============
async function ristampaRicevutaTarga(invoiceCode) {
    try {
        const resp = await fetch(`${API_BASE}/reprint_receipt.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ receipt_code: invoiceCode })
        });

        const data = await resp.json();

        // ✅ Se il backend dice NO, mostra errore e STOP
        if (!data.success) {
            showToast('❌ Errore nella ristampa ricevuta: ' + (data.message || ''), 'error');
            return;
        }

        const filename = data?.data?.reprint_filename;
        if (!filename) {
            showToast('❌ Ricevuta ristampata ma filename mancante', 'error');
            return;
        }

        showToast(data.message || ('✅ Ricevuta ristampata: ' + filename), 'success');

        //// ===== VECCHIO (NON FUNZIONA: /invoice non è servito dal webserver -> 404) =====
        // window.open(`/invoice/${filename}`, '_blank');

        // ✅ NUOVO (FIX DEFINITIVO: passa sempre dal PHP download_invoice.php)
        // Se reprint_receipt.php restituisce download_url lo usiamo, altrimenti lo costruiamo.
        const downloadUrl = data?.data?.download_url
            ? data.data.download_url
            : `${API_BASE}/download_invoice.php?file=${encodeURIComponent(filename)}`;

        ///window.open(downloadUrl, '_blank');

    } catch (err) {
        showToast('❌ Errore di rete nella ristampa: ' + (err?.message || err), 'error');
    }
}

function ristampaRicevutaPassaggio(invoiceCode) {
    const file = `RECEIPT_${invoiceCode}.txt`;
    window.open(`${API_BASE}/download_invoice.php?file=${encodeURIComponent(file)}`, '_blank');
}

function ristampaRicevutaPassaggio(invoiceCode) {
    //// VECCHIO (non funzionava: usava una variabile inesistente "filename")
    // window.open(`${API_BASE}/download_invoice.php?file=${encodeURIComponent(filename)}`, '_blank');

    //// VECCHIO (apertura diretta file via /invoice: nel tuo server dava 404 Not Found)
    // window.open(`/invoice/RECEIPT_${invoiceCode}.txt`, '_blank');

    // ✅ NUOVO: ristampa via backend (così puoi passare anche passage_id per log DB)
    // e poi apri il file usando download_invoice.php (che hai verificato funzionare)
    (async () => {
        try {
            const payload = {
                receipt_code: invoiceCode,
                // PATCH: salva contesto passaggio se disponibile
                passage_id: (typeof selectedPassageId !== 'undefined' && selectedPassageId) ? selectedPassageId : null
            };

            const resp = await fetch(`${API_BASE}/reprint_receipt.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const data = await resp.json();

            if (!data.success) {
                showToast('❌ Errore nella ristampa ricevuta: ' + (data.message || ''), 'error');
                return;
            }

            const filename = data?.data?.reprint_filename;
            if (!filename) {
                showToast('❌ Ricevuta ristampata ma filename mancante', 'error');
                return;
            }

            showToast(data.message || ('✅ Ricevuta ristampata: ' + filename), 'success');

            // Usa download_url se presente (dal tuo reprint_receipt.php modificato), altrimenti fallback
            const downloadUrl = data?.data?.download_url
                ? data.data.download_url
                : `${API_BASE}/download_invoice.php?file=${encodeURIComponent(filename)}`;

          ///  window.open(downloadUrl, '_blank');
        } catch (err) {
            showToast('❌ Errore di rete nella ristampa: ' + (err?.message || err), 'error');
        }
    })();
}
// ==== FINE PATCH funzione utility ====
function getOpenSheetType() {
  const plateIdEl = document.getElementById('plateId');
  if (plateIdEl && plateIdEl.value) return 'plate';

  const passageEntryDate = document.getElementById('passageEntryDate');
  if (passageEntryDate) return 'passage';

  return 'unknown';
}

function _getTicketCodeFromCurrentPlateObjectOrDom() {
  // Provo a prendere da eventuale oggetto globale se lo hai (non sempre esiste)
  // Fallback: non abbiamo un input ticket nella scheda targa, quindi useremo solo dati backend tramite save_ticket.php
  return '';
}

async function savePlateManualPreTicketIfNeeded() {
  const plateId = parseInt(document.getElementById('plateId')?.value || '0', 10);
  const plateNumber = document.getElementById('plateNumber')?.value || '';
  const invoiceCode = document.getElementById('invoiceCodeHidden')?.value || '';
  const canEditEntry = document.getElementById('canEditEntryHidden')?.value === '1';

  if (!plateId) return { success: false, message: 'plateId mancante' };

  // se ricevuta emessa, non consentire modifiche ingresso
  // ✅ se ricevuta emessa: NON salvare ingresso manuale, ma NON bloccare gli altri moduli
if (invoiceCode) return { success: true, skipped: true };

  // solo manuali editabili
  if (!canEditEntry) return { success: true, skipped: true };

  const entryDateEl = document.getElementById('entryDate');
  const entryTimeEl = document.getElementById('entryTime');
  const entryDate = entryDateEl?.value || '';
  const entryTime = entryTimeEl?.value || '';

  if (!entryDate || !entryTime) return { success: false, message: 'Inserisci data/ora ingresso' };

  // Salva su manual_plates_log (pre-ticket)
  const resp = await fetch(`${API_BASE}/save_manual_plate.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      plate_id: plateId,
      plate_number: plateNumber,
      entry_date: entryDate,
      entry_time: entryTime
    })
  });

  const res = await resp.json();
  if (!res.success) return { success: false, message: res.message || 'Errore salvataggio ingresso (manuale)' };

  return { success: true };
}

async function savePlateToCassaIfTicketExistsSoft() {
  const plateId = parseInt(document.getElementById('plateId')?.value || '0', 10);
  if (!plateId) return { success: false, message: 'plateId mancante' };

// ✅ NEW: se non esiste ticket_code, NON chiamare save_ticket.php
  const ticketCode =
    (window.currentPlate?.ticket_code || '').toString().trim() ||
    (window.currentPlate?.ticket_code_printed || '').toString().trim() ||
    (window.currentPlate?.Tticket_code || '').toString().trim() ||
    '';

  if (!ticketCode) {
    return { success: false, soft: true, message: 'Nessun ticket associato: salvato solo ingresso (manuale)' };
  }
  const payload = {
    plate_id: plateId,
    Tpaid: document.getElementById('paid')?.checked ? 1 : 0,
    TpayC: document.getElementById('pay_cash')?.checked ? 1 : 0,
    TpayE: document.getElementById('pay_electronic')?.checked ? 1 : 0,
    Tannullato: document.getElementById('annullato')?.checked ? 1 : 0,
    Tannultxt: document.getElementById('motivo')?.value || '',
    entry_date: document.getElementById('entryDate')?.value || null,
    entry_time: document.getElementById('entryTime')?.value || null,
    exit_date: document.getElementById('exitDate')?.value || null,
    exit_time: document.getElementById('exitTime')?.value || null
  };

  const resp = await fetch(`${API_BASE}/save_ticket.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });

  const res = await resp.json();

  // se non c'è ticket, per noi è soft-fail (il salvataggio manuale pre-ticket ha già funzionato)
  if (!res.success) return { success: false, soft: true, message: res.message || 'Salvataggio cassa non eseguito' };

  return { success: true };
}




async function renderDetails(plate) {
	window.currentPlate = plate;
	// ✅ PATCH: recupera image_path dalla lista (get_plates.php) se manca nei dettagli
if (!plate.image_path && Array.isArray(window.allPlates)) {
  const fromList = window.allPlates.find(p => String(p.id) === String(plate.id));
  if (fromList && fromList.image_path) plate.image_path = fromList.image_path;
}
  // PATCH: normalizza annullato/motivo anche sulle targhe
  if (!('annullato' in plate)) {
    if ('Tannullato' in plate) plate.annullato = plate.Tannullato;
    else if ('Pannullato' in plate) plate.annullato = plate.Pannullato;
    else plate.annullato = 0;
  }
  if (!('motivo' in plate)) {
    if ('Tannultxt' in plate) plate.motivo = plate.Tannultxt;
    else if ('Pannultxt' in plate) plate.motivo = plate.Pannultxt;
    else plate.motivo = '';
  }

  console.log('[renderDetails] plate annullato:', plate.annullato, 'motivo:', plate.motivo, plate);

  const detailsPanel = document.getElementById('detailsPanel');
  if (!detailsPanel) return;

  const isRilevata = Number(plate.is_manual) === 0;
const invoiceCode = (plate.invoice_code || '').toString().trim();

// ✅ ricevuta emessa => lock vero
const hasReceipt =
  invoiceCode !== '' ||
  ((plate.invoice_exit_datetime || '').toString().trim() !== '');

// ✅ ingresso editabile SOLO per targhe manuali e SOLO se NON c'è ricevuta
const isManual = Number(plate.is_manual) === 1;
const canEditEntry = isManual && !hasReceipt;
const entryLockAttrs = canEditEntry ? '' : 'readonly disabled';

    // =========== Date / Time auto =============
  // ✅ ENTRATA:
  // - manuale: Tentry_* (cassa) -> entry_date/time (manual_plates_log / tickets)
  // - altrimenti: ticket_printed entry_datetime
  // - altrimenti: date_detected
  let entryDate = '';
let entryTime = '';

// MANUALI: usa entry inserita dall'utente (mpl o cassa)
if (isManual) {
  entryDate = plate.Tentry_date || plate.entry_date || '';
  entryTime = plate.Tentry_time || plate.entry_time || '';
}

// RILEVATE: usa ticket_printed entry_datetime
if ((!entryDate || !entryTime) && plate.entry_datetime) {
  const dt = new Date(String(plate.entry_datetime).replace(' ', 'T'));
  if (!isNaN(dt.getTime())) {
    entryDate = dt.toISOString().slice(0, 10);
    entryTime = dt.toTimeString().slice(0, 5);
  }
}

// fallback estremo
if ((!entryDate || !entryTime) && plate.date_detected) {
  const dt = new Date(String(plate.date_detected).replace(' ', 'T'));
  if (!isNaN(dt.getTime())) {
    entryDate = dt.toISOString().slice(0, 10);
    entryTime = dt.toTimeString().slice(0, 5);
  }
}

  // fallback su ticket_printed (tp.entry_datetime -> in get_plate.php è "entry_datetime")
  if ((!entryDate || !entryTime) && plate.entry_datetime) {
    const dt = new Date(String(plate.entry_datetime).replace(' ', 'T'));
    if (!isNaN(dt.getTime())) {
      entryDate = dt.toISOString().slice(0, 10);
      entryTime = dt.toTimeString().slice(0, 5);
    }
  }

  // fallback finale su rilevazione
  if (!entryDate || !entryTime) {
    if (plate.date_detected) {
      const detected = new Date(plate.date_detected);
      if (!isNaN(detected.getTime())) {
        entryDate = detected.toISOString().slice(0, 10);
        entryTime = detected.toTimeString().slice(0, 5);
      }
    }
  }

  // ✅ USCITA:
  // - se ricevuta emessa: invoice_exit_datetime
  // - altrimenti: SOLO ticket_printed exit_datetime (plate.exit_datetime)
  // - altrimenti: vuoto (NON copiare mai l'ingresso)
  let exitDate = "";
  let exitTime = "";

  if (plate.invoice_exit_datetime) {
    const dt = new Date(String(plate.invoice_exit_datetime).replace(' ', 'T'));
    if (!isNaN(dt.getTime())) {
      exitDate = dt.toISOString().slice(0, 10);
      exitTime = dt.toTimeString().slice(0, 5);
    }
  }

  // se non c'è ricevuta, usa exit_datetime del ticket (tp.exit_datetime)
  if ((!exitDate || !exitTime) && plate.exit_datetime) {
    const dt = new Date(String(plate.exit_datetime).replace(' ', 'T'));
    if (!isNaN(dt.getTime())) {
      exitDate = dt.toISOString().slice(0, 10);
      exitTime = dt.toTimeString().slice(0, 5);
    }
  }

  // ❌ IMPORTANTISSIMO: non usare più Texit_* / t.exit_date/exit_time per le targhe
  // perché ti portano a exit=entry o valori incoerenti.

  if (!exitDate && plate.Texit_date) exitDate = plate.Texit_date;
  if (!exitTime && plate.Texit_time) exitTime = String(plate.Texit_time).slice(0, 5);

  if (!exitDate && plate.exit_date) exitDate = plate.exit_date;
  if (!exitTime && plate.exit_time) exitTime = String(plate.exit_time).slice(0, 5);

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

  function fieldStateLocal(id) {
    // campi sempre editabili anche se ricevuta esiste
    if (["annullato", "motivo", "paid", "pay_cash", "pay_electronic"].includes(id)) return "";
    return invoiceCode ? 'disabled readonly' : '';
  }

  // ===================== TEMPLATE COMPLETO =====================
  detailsPanel.innerHTML = `
    <input type="hidden" id="plateId" value="${plate.id}">
    <input type="hidden" id="plateNumber" value="${plate.plate_corrected || plate.plate_number || ''}">
    <input type="hidden" id="invoiceCodeHidden" value="${invoiceCode}">
    <input type="hidden" id="canEditEntryHidden" value="${canEditEntry ? '1' : '0'}">

    <div class="accordion-section open">
      <div class="accordion-header" onclick="toggleAccordion(this)">
        <span class="accordion-icon">▶</span>
        <h3>🚪 Entrata/Uscita Veicoli</h3>
      </div>

      <div class="accordion-content" style="display:block;">
        <div style="display:grid;grid-template-columns:2fr 2fr 2fr 2fr 2fr 2fr;gap:10px;">
          
          <!-- ✅ PATCH: ingresso editabile solo su manuali senza ricevuta -->
          <div class="form-group">
            <label>DATA INGRESSO</label>
            <input type="date" id="entryDate" value="${entryDate}" ${entryLockAttrs}>
          </div>

          <div class="form-group">
            <label>ORA INGRESSO</label>
            <input type="time" id="entryTime" value="${entryTime ? String(entryTime).slice(0,5) : ''}" ${entryLockAttrs}>
          </div>

          <!-- VECCHIO: sempre bloccati (lasciato come storico)
          <div class="form-group"><label>DATA INGRESSO</label><input type="date" id="entryDate" value="${entryDate}" ${fieldStateLocal("entryDate")} readonly disabled></div>
          <div class="form-group"><label>ORA INGRESSO</label><input type="time" id="entryTime" value="${entryTime ? entryTime.slice(0,5) : ''}" ${fieldStateLocal("entryTime")} readonly disabled></div>
          -->

          <div class="form-group">
            <label>DATA USCITA</label>
            <input type="date" id="exitDate" value="${exitDate}" ${fieldStateLocal("exitDate")} readonly disabled>
          </div>

          <div class="form-group">
            <label>ORA USCITA</label>
            <input type="time" id="exitTime" value="${exitTime}" ${fieldStateLocal("exitTime")} readonly disabled>
          </div>

          <div class="form-group">
            <label>FASCIA ORARIA</label>
            <select id="fascia" ${fieldStateLocal("fascia")}>${fascieOptions}</select>
          </div>
        </div>

        <div class="dettagli-button-row">
          <label><input type="checkbox" id="paid" ${Number(plate.Tpaid) === 1 ? "checked" : ""}> PAGATO</label>
          <label><input type="checkbox" id="pay_cash" ${Number(plate.TpayC) === 1 ? "checked" : ""}> CASH</label>
          <label><input type="checkbox" id="pay_electronic" ${Number(plate.TpayE) === 1 ? "checked" : ""}> ELETTR.</label>
          <label>
            <input type="checkbox" id="annullato" ${Number(plate.annullato) === 1 ? "checked" : ""}> Annullato
            <input type="text" id="motivo" value="${plate.motivo || ''}" maxlength="40" placeholder="Motivo annullamento" ${fieldStateLocal("motivo")}>
          </label>

          <label>Prezzo €
            <input type="number" id="prezzo" value="${plate.prezzo || ''}" step="0.01" min="0" ${fieldStateLocal("prezzo")}>
          </label>

          <button type="button" onclick="calcolaUscitaPerTarga()" class="btn-small" style="background:#667eea; color:white;" ${invoiceCode ? "disabled" : ""}>🧮 Calcola</button>
          ${!invoiceCode ? `<button type="button" onclick="emettiRicevutaTarga()" class="btn-small" style="background:#22c55e;">📄 Ricevuta</button>` : ""}
          ${invoiceCode ? `<button type="button" onclick="ristampaRicevutaTarga('${invoiceCode}')" class="btn-small" style="background:#f59e42;">🖨️ Ristampa</button>` : ""}
        </div>

        <div style="display:flex;gap:18px;margin-top:12px;align-items:center;">
          <div class="form-group" style="min-width:70px;">
            <label>Giorni</label>
            <input type="number" id="giorni" value="0" readonly>
          </div>
          <div class="form-group" style="min-width:70px;">
            <label>Ore</label>
            <input type="number" id="ore" value="0" readonly>
          </div>
          <div class="form-group" style="min-width:70px;">
            <label>Min</label>
            <input type="number" id="minuti" value="0" readonly>
          </div>
        </div>
      </div>
    </div>
  `;


// ... dentro renderDetails(plate) subito DOPO detailsPanel.innerHTML = `...`;

// ✅ NEW: container moduli sotto “Entrata/Uscita & Pagamenti”
const existing = document.getElementById('plateModulesContainer');
if (!existing) {
  const modulesHtml = `
    <div id="plateModulesContainer">

      <div class="accordion-section">
        <div class="accordion-header" onclick="toggleAccordion(this)">
          <span class="accordion-icon">▶</span>
          <h3>🎫 Info Ticket</h3>
        </div>
        <div class="accordion-content" style="display:none;">
          <div id="modulo1Ticket"></div>
        </div>
      </div>

      <div class="accordion-section">
        <div class="accordion-header" onclick="toggleAccordion(this)">
          <span class="accordion-icon">▶</span>
          <h3>🚗 Dati Veicolo</h3>
        </div>
        <div class="accordion-content" style="display:none;">
          <div id="modulo2Veicolo"></div>
        </div>
      </div>

      <div class="accordion-section">
        <div class="accordion-header" onclick="toggleAccordion(this)">
          <span class="accordion-icon">▶</span>
          <h3>🪪 Abbonamento</h3>
        </div>
        <div class="accordion-content" style="display:none;">
          <div id="modulo4Abbonamento"></div>
        </div>
      </div>

      <div class="accordion-section">
        <div class="accordion-header" onclick="toggleAccordion(this)">
          <span class="accordion-icon">▶</span>
          <h3>💳 Tessera Prepagata</h3>
        </div>
        <div class="accordion-content" style="display:none;">
          <div id="modulo5Scalare"></div>
        </div>
      </div>

      <div class="accordion-section">
        <div class="accordion-header" onclick="toggleAccordion(this)">
          <span class="accordion-icon">▶</span>
          <h3>✅ Tipo di Autorizzazioni</h3>
        </div>
        <div class="accordion-content" style="display:none;">
          <div id="modulo6Autorizzazioni"></div>
        </div>
      </div>

      <div class="accordion-section">
        <div class="accordion-header" onclick="toggleAccordion(this)">
          <span class="accordion-icon">▶</span>
          <h3>🗒️ Note ingresso/uscita</h3>
        </div>
        <div class="accordion-content" style="display:none;">
          <div id="modulo3Note"></div>
        </div>
      </div>

    </div>
  `;
  // Inserisco i moduli DOPO la sezione Entrata/Uscita già renderizzata
  detailsPanel.insertAdjacentHTML('beforeend', modulesHtml);
}

// ✅ NEW: context per moduli
// ✅ NEW/CONF: nel plateCtx passiamo sempre entry_date/entry_time
const plateCtx = {
  plate_id: plate.id,
  plate_number: (plate.plate_corrected || plate.plate_number || ''),
  date_detected: plate.date_detected || null,
  entry_date: document.getElementById('entryDate')?.value || entryDate || '',
  entry_time: document.getElementById('entryTime')?.value || entryTime || '',

  // ✅ aggiungi questo
  ticket_code: plate.ticket_code || plate.ticket_code_printed || plate.Tticket_code || ''
};

// ✅ NEW: mount moduli (solo se script caricati)
setTimeout(() => {
  try {
    if (window.Modulo1 && typeof window.Modulo1.mount === 'function') {
      window.Modulo1.mount(document.getElementById('modulo1Ticket'), plateCtx);
    }
    if (window.Modulo2 && typeof window.Modulo2.mount === 'function') {
      window.Modulo2.mount(document.getElementById('modulo2Veicolo'), plateCtx);
    }
    if (window.Modulo3 && typeof window.Modulo3.mount === 'function') {
      window.Modulo3.mount(document.getElementById('modulo3Note'), plateCtx);
    }
    if (window.Modulo4 && typeof window.Modulo4.mount === 'function') {
      window.Modulo4.mount(document.getElementById('modulo4Abbonamento'), plateCtx);
    }
    if (window.Modulo5 && typeof window.Modulo5.mount === 'function') {
      window.Modulo5.mount(document.getElementById('modulo5Scalare'), plateCtx);
    }
    if (window.Modulo6 && typeof window.Modulo6.mount === 'function') {
      window.Modulo6.mount(document.getElementById('modulo6Autorizzazioni'), plateCtx);
    }
  } catch (e) {
    console.error('Errore mount moduli:', e);
  }
}, 50);


  // ==== listeners e durata
  setTimeout(() => {
    const annulCB = document.getElementById('annullato');
    const motivoInput = document.getElementById('motivo');

    if (annulCB) annulCB.checked = Number(plate.annullato) === 1;
    if (motivoInput) motivoInput.value = plate.motivo || '';

    if (Number(plate.annullato) === 1) {
      document.querySelectorAll('#detailsPanel input, #detailsPanel select, #detailsPanel textarea').forEach(el => {
        if (el.id !== 'annullato' && el.id !== 'motivo') el.disabled = true;
      });
    }

    if (annulCB && motivoInput) {
      motivoInput.disabled = !annulCB.checked;
      annulCB.addEventListener('change', function () {
        motivoInput.disabled = !annulCB.checked;
        if (!annulCB.checked) motivoInput.value = '';
      });
    }

    const cashCB = document.getElementById('pay_cash');
    const eleCB  = document.getElementById('pay_electronic');
    if (cashCB && eleCB) {
      cashCB.addEventListener('change', e => { if (e.target.checked) eleCB.checked = false; });
      eleCB.addEventListener('change', e => { if (e.target.checked) cashCB.checked = false; });
    }

    // ✅ PATCH: se manuale e editabile, quando cambia ingresso aggiorna durata live
    const canEdit = document.getElementById('canEditEntryHidden')?.value === '1';
    if (canEdit) {
      const entryDateEl = document.getElementById('entryDate');
      const entryTimeEl = document.getElementById('entryTime');
      const onEntryChange = () => {
        if (typeof aggiornaSelettoriDurataFromDom === 'function') aggiornaSelettoriDurataFromDom();
      };
      if (entryDateEl) entryDateEl.addEventListener('change', onEntryChange);
      if (entryTimeEl) entryTimeEl.addEventListener('change', onEntryChange);
    }

    if (typeof initSubscriptionExpiryWatcher === 'function') initSubscriptionExpiryWatcher();
    if (typeof initTicketPrepaidExpiryWatcher === 'function') initTicketPrepaidExpiryWatcher();

    if (typeof aggiornaSelettoriDurataFromDom === 'function') {
      aggiornaSelettoriDurataFromDom();
    }
  }, 100);

  // bottoni salva/chiudi (se li gestisci fuori, lascia pure invariato)
  let buttonContainer = document.getElementById('detailsButtonContainer');
  if (!buttonContainer) {
    buttonContainer = document.createElement('div');
    buttonContainer.id = 'detailsButtonContainer';
    buttonContainer.className = 'form-buttons';
    detailsPanel.parentNode.appendChild(buttonContainer);
  }

// OLD: salvava “final ticket” e poteva richiedere ticket_code
// buttonContainer.innerHTML = `
//   <button onclick="saveFinalTicket(parseInt(document.getElementById('plateId')?.value || '0', 10))" class="btn-save">💾 Salva</button>
//   <button onclick="closeDetails()" class="btn-close">✕ Chiudi</button>
// `;

// NEW: un solo salvataggio intelligente in base alla scheda aperta
buttonContainer.innerHTML = `
  <button onclick="handleSave()" class="btn-save">💾 Salva</button>
  <button onclick="closeDetails()" class="btn-close">✕ Chiudi</button>
`;
  buttonContainer.style.display = 'flex';

  // terza colonna
  if (typeof renderImageBoxMeta === 'function') {
    if (isRilevata) renderImageBoxMeta(plate, 'targa_rilevata');
    else renderImageBoxMeta(plate, 'manuale');
  }
}



// ⏩ PATCH FONDAMENTALE: aggiorna anche dopo ognI CALCOLO PREZZO/AZIONE TEMPO

///function calcolaPrezzoTarga() {
    // ...tuo codice di calcolo attuale invariato...

    // PATCH: aggiorna i selettori immediatamente dopo il calcolo!
 ////   aggiornaSelettoriDurataFromDom();
////}
// ================== FINE PATCHED RENDERDETAILS ===================

async function emitReceiptPassage() {
  const passageId = selectedPassageId;
  const price = parseFloat(document.getElementById('passagePrice')?.value || '0');

  // REQ.1: blocco UI se annullato
  if (document.getElementById('annullato')?.checked) {
    showToast('❌ Passaggio annullato: non puoi emettere ricevuta', 'error', 4000);
    return;
  }

  if (!passageId || Number.isNaN(price) || price <= 0) {
    showToast("Imposta prezzo valido e seleziona un passaggio", "error");
    return;
  }

  const payload = {
    passage_id: passageId,
    price: price,
    Tpaid: document.getElementById('paid')?.checked ? 1 : 0,
    TpayC: document.getElementById('pay_cash')?.checked ? 1 : 0,
    TpayE: document.getElementById('pay_electronic')?.checked ? 1 : 0,
    Tannullato: document.getElementById('annullato')?.checked ? 1 : 0,
    Tannultxt: document.getElementById('motivo')?.value || ''
  };

  showToast('⏳ Emissione ricevuta...', 'info', 2000);

  try {
    const response = await fetch(`${API_BASE}/emit_receipt_passage.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    const result = await response.json();

    if (result.success) {
      showToast('📄 Ricevuta: ' + result.data.receipt_code, 'success', 4000);

      fetch(`${API_BASE}/get_passage.php?id=${passageId}&t=${Date.now()}`, { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            if (typeof window.renderPassageDetails === 'function') window.renderPassageDetails(data.data);
          }
        });

      loadPlates();
    } else {
      showToast('❌ ' + (result.message || 'Errore emissione ricevuta passaggio'), 'error');
    }
  } catch (error) {
    showToast('❌ Errore emissione ricevuta', 'error');
  }
}

async function emitReceiptPassageById(passageId) {
  const price = parseFloat(document.getElementById('passagePrice')?.value || '0');

  if (!passageId || Number.isNaN(price) || price <= 0) {
    showToast("Imposta prezzo valido e seleziona un passaggio", "error");
    return;
  }

  showToast('⏳ Emissione ricevuta...', 'info', 2000);

  try {
    const response = await fetch(`${API_BASE}/emit_receipt_passage.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ passage_id: passageId, price })
    });

    const result = await response.json();

    if (result.success) {
      showToast('📄 Ricevuta: ' + result.data.receipt_code, 'success', 4000);

      // ricarica dettaglio passaggio
      fetch(`${API_BASE}/get_passage.php?id=${passageId}&t=${Date.now()}`, { cache: 'no-store' })
        .then(r => r.json())
        .then(data => { if (data.success) renderPassageDetails(data.data); });

      loadPlates();
    } else {
      showToast('❌ ' + (result.message || 'Errore emissione ricevuta passaggio'), 'error');
    }
  } catch (err) {
    showToast('❌ Errore emissione ricevuta', 'error');
  }
}

// ================== PATCHED RENDERPASSAGEDETAILS (PASSAGGI) ===================
function renderPassageDetails_OLD(passage, cassa) {
    // PATCH 1: normalizza annullato/motivo a inizio funzione per assicurarti consistenza
    if (!('annullato' in passage)) {
        if ('Pannullato' in passage) passage.annullato = passage.Pannullato;
        else if ('Tannullato' in passage) passage.annullato = passage.Tannullato;
    }
    if (!('motivo' in passage)) {
        if ('Pannultxt' in passage) passage.motivo = passage.Pannultxt;
        else if ('Tannultxt' in passage) passage.motivo = passage.Tannultxt;
    }

    console.log("DEBUG Dettaglio Passaggio - ID:", passage.id, passage);

    const detailsPanel = document.getElementById('detailsPanel');
    if (!detailsPanel) return;

    // (resto del codice identico)

    // PATCH 2: usa sempre i valori normalizzati anche nel template
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
            <input type="date" id="passageEntryDate" value="${entryDate}" readonly disabled>
          </div>
          <div class="form-group">
            <label>ORA INGRESSO</label>
            <input type="time" id="passageEntryTime" value="${entryTime}" readonly disabled>

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
            <label><input type="checkbox" id="annullato" ${passage.annullato === 1 ? "checked" : ""}> Annullato</label>
            <input type="text" id="motivo" value="${passage.motivo || ''}" maxlength="40" placeholder="Motivo annullamento" ${fieldLock("motivo")}>
          </div>
        </div>
        <div style="display:flex; gap:18px; margin:15px 0; flex-wrap:wrap;">
          ${!invoiceCode ? `<button type="button" onclick="calculatePassagePrice()" class="btn-small" style="background:#667eea;color:white;" ${invoiceCode ? "disabled" : ""}>🧮 Calcola</button>` : ""}
          ${!invoiceCode ? `<button type="button" onclick="emitReceiptPassageById(selectedPassageId)" class="btn-small" style="background:#22c55e; color:white;">📄 Ricevuta</button>` : ""}
          ${invoiceCode ? `<button type="button" onclick="ristampaRicevutaPassaggio('${invoiceCode}')" class="btn-small" style="background:#f59e42; color:white;">🖨️ Ristampa</button>` : ""}
        </div>
      </div>
    </div>
    `;

    let buttonContainer = document.getElementById('detailsButtonContainer');
    if (!buttonContainer) {
        buttonContainer = document.createElement('div');
        buttonContainer.id = 'detailsButtonContainer';
        buttonContainer.className = 'form-buttons';
        detailsPanel.parentNode.appendChild(buttonContainer);
    }
    buttonContainer.innerHTML = `
        <button onclick="savePassageDataNew(${passage.id}, '${invoiceCode}')" class="btn-save">💾 Salva</button>
        <button onclick="closeDetails()" class="btn-close">✕ Chiudi</button>
    `;
    buttonContainer.style.display = 'flex';

    setTimeout(() => {
        const annulCB = document.getElementById('annullato');
        const motivoInput = document.getElementById('motivo');
        // PATCH: sempre normalizzati
        if (annulCB) annulCB.checked = passage.annullato === 1;
        if (motivoInput) motivoInput.value = passage.motivo || '';
        if (passage.annullato === 1) {
            document.querySelectorAll('#detailsPanel input, #detailsPanel select, #detailsPanel textarea').forEach(el => {
                if (el.id !== 'annullato' && el.id !== 'motivo') el.disabled = true;
            });
        }
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

    renderImageBoxMeta(passage, 'passaggio');
}

// ===== PATCH 1C: calcola uscita + durata + prezzo, ma prima salva ingresso SOLO manuali =====

// helper parse
function _parseDT(dateStr, timeStr) {
  if (!dateStr || !timeStr) return null;
  const t = (String(timeStr).length === 5) ? `${timeStr}:00` : String(timeStr);
  const d = new Date(`${dateStr}T${t}`);
  return isNaN(d.getTime()) ? null : d;
}

// salva ingresso SOLO se manuale editabile
async function saveManualEntryIfEnabled() {
  const canEditEntry = document.getElementById('canEditEntryHidden')?.value === '1';
  if (!canEditEntry) return { success: true, skipped: true };

  const plateId = parseInt(document.getElementById('plateId')?.value || '0', 10);
  const plateNumber = document.getElementById('plateNumber')?.value || '';
  const entryDate = document.getElementById('entryDate')?.value || '';
  const entryTime = document.getElementById('entryTime')?.value || '';

  if (!plateId) return { success: false, message: 'plateId mancante' };
  if (!entryDate || !entryTime) return { success: false, message: 'Data/ora ingresso mancante' };

  // se esiste uscita (dopo calcolo precedente), valida
  const exitDate = document.getElementById('exitDate')?.value || '';
  const exitTime = document.getElementById('exitTime')?.value || '';
  const dtIn = _parseDT(entryDate, entryTime);
  const dtOut = (exitDate && exitTime) ? _parseDT(exitDate, exitTime) : null;

  if (!dtIn) return { success: false, message: 'Formato ingresso non valido' };
  if (dtOut && dtIn > dtOut) return { success: false, message: 'Ingresso non può essere dopo uscita' };

  // =========================
  // VECCHIO (NON USARE): update_ticket.php è troppo "large" e può causare side effect
  // =========================
  /*
  const resp = await fetch(`${API_BASE}/update_ticket.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      plate_id: plateId,
      plate_number: plateNumber,
      entry_date: entryDate,
      entry_time: entryTime,
      Tentry_date: entryDate,
      Tentry_time: entryTime
    })
  });
  const res = await resp.json();
  if (!res.success) return { success: false, message: res.message || 'Errore salvataggio ingresso' };
  return { success: true };
  */

  // ✅ NUOVO: endpoint dedicato e safe (solo manuali)
  const resp = await fetch(`${API_BASE}/save_manual_entry.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      plate_id: plateId,
      plate_number: plateNumber, // opzionale, utile per log
      entry_date: entryDate,
      entry_time: entryTime
    })
  });

  let res = null;
  try {
    res = await resp.json();
  } catch (e) {
    return { success: false, message: 'Risposta non valida dal server (JSON)' };
  }

  if (!res.success) return { success: false, message: res.message || 'Errore salvataggio ingresso' };

  return { success: true };
}

// ⚠️ NON cancelliamo nulla: se avevi già un window.calcolaUscitaPerTarga,
// lo preserviamo come "vecchio" per storico.
const _OLD_calcolaUscitaPerTarga = window.calcolaUscitaPerTarga;

// ================== COMPAT CALCOLO (NON RIMUOVERE) ==================
// Alcune versioni chiamavano calcolaUscita() o calcola().
// Se non esistono, mappiamo su funzioni presenti (modulo1..6) o su compute interno.
window.calcola = window.calcola || null;
window.calcolaUscita = window.calcolaUscita || null;

// prova a trovare una funzione di calcolo esistente in altri moduli
function resolveCalcFn() {
  return (
    window.calcolaUscita ||
    window.calcola ||
    window.computeExitForPlate ||       // se esiste in qualche modulo
    window.computeUscita ||             // idem
    window.moduloCalcolaUscita ||       // idem
    null
  );
}


// =====================================================
// REQ.2 - Sync annullamento ricevuta su invoices_printed
// Da chiamare dopo un salvataggio (targa o passaggio) se invoice_code esiste.
// =====================================================
async function syncInvoiceAnnullamentoIfNeeded(receiptCode) {
  const rc = (receiptCode || '').trim();
  if (!rc) return { success: true, skipped: true };

  try {
    const payload = {
      receipt_code: rc,
      Tannullato: document.getElementById('annullato')?.checked ? 1 : 0,
      Tannultxt: document.getElementById('motivo')?.value || ''
    };

    const resp = await fetch(`${API_BASE}/set_invoice_annullamento.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    const j = await resp.json();

    if (!j.success) {
      // non blocco il salvataggio principale: aviso soltanto
      showToast('⚠️ ' + (j.message || 'Errore sync ricevuta'), 'warning', 4500);
      return { success: false, message: j.message || 'Errore sync ricevuta' };
    }

    return { success: true };
  } catch (e) {
    showToast('⚠️ Errore sync ricevuta', 'warning', 4500);
    return { success: false, message: String(e?.message || e) };
  }
}

function calcolaUscitaTarga_Fissa() {
  const exitDateEl = document.getElementById('exitDate');
  const exitTimeEl = document.getElementById('exitTime');
  const entryDateEl = document.getElementById('entryDate');
  const entryTimeEl = document.getElementById('entryTime');

  // Se mancano i campi, esci (non può calcolare)
  if (!exitDateEl || !exitTimeEl || !entryDateEl || !entryTimeEl) {
    console.warn('[calcolaUscitaTarga_Fissa] campi entry/exit non trovati');
    return;
  }

  const invoiceCode = document.getElementById('invoiceCodeHidden')?.value || '';

  // 1) Se NON c'è ricevuta, imposta uscita = adesso
  if (!invoiceCode) {
    const now = new Date();
    exitDateEl.value = now.toISOString().slice(0, 10);
    exitTimeEl.value = now.toTimeString().slice(0, 5);
  }

  // 2) Blocca sempre i campi uscita
  exitDateEl.readOnly = true;
  exitTimeEl.readOnly = true;
  exitDateEl.disabled = true;
  exitTimeEl.disabled = true;

  // 3) Aggiorna durata (giorni/ore/minuti)
  if (typeof aggiornaSelettoriDurataFromDom === 'function') {
    aggiornaSelettoriDurataFromDom();
  }
}

function calcolaPrezzoTarga() {
    // ============================================================
    // ✅ GUARDRAIL: evita doppio toast se la funzione viene chiamata
    // 2 volte in pochi ms (es. wrapper + funzione fissa).
    // - NON blocca il calcolo
    // - blocca SOLO il toast duplicato
    // ============================================================
    window.__lastPrezzoToastTs = window.__lastPrezzoToastTs || 0;
    const __nowTs = Date.now();
    const __canToast = (__nowTs - window.__lastPrezzoToastTs) >= 400;

    const fasciaSelect  = document.getElementById('fascia');
    const priceInput    = document.getElementById('prezzo');
    const entryDateEl   = document.getElementById('entryDate');
    const entryTimeEl   = document.getElementById('entryTime');
    const exitDateEl    = document.getElementById('exitDate');
    const exitTimeEl    = document.getElementById('exitTime');

    if (!fasciaSelect || !fasciaSelect.value) {
        if (priceInput) priceInput.value = "0.00";
        if (__canToast) {
            window.__lastPrezzoToastTs = __nowTs;
            showToast("Seleziona una fascia oraria", "warning");
        }
        return;
    }

    const now         = new Date();
    const currentDate = now.toISOString().slice(0, 10);
    const currentTime = now.toTimeString().slice(0, 5);

    if (entryDateEl && !entryDateEl.value) entryDateEl.value = currentDate;
    if (entryTimeEl && !entryTimeEl.value) entryTimeEl.value = currentTime;
    if (exitDateEl && !exitDateEl.value)   exitDateEl.value  = currentDate;
    if (exitTimeEl && !exitTimeEl.value)   exitTimeEl.value  = currentTime;

    const entryDate = entryDateEl?.value || currentDate;
    const entryTime = entryTimeEl?.value || currentTime;
    const exitDate  = exitDateEl?.value  || currentDate;
    const exitTime  = exitTimeEl?.value  || currentTime;

    // -------- PATCH: costruisci sempre formato ISO senza spazio ----------
    function safeDate(dateVal, timeVal) {
        let t = (timeVal && timeVal.length === 5) ? `${timeVal}:00` : timeVal;
        return new Date(`${dateVal}T${t}`);
    }

    const entry = safeDate(entryDate, entryTime);
    const exit  = safeDate(exitDate, exitTime);

    // ------ Validazioni --------
    if (isNaN(entry.getTime()) || isNaN(exit.getTime())) {
        if (priceInput) priceInput.value = "0.00";
        if (__canToast) {
            window.__lastPrezzoToastTs = __nowTs;
            showToast("Errore formato data/ora!", "error");
        }
        return;
    }

    if (entry > exit) {
        if (priceInput) priceInput.value = "0.00";
        if (__canToast) {
            window.__lastPrezzoToastTs = __nowTs;
            showToast("La data/ora di INGRESSO non può essere dopo l'USCITA!", "error");
        }
        return;
    }

// ------ Calcolo prezzo (CORRETTO: regola tolleranza + scatti orari + Nore + day + extra oltre 24h) -------

const selectedOption  = fasciaSelect.options[fasciaSelect.selectedIndex];
const fasciaPrezzo    = parseFloat(selectedOption.dataset.prezzo) || 0;       // €/ora
const fasciaPrezzoDay = parseFloat(selectedOption.dataset.prezzoDay) || 0;    // €/giorno (24h)
const tolleranza      = parseInt(selectedOption.dataset.tolleranza, 10) || 5; // minuti

// ✅ Nore: usa quello globale da get_fasce.php se disponibile, altrimenti 5
const nore = (typeof globalNore !== 'undefined' && Number(globalNore) > 0) ? Number(globalNore) : 5;

const minutiTotali = Math.max(0, Math.floor((exit - entry) / 60000));
let prezzoFinale = 0;

// ✅ sotto tolleranza: non calcolare
if (minutiTotali < tolleranza) {
  if (priceInput) priceInput.value = '0.00';
  if (__canToast) {
    window.__lastPrezzoToastTs = __nowTs;
    showToast('Sosta troppo breve per calcolare', 'warning');
  }
  return;
}

// ✅ helper: ore a scatti con tolleranza (come specifica)
// - < tolleranza => 0
// - tra tolleranza e 59m => 1 ora
// - se superi h ore + tolleranza => h+1 (arrotonda in eccesso)
// - se sei entro h ore + tolleranza => h (arrotonda in difetto)
function oreConTolleranza(minuti, tollMin) {
  minuti = Math.max(0, Math.floor(Number(minuti) || 0));
  tollMin = Math.max(0, Math.floor(Number(tollMin) || 0));

  if (minuti < tollMin) return 0;

  const h = Math.floor(minuti / 60);
  const rem = minuti % 60;

  if (h === 0) return 1;                 // >= tolleranza e < 60 => 1 ora
  if (rem > tollMin) return h + 1;       // supera h ore + tolleranza => scatto
  return h;                               // entro tolleranza => resta all'ora precedente
}

// ✅ prezzo per un residuo < 24h:
// - ore(scatti) <= Nore => ore * prezzoOra
// - ore(scatti) > Nore  => prezzoDay (giorno pieno)
function prezzoResiduoGiornaliero(minutiResidui) {
  if (minutiResidui < tolleranza) return 0;

  const ore = oreConTolleranza(minutiResidui, tolleranza);

  if (ore > 0 && ore <= nore) return ore * fasciaPrezzo;

  // se superi Nore, scatta giorno pieno (entro le 24h)
  return fasciaPrezzoDay;
}

// ✅ spezza in giorni completi (24h) + residuo e applica la regola anche oltre 24h
const minutiGiorno = 24 * 60;
const giorniInteri = Math.floor(minutiTotali / minutiGiorno);
const residuo = minutiTotali % minutiGiorno;

// base: giorni interi * day
prezzoFinale = giorniInteri * fasciaPrezzoDay;

// aggiungi residuo (che può diventare: 0, ore, oppure un altro day)
prezzoFinale += prezzoResiduoGiornaliero(residuo);

if (priceInput) priceInput.value = prezzoFinale.toFixed(2);

// ✅ aggiorna durata subito dopo calcolo prezzo (se funzione esiste)
if (typeof aggiornaSelettoriDurataFromDom === 'function') {
  aggiornaSelettoriDurataFromDom();
}

// ✅ Toast UNA sola volta
if (__canToast) {
  window.__lastPrezzoToastTs = __nowTs;
  showToast(`Prezzo calcolato: €${prezzoFinale.toFixed(2)}`, 'success');
}

    // ============================================================
    // VECCHIO/NOTE (NON CANCELLARE)
    // Se avevi una versione "wrapper" che chiamava solo
    // aggiornaSelettoriDurataFromDom(), lasciala commentata:
    // ============================================================
    /*
    // ⏩ PATCH FONDAMENTALE: aggiorna anche dopo ogni calcolo prezzo/azione tempo
    // function calcolaPrezzoTarga() {
    //     // ...tuo codice di calcolo attuale invariato...
    //     aggiornaSelettoriDurataFromDom();
    // }
    */
}

function renderImageBoxMeta(obj,tipo='targa'){
const imageBox=document.getElementById('imageBox');
if(!imageBox)return;
console.log('➡️ OGGETTO META:',obj);
const label=obj.plate_corrected||obj.plate_number||obj.plate||obj.label||'';
const _parseSqlDT=(s)=>{if(!s)return null;const dt=new Date(String(s).replace(' ','T'));return isNaN(dt.getTime())?null:dt;};
const _fmtIt=(dt)=>(dt?dt.toLocaleString('it-IT'):'-');
const passageId=(tipo==='passaggio'&&obj&&obj.id!=null)?obj.id:null;

let ingresso='-';
if(obj.entry_datetime){ingresso=_fmtIt(_parseSqlDT(obj.entry_datetime));}
else if(obj.entryDate&&obj.entryTime){ingresso=`${obj.entryDate} ${obj.entryTime}`;}
else if(obj.date_detected){ingresso=_fmtIt(_parseSqlDT(obj.date_detected));}

let uscita='-';
const dtPexit=_parseSqlDT(obj.Pexit_datetime);
if(dtPexit){uscita=_fmtIt(dtPexit);}
else{
const dtInvExit=_parseSqlDT(obj.invoice_exit_datetime);
if(dtInvExit){uscita=_fmtIt(dtInvExit);}
else{
const d=obj.datacassa?String(obj.datacassa).slice(0,10):'';
const t=obj.oraincasso?String(obj.oraincasso).slice(0,5):'';
if(d&&t){uscita=`${d} ${t}`;}
else{
const dtExit=_parseSqlDT(obj.exit_datetime);
if(dtExit)uscita=_fmtIt(dtExit);
else if(obj.exitDate&&obj.exitTime)uscita=`${obj.exitDate} ${obj.exitTime}`;
}
}
}

const priceRaw=(obj&&obj.invoice_price!=null&&String(obj.invoice_price).trim()!=='')?obj.invoice_price:(obj&&obj.prezzo!=null&&String(obj.prezzo).trim()!=='')?obj.prezzo:'';
let priceText='-';
if(priceRaw!==''){const n=Number(priceRaw);priceText=isNaN(n)?String(priceRaw):`€${n.toFixed(2)}`;}

const ticketCode=obj.ticket_code||obj.ticketCode||'-';
const invoiceCode=obj.invoice_code||'-';

let durata='-';
let giorni=Number(obj.giorni),ore=Number(obj.ore),min=Number(obj.minuti);
if(!isNaN(giorni)&&!isNaN(ore)&&!isNaN(min)&&giorni!==null&&ore!==null&&min!==null){durata=`${giorni}g ${ore}h ${min}m`;}
else{
try{
let dtIn=null,dtOut=null;
if(obj.entry_datetime)dtIn=_parseSqlDT(obj.entry_datetime);
else if(obj.entryDate&&obj.entryTime)dtIn=_parseSqlDT(`${obj.entryDate} ${obj.entryTime}:00`);
else if(obj.date_detected)dtIn=_parseSqlDT(obj.date_detected);
dtOut=_parseSqlDT(obj.Pexit_datetime)||_parseSqlDT(obj.invoice_exit_datetime)||(obj.datacassa&&obj.oraincasso?_parseSqlDT(`${String(obj.datacassa).slice(0,10)} ${String(obj.oraincasso).slice(0,5)}:00`):null)||_parseSqlDT(obj.exit_datetime);
if(!dtOut)dtOut=new Date();
if(dtIn&&dtOut&&!isNaN(dtIn.getTime())&&!isNaN(dtOut.getTime())){
const minTot=Math.max(0,Math.floor((dtOut-dtIn)/60000));
const giornilive=Math.floor(minTot/1440);
const orelive=Math.floor((minTot%1440)/60);
const minlive=minTot%60;
durata=`${giornilive}g ${orelive}h ${minlive}m`;
}
}catch(e){}
}

let html=`
<div class="image-meta">
<div class="image-meta-plate">${label}</div>
<div class="image-meta-row"><span class="image-meta-label">Ingresso</span><span class="image-meta-value">${ingresso}</span></div>
<div class="image-meta-row"><span class="image-meta-label">Uscita</span><span class="image-meta-value">${uscita}</span></div>
${passageId!=null?`<div class="image-meta-row"><span class="image-meta-label">ID Passaggio</span><span class="image-meta-value">#${passageId}</span></div>`:''}
<div class="image-meta-row"><span class="image-meta-label">Codice Ticket</span><span class="image-meta-value">${ticketCode}</span></div>
<div class="image-meta-row"><span class="image-meta-label">Codice Ricevuta</span><span class="image-meta-value">${invoiceCode}</span></div>
<div class="image-meta-row"><span class="image-meta-label">Durata Sosta</span><span class="image-meta-value">${durata}</span></div>
<div class="image-meta-row"><span class="image-meta-label">Costo Sosta</span><span class="image-meta-value">${priceText}</span></div>
</div>
`;

if (tipo === 'targa_rilevata' && obj.id) {
  let imagePath = obj.image_path || null;
///let imagePath = obj.image_path || null;

// ✅ NEW: fallback dall'array images (get_plate.php)
if (!imagePath && Array.isArray(obj.images) && obj.images.length > 0) {
  imagePath = obj.images[0].image_path || null;
}
  // fallback: recupera image_path dalla lista caricata (get_plates.php)
  if (!imagePath && Array.isArray(window.allPlates)) {
    const fromList = window.allPlates.find(p => String(p.id) === String(obj.id));
    if (fromList && fromList.image_path) imagePath = fromList.image_path;
  }

  const thumbUrl = imagePath
    ? `${API_BASE}/get_image.php?path=${encodeURIComponent(imagePath)}&w=320&q=75`
    : `${API_BASE}/get_image.php?id=${obj.id}&w=320&q=75`;

  // URL grande per il popup (w=0 = originale)
  const fullUrl = imagePath
    ? `${API_BASE}/get_image.php?path=${encodeURIComponent(imagePath)}&w=0`
    : `${API_BASE}/get_image.php?id=${obj.id}&w=0`;

  const plateImageUrl = `${API_BASE}/get_plate_image.php?id=${obj.id}`;

  html += `
    <div class="image-box-inner" style="margin-bottom:8px;">
      <img src="${thumbUrl}" alt="${label} (ANPR)"
           onclick="openImageModalUrl('${fullUrl}', '${label}')" />
    </div>
    <div class="image-box-inner">
      <img src="${plateImageUrl}" alt="${label} (targa)"
           onclick="openImageModal(this, '${label}')" />
    </div>
  `;
} else {
  html += `<div class="image-box-inner"></div>`;
}

// ✅ FIX: applica l'HTML e chiudi correttamente la funzione
imageBox.innerHTML = html;
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

// Questa funzione ora si aspetta in ingresso l'oggetto passage completo, non solo l'id!
// Esempio chiamata: savePassageDataNew(passage);

async function savePassageDataNew(passage, invoiceCode = '') {
    // PATCH: fallback — se viene passato solo un numero/id, prova a recuperare passage dalla lista globale (da cambiare con la tua variabile o array in uso)
    // ⚠️ Sostituisci window.passagesList con il nome reale del tuo array di passaggi!!!
    if ((!passage || !passage.id) && typeof passage === "number" && window.passagesList) {
        // Modifica: ora recupero l'oggetto passage dalla lista, se è stato passato solo l'id
        passage = window.passagesList.find(p => p.id == passage);
    }

    // PATCH: messaggio di errore se non riesco a trovare l'oggetto
    if (!passage || !passage.id) {
        showToast('ID passaggio mancante', 'error');
        // Modifica: ora la funzione prova sempre a recuperare passage, abortisce solo se fallisce
        return;
    }

    // PATCH: valorizza Pticket_code robustamente
    // --- VECCHIO CODICE, LASCIARE COME COMMENTO PER STORIA ---
    // const Pticket_code = document.getElementById('ticketCode')?.value ?? '';
    // ---------------------------------------------------------
    // Modifica: ora priorità a passage.ticket_code, poi ticket_code_printed, poi campo manuale
    const Pticket_code =
        (passage.ticket_code && passage.ticket_code !== '') ? passage.ticket_code :
        (passage.ticket_code_printed && passage.ticket_code_printed !== '') ? passage.ticket_code_printed :
        (document.getElementById('ticketCode')?.value ?? '');

    // ==========================
    // ✅ PATCH DEFINITIVA: evita " :00" e datetime invalidi
    // - Se data o ora sono vuote -> null
    // - Se ora è HH:MM -> HH:MM:SS
    // ==========================
    function buildDateTime(dateId, timeId) {
        const d = document.getElementById(dateId)?.value || '';
        const t = document.getElementById(timeId)?.value || '';
        if (!d || !t) return null;
        const tt = (t.length === 5) ? `${t}:00` : t; // HH:MM -> HH:MM:SS
        return `${d} ${tt}`;
    }

    const data = {
        passage_id: passage.id,
        Ppaid:        document.getElementById('paid')?.checked ? 1 : 0,
        PpayC:        document.getElementById('pay_cash')?.checked ? 1 : 0,
        PpayE:        document.getElementById('pay_electronic')?.checked ? 1 : 0,
        Pannullato:   document.getElementById('annullato')?.checked ? 1 : 0,
        Pannultxt:    document.getElementById('motivo')?.value ?? '',
        invoice_price: document.getElementById('passagePrice')?.value ?? '',
        fascia:       document.getElementById('passageFascia')?.value ?? '',
        Pticket_code: Pticket_code, // PATCH: ora è sempre valorizzato!

        // ✅ PATCH: datetime robusti (mai " :00")
        entry_datetime: buildDateTime('passageEntryDate', 'passageEntryTime'),
        exit_datetime:  buildDateTime('passageExitDate',  'passageExitTime'),

        info: document.getElementById('ticketInfo')?.value ?? '',
        note: document.getElementById('notes')?.value ?? ''
    };

    // PATCH: log di debug per controllo dati effettivi inviati
    console.log("DEBUG PAYLOAD:", data);
    console.log("DEBUG entry_datetime:", JSON.stringify(data.entry_datetime));
    console.log("DEBUG exit_datetime:", JSON.stringify(data.exit_datetime));

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

            // PATCH: aggiorna i dettagli dopo salvataggio
            fetch(`${API_BASE}/get_passage.php?id=${passage.id}&t=${Date.now()}`, { cache: 'no-store' })
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


// ================== RENDER PASSAGGIO (UFFICIALE) ==================
// Compatibile con chiamata a 1 parametro (data dal backend con join cassa)
// o 2 parametri (passage, cassa) se in futuro separi le sorgenti.
window.renderPassageDetails = function renderPassageDetails(data, cassaMaybe) {
  // normalizza annullato/motivo
  if (!('annullato' in data)) {
    if ('Pannullato' in data) data.annullato = data.Pannullato;
    else if ('Tannullato' in data) data.annullato = data.Tannullato;
    else data.annullato = 0;
  }
  if (!('motivo' in data)) {
    if ('Pannultxt' in data) data.motivo = data.Pannultxt;
    else if ('Tannultxt' in data) data.motivo = data.Tannultxt;
    else data.motivo = '';
  }

  window.currentPassage = data;
  selectedPassageId = data.id;

  const passage = data;
  const cassa = cassaMaybe || data;

  console.log("DEBUG Dettaglio Passaggio - ID:", passage.id, passage);

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
  } catch (e) {
    fasceData = [];
  }

  fasciaOptions = fasceData.map(fascia => `
    <option value="${fascia.codice}" ${fascia.codice === selectedFascia ? 'selected' : ''} 
      data-testo="${fascia.testo}" data-prezzo="${fascia.prezzo}" data-prezzo-day="${fascia.prezzo_day}" data-tolleranza="${fascia.tolleranza || 5}">
      ${fascia.codice} ${fascia.testo} - H. €${parseFloat(fascia.prezzo).toFixed(2)} - D. €${parseFloat(fascia.prezzo_day).toFixed(2)}
    </option>
  `).join('');

  // ===== Helper parse datetime robusto =====
  const _parseSqlDT = (s) => {
    if (!s) return null;
    const dt = new Date(String(s).replace(' ', 'T'));
    return isNaN(dt.getTime()) ? null : dt;
  };

  // Ingresso dal passaggio
  let entryDate = '', entryTime = '';
  const dtIn = _parseSqlDT(passage.entry_datetime);
  if (dtIn) {
    entryDate = dtIn.toISOString().slice(0, 10);
    entryTime = dtIn.toTimeString().slice(0, 5);
  }

  // ===== USCITA: priorità Pexit_datetime -> invoice_exit_datetime -> datacassa/oraincasso -> passage.exit_datetime
  let uscitaData = '';
  let uscitaOra  = '';

  // 1) Pexit_datetime (CASSA) ✅
  const dtPexit = _parseSqlDT(cassa.Pexit_datetime);
  if (dtPexit) {
    uscitaData = dtPexit.toISOString().slice(0, 10);
    uscitaOra  = dtPexit.toTimeString().slice(0, 5);
  }

  // 2) invoice_exit_datetime
  if (!uscitaData || !uscitaOra) {
    const dtInvExit = _parseSqlDT(cassa.invoice_exit_datetime);
    if (dtInvExit) {
      uscitaData = dtInvExit.toISOString().slice(0, 10);
      uscitaOra  = dtInvExit.toTimeString().slice(0, 5);
    }
  }

  // 3) datacassa/oraincasso
  if (!uscitaData && cassa.datacassa) uscitaData = String(cassa.datacassa).slice(0, 10);
  if (!uscitaOra && cassa.oraincasso) uscitaOra = String(cassa.oraincasso).slice(0, 5);

  // 4) exit_datetime sul passaggio
  if ((!uscitaData || !uscitaOra) && passage.exit_datetime) {
    const dtOut = _parseSqlDT(passage.exit_datetime);
    if (dtOut) {
      if (!uscitaData) uscitaData = dtOut.toISOString().slice(0, 10);
      if (!uscitaOra) uscitaOra = dtOut.toTimeString().slice(0, 5);
    }
  }

  // Dati bloccati da CASSA
  const prezzoVal =
    (cassa && cassa.invoice_price != null) ? cassa.invoice_price :
    (cassa && cassa.prezzo != null) ? cassa.prezzo :
    '';

  const pagato = Number(cassa?.Ppaid || 0) === 1;
  const cash   = Number(cassa?.PpayC || 0) === 1;
  const elett  = Number(cassa?.PpayE || 0) === 1;
  const invoiceCode = (cassa && cassa.invoice_code) ? String(cassa.invoice_code).trim() : '';

  // Durata (giorni/ore/min) tra ingresso e uscita “visibile”
  let giorni = 0, ore = 0, min = 0;
  if (dtIn && uscitaData && uscitaOra) {
    const dtOutCalc = new Date(`${uscitaData}T${uscitaOra}:00`);
    if (!isNaN(dtOutCalc.getTime())) {
      const totMin = Math.max(0, Math.floor((dtOutCalc - dtIn) / 60000));
      giorni = Math.floor(totMin / 1440);
      ore = Math.floor((totMin % 1440) / 60);
      min = totMin % 60;
    }
  }

  function fieldLock(attr) {
    return invoiceCode && !["annullato","motivo","paid","pay_cash","pay_electronic"].includes(attr)
      ? 'readonly disabled'
      : '';
  }

  detailsPanel.innerHTML = `
    <div class="accordion-section open">
      <div class="accordion-header" onclick="toggleAccordion(this)">
        <span class="accordion-icon">▶</span>
        <h3>🚶 Dettaglio Passaggio</h3>
      </div>
      <div class="accordion-content" style="display:block;">
        <div class="passaggio-row" style="display:flex; flex-wrap:wrap; align-items:flex-end; gap:12px;">
          <div class="form-group">
            <label>DATA INGRESSO</label>
            <input type="date" id="passageEntryDate" value="${entryDate}" readonly disabled>
          </div>
          <div class="form-group">
            <label>ORA INGRESSO</label>
            <input type="time" id="passageEntryTime" value="${entryTime}" readonly disabled>
          </div>

          <div class="form-group">
            <label>DATA USCITA</label>
            <input type="date" id="passageExitDate" value="${uscitaData}" ${fieldLock("exitDate")} readonly disabled>
          </div>
          <div class="form-group">
            <label>ORA USCITA</label>
            <input type="time" id="passageExitTime" value="${uscitaOra}" ${fieldLock("exitTime")} readonly disabled>
          </div>

          <div class="form-group">
            <label>FASCIA</label>
            <select id="passageFascia" ${fieldLock("fascia")} >
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

          <div class="pay-annull-row">
            <div class="pay-group">
              <label><input type="checkbox" id="paid" ${pagato ? "checked" : ""}> Pagato</label>
              <label><input type="checkbox" id="pay_cash" ${cash ? "checked" : ""}> Cash</label>
              <label><input type="checkbox" id="pay_electronic" ${elett ? "checked" : ""}> Elettr.</label>
            </div>

            <div class="annull-group">
              <label style="margin:0;">
                <input type="checkbox" id="annullato" ${Number(passage.annullato) === 1 ? "checked" : ""}> Annullato
              </label>
              <input type="text" id="motivo" value="${passage.motivo || ''}" maxlength="40"
                placeholder="Motivo annullamento" ${fieldLock("motivo")}>
            </div>
          </div>
        </div>

        <div style="display:flex; gap:18px; margin:15px 0; flex-wrap:wrap;">
          ${!invoiceCode ? `<button type="button" onclick="calculatePassagePrice()" class="btn-small" style="background:#667eea;color:white;">🧮 Calcola</button>` : ""}
          ${!invoiceCode ? `<button type="button" onclick="emitReceiptPassage()" class="btn-small" style="background:#22c55e; color:white;">Ricevuta</button>` : ""}
          ${invoiceCode ? `<button type="button" onclick="ristampaRicevutaPassaggio('${invoiceCode}')" class="btn-small" style="background:#f59e42; color:white;">🖨️ Ristampa</button>` : ""}
        </div>
      </div>
    </div>
  `;

  let buttonContainer = document.getElementById('detailsButtonContainer');
  if (!buttonContainer) {
    buttonContainer = document.createElement('div');
    buttonContainer.id = 'detailsButtonContainer';
    buttonContainer.className = 'form-buttons';
    detailsPanel.parentNode.appendChild(buttonContainer);
  }

  buttonContainer.innerHTML = `
    <button onclick="savePassageDataNew(window.currentPassage, '${invoiceCode}')" class="btn-save">💾 Salva</button>
    <button onclick="closeDetails()" class="btn-close">✕ Chiudi</button>
  `;
  buttonContainer.style.display = 'flex';

  setTimeout(() => {
    const annulCB = document.getElementById('annullato');
    const motivoInput = document.getElementById('motivo');

    if (annulCB) annulCB.checked = Number(passage.annullato) === 1;
    if (motivoInput) motivoInput.value = passage.motivo || '';

    if (Number(passage.annullato) === 1) {
      document.querySelectorAll('#detailsPanel input, #detailsPanel select, #detailsPanel textarea').forEach(el => {
        if (el.id !== 'annullato' && el.id !== 'motivo') el.disabled = true;
      });
    }

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

  renderImageBoxMeta(passage, 'passaggio');
};

// ================== SALVA STATO TARGA ==================
// PATCH: funzione salva su cassa inclusi Pticket_code, annullato, motivo, pagato
async function savePlateStatus({
    plateId,
    isPaid,
    isAnnullato,
    motivoAnnullamento,
    pticket_code
}) {
    const payload = {
        plate_id: plateId,
        Tannullato: isAnnullato ? 1 : 0,
        Tannultxt: motivoAnnullamento || "",
        Tpaid: isPaid ? 1 : 0,
        Pticket_code: pticket_code || ""
    };

    try {
        const response = await fetch('/anpr/api/save_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const result = await response.json();

        if (!result.success) {
            showToast(result.message || "❌ Errore nel salvataggio stato targa", "error");
        } else {
            showToast("✅ Stato targa aggiornato!", "success");
            // Aggiorna la lista subito dopo
            loadPlates(() => selectPlate(plateId));
        }
    } catch (err) {
        console.error("Errore salvataggio stato targa:", err);
        showToast("❌ Errore di rete durante il salvataggio", "error");
    }
}
//// EXPORT GLOBALI (puliti e safe) per onclick inline

// calcolaUscitaPerTarga DEVE restare il wrapper sopra (non sovrascriverlo più)
if (typeof window.calcolaUscitaPerTarga !== 'function' && typeof calcolaUscitaPerTarga === 'function') {
  window.calcolaUscitaPerTarga = calcolaUscitaPerTarga;
}

// altre funzioni usate dagli onclick inline
if (typeof window.emettiRicevutaTarga !== 'function' && typeof emettiRicevutaTarga === 'function') {
  window.emettiRicevutaTarga = emettiRicevutaTarga;
}
if (typeof window.ristampaRicevutaTarga !== 'function' && typeof ristampaRicevutaTarga === 'function') {
  window.ristampaRicevutaTarga = ristampaRicevutaTarga;
}
if (typeof window.calcolaPrezzoTarga !== 'function' && typeof calcolaPrezzoTarga === 'function') {
  window.calcolaPrezzoTarga = calcolaPrezzoTarga;
}
if (typeof window.closeDetails !== 'function' && typeof closeDetails === 'function') {
  window.closeDetails = closeDetails;
}
if (typeof window.handleSave !== 'function' && typeof handleSave === 'function') {
  window.handleSave = handleSave;
}
// ✅ COMPAT: alias per vecchi riferimenti
// Non cancelliamo nulla: se qualche pezzo di codice chiama ancora savePlateEntryIfEditable(),
// lo facciamo puntare alla nuova logica.
if (typeof window.savePlateEntryIfEditable !== 'function') {
  window.savePlateEntryIfEditable = async function () {
    if (typeof savePlateManualPreTicketIfNeeded === 'function') {
      return savePlateManualPreTicketIfNeeded();
    }
    return { success: false, message: 'savePlateManualPreTicketIfNeeded non definita' };
  };
}
// ✅ COMPAT: alias per vecchi riferimenti (cassa)
// Se qualche codice chiama ancora savePlateCassaStatusIfPossible(), lo reindirizziamo.
// ✅ COMPAT + SOFT FAIL: cassa richiede ticket, ma Salva deve funzionare anche senza ticket
window.savePlateCassaStatusIfPossible = async function () {
  if (typeof savePlateToCassaIfTicketExistsSoft === 'function') {
    const res = await savePlateToCassaIfTicketExistsSoft();

    // se non c'è ticket, NON bloccare: soft-fail
    if (res && res.success === false) {
      return { success: false, soft: true, message: res.message || 'Salvataggio cassa non eseguito' };
    }

    return res;
  }

  return { success: false, soft: true, message: 'savePlateToCassaIfTicketExistsSoft non definita' };
};

// =====================================================
// ✅ NEW: SALVATAGGIO MODULI TARGA (richiamato dal Salva principale)
// - Non blocca il salvataggio principale se fallisce: mostra warning.
// - Modulo2: Veicolo (plates)
// - Modulo3: Note evento (tickets_printed.note)
// - Modulo4: Abbonamento (abbonamenti)
// - Modulo5: Tessera (tesserapre) SOLO DATI/STATO (NO ricarica / NO nuova tessera)
// - Modulo6: Autorizzazioni (plates.autor)
// =====================================================
async function savePlateModulesIfPresent() {
  const plateId = parseInt(document.getElementById('plateId')?.value || '0', 10);
  const plateNumber = document.getElementById('plateNumber')?.value || '';

  if (!plateId) return { success: false, message: 'plateId mancante (moduli)' };

  const results = [];

  // -------------------------
  // MODULO2: Veicolo
  // -------------------------
  try {
    const hasM2 =
      document.getElementById('m2_tipo') ||
      document.getElementById('m2_marca') ||
      document.getElementById('m2_colore') ||
      document.getElementById('m2_posizione') ||
      document.getElementById('m2_notev');

    if (hasM2) {
      const tipo = document.getElementById('m2_tipo')?.value ?? '';
      const marca = document.getElementById('m2_marca')?.value ?? '';
      const colore = document.getElementById('m2_colore')?.value ?? '';
      const posizione = document.getElementById('m2_posizione')?.value ?? '';
      const notev = document.getElementById('m2_notev')?.value ?? '';

      const resp = await fetch(`${API_BASE}/modulo2_session_save.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          plate_id: plateId,
          plate_number: plateNumber,
          tipo,
          marca,
          colore,
          posizione,
          notev
        })
      });

      const j = await resp.json();
      results.push({
        module: 'Modulo2',
        success: !!j.success,
        message: j.message || ''
      });
    }
  } catch (e) {
    results.push({
      module: 'Modulo2',
      success: false,
      message: e?.message || String(e)
    });
  }

 // -------------------------
// MODULO3: Note ingresso/uscita (Soluzione A)
// -------------------------
try {
  const hasM3 = document.getElementById('m3_note');
  if (hasM3) {
    const note = document.getElementById('m3_note')?.value ?? '';

    // ✅ ticket_code dal contesto della scheda (quello che hai appena aggiunto in plateCtx)
    // Se non hai un ctx disponibile qui, usa fallback da vari possibili campi del plate.
    const ticket_code =
      (window.currentPlate?.ticket_code || '').toString().trim() ||
      (window.currentPlate?.ticket_code_printed || '').toString().trim() ||
      (window.currentPlate?.Tticket_code || '').toString().trim() ||
      '';

    const resp = await fetch(`${API_BASE}/modulo3_notes_save.php`, {  // ✅ PLURALE (file esistente)
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        plate_id: plateId,
        ticket_code, // ✅ NEW
        note
      })
    });

    const j = await resp.json();
    results.push({
      module: 'Modulo3',
      success: !!j.success,
      message: j.message || ''
    });
  }
} catch (e) {
  results.push({
    module: 'Modulo3',
    success: false,
    message: e?.message || String(e)
  });
}

  // -------------------------
  // MODULO4: Abbonamento (abbonamenti)
  // -------------------------
  try {
    const hasM4 =
      document.getElementById('m4_inabb') ||
      document.getElementById('m4_finabb') ||
      document.getElementById('m4_attivo') ||
      document.getElementById('m4_tipo');

    if (hasM4) {
      const payload = {
        plate_number: plateNumber,

        inabb: document.getElementById('m4_inabb')?.value || null,
        finabb: document.getElementById('m4_finabb')?.value || null,
        attivo: document.getElementById('m4_attivo')?.checked ? 1 : 0,
        tipo: document.getElementById('m4_tipo')?.value || null,
        prezzo: document.getElementById('m4_prezzo')?.value || null,

        Apay: document.getElementById('m4_Apay')?.checked ? 1 : 0,
        SpayC: document.getElementById('m4_SpayC')?.checked ? 1 : 0,
        SpayE: document.getElementById('m4_SpayE')?.checked ? 1 : 0,

        // datetime-local -> già in formato YYYY-MM-DDTHH:MM
        Dpay: (document.getElementById('m4_Dpay')?.value || '').replace('T', ' ') || null,

        nome: document.getElementById('m4_nome')?.value || null,
        indirizzo: document.getElementById('m4_indirizzo')?.value || null,
        citta: document.getElementById('m4_citta')?.value || null,
        cap: document.getElementById('m4_cap')?.value || null,
        prov: document.getElementById('m4_prov')?.value || null,
        stato: document.getElementById('m4_stato')?.value || null,
        pi: document.getElementById('m4_pi')?.value || null,
        cf: document.getElementById('m4_cf')?.value || null,
        codun: document.getElementById('m4_codun')?.value || null,
        info: document.getElementById('m4_info')?.value || null
      };

      const resp = await fetch(`${API_BASE}/modulo4_abbonamento_save.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });

      const j = await resp.json();
      results.push({
        module: 'Modulo4',
        success: !!j.success,
        message: j.message || ''
      });
    }
  } catch (e) {
    results.push({
      module: 'Modulo4',
      success: false,
      message: e?.message || String(e)
    });
  }

 // -------------------------
// MODULO5: Tessera a Scalare (salva fascia/anagrafica/flag col tasto 💾 Salva principale)
// -------------------------
try {
  const hasM5 = document.getElementById('m5_fascias');
  if (hasM5) {

    // ✅ 1) se il modulo è tutto vuoto, NON salvare e NON creare tessera (evita consumare ID)
    const hasData =
      !!(document.getElementById('m5_fascias')?.value || '').trim() ||
      !!(document.getElementById('m5_motivo')?.value || '').trim() ||
      !!(document.getElementById('m5_nome')?.value || '').trim() ||
      !!(document.getElementById('m5_indirizzo')?.value || '').trim() ||
      !!(document.getElementById('m5_citta')?.value || '').trim() ||
      !!(document.getElementById('m5_cap')?.value || '').trim() ||
      !!(document.getElementById('m5_prov')?.value || '').trim() ||
      !!(document.getElementById('m5_stato')?.value || '').trim() ||
      !!(document.getElementById('m5_pi')?.value || '').trim() ||
      !!(document.getElementById('m5_cf')?.value || '').trim() ||
      !!(document.getElementById('m5_codun')?.value || '').trim() ||
      !!(document.getElementById('m5_info')?.value || '').trim() ||
      (document.getElementById('m5_attivo')?.checked) ||
      (document.getElementById('m5_Apay')?.checked) ||
      (document.getElementById('m5_SpayC')?.checked) ||
      (document.getElementById('m5_SpayE')?.checked) ||
      (document.getElementById('m5_canc')?.checked);

    if (!hasData) {
      results.push({ module:'Modulo5', success:true, message:'ℹ️ Modulo5: nessun dato da salvare' });
    } else {

      // ✅ 2) costruisco payload e salvo
      const payload = {
        plate_number: plateNumber, // <-- IMPORTANT: usa la tua variabile targa nello scope

        attivo: document.getElementById('m5_attivo')?.checked ? 1 : 0,
        Apay: document.getElementById('m5_Apay')?.checked ? 1 : 0,
        SpayC: document.getElementById('m5_SpayC')?.checked ? 1 : 0,
        SpayE: document.getElementById('m5_SpayE')?.checked ? 1 : 0,
        canc: document.getElementById('m5_canc')?.checked ? 1 : 0,
        motivo: document.getElementById('m5_motivo')?.value ?? null,

        fascias: (document.getElementById('m5_fascias')?.value || '') || null,

        nome: document.getElementById('m5_nome')?.value ?? null,
        indirizzo: document.getElementById('m5_indirizzo')?.value ?? null,
        citta: document.getElementById('m5_citta')?.value ?? null,
        cap: document.getElementById('m5_cap')?.value ?? null,
        prov: document.getElementById('m5_prov')?.value ?? null,
        stato: document.getElementById('m5_stato')?.value ?? null,
        pi: document.getElementById('m5_pi')?.value ?? null,
        cf: document.getElementById('m5_cf')?.value ?? null,
        codun: document.getElementById('m5_codun')?.value ?? null,
        info: document.getElementById('m5_info')?.value ?? null
      };

      // mutua esclusione contante/elettronico
      if (payload.SpayC === 1 && payload.SpayE === 1) payload.SpayE = 0;

      const resp = await fetch(`${API_BASE}/modulo5_tessera_save.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });

      const j = await resp.json();

      // ✅ se il backend crea una tessera, aggiorno subito il campo ID
      if (j.success && j.data?.id) {
        const elId = document.getElementById('m5_id');
        if (elId) elId.value = String(j.data.id);
      }

      results.push({
        module: 'Modulo5',
        success: !!j.success,
        message: j.message || ''
      });
    }
  }
} catch (e) {
  results.push({
    module: 'Modulo5',
    success: false,
    message: e?.message || String(e)
  });
}

  // -------------------------
  // MODULO6: Autorizzazioni (plates.autor)
  // -------------------------
  try {
    const hasM6 = document.getElementById('m6_autor');
    if (hasM6) {
      const autor = document.getElementById('m6_autor')?.value ?? '';

      const resp = await fetch(`${API_BASE}/modulo6_autor_save.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          plate_id: plateId,
          plate_number: plateNumber,
          autor
        })
      });

      const j = await resp.json();
      results.push({
        module: 'Modulo6',
        success: !!j.success,
        message: j.message || ''
      });
    }
  } catch (e) {
    results.push({
      module: 'Modulo6',
      success: false,
      message: e?.message || String(e)
    });
  }

  const anyFail = results.some(r => r.success === false);
  return { success: !anyFail, results };
}

// ===============================
// ✅ PATCH DEFINITIVA: handleSave (soft-fail se manca ticket)
// ===============================
window.handleSave = async function () {
  const type = (typeof getOpenSheetType === 'function') ? getOpenSheetType() : 'unknown';

  try {
    // ===== PASSAGGIO =====
    if (type === 'passage') {
      if (typeof savePassageDataNew !== 'function') {
        showToast('❌ savePassageDataNew non disponibile', 'error', 3500);
        return;
      }
      if (!window.currentPassage) {
        showToast('❌ Passaggio corrente non disponibile', 'error', 3500);
        return;
      }
      await savePassageDataNew(window.currentPassage, (window.currentPassage.invoice_code || ''));
      return;
    }

    // ===== TARGA =====
    if (type === 'plate') {
      // ✅ NEW: preserva contesto UI prima del refresh lista (per non "sparire" la colonna sinistra)
      const __keep = {
        plateId: parseInt(document.getElementById('plateId')?.value || '0', 10),

        // prova vari id possibili (se non esistono resta '')
        dayFilter:
          document.getElementById('filterDay')?.value ||
          document.getElementById('dayFilter')?.value ||
          document.querySelector('select[name="dayFilter"]')?.value ||
          '',

        search:
          document.getElementById('searchInput')?.value ||
          document.getElementById('searchBox')?.value ||
          document.querySelector('input[name="search"]')?.value ||
          ''
      };

      // 1) PRE-TICKET: salva ingresso su manual_plates_log se manuale editabile
      if (typeof savePlateManualPreTicketIfNeeded === 'function') {
        const pre = await savePlateManualPreTicketIfNeeded();
        if (pre && pre.success === false) {
          showToast('❌ ' + (pre.message || 'Errore salvataggio ingresso (manuale)'), 'error', 4000);
          return;
        }
      }

      // 2) CASSA: prova a salvare (richiede ticket) -> soft-fail se manca
      let cassaRes = null;
      if (typeof savePlateToCassaIfTicketExistsSoft === 'function') {
        cassaRes = await savePlateToCassaIfTicketExistsSoft();
      } else if (typeof savePlateCassaStatusIfPossible === 'function') {
        // fallback se nel tuo file è rimasto il nome vecchio
        cassaRes = await savePlateCassaStatusIfPossible();
      }

      if (cassaRes && cassaRes.success === false) {
        const msg = cassaRes.message || '';

        // ✅ soft-fail specifico: ticket mancante
        if (msg.toLowerCase().includes('nessun ticket associato')) {
          showToast('⚠️ Ticket non presente: salvato ingresso (manuale).', 'warning', 4500);
        } else {
          showToast('❌ ' + msg, 'error', 4500);
          return;
        }
      } else {
        showToast('✅ Salvato', 'success', 2000);
      }

      // 3) ✅ NEW: salva MODULI (Veicolo, Note, Abbonamento, Tessera, Autorizzazioni)
      // Non deve bloccare il salvataggio principale: warning only.
      try {
        if (typeof savePlateModulesIfPresent === 'function') {
          const modRes = await savePlateModulesIfPresent();

          if (modRes && modRes.success === false) {
            const msgs = (modRes.results || [])
              .filter(x => x && x.success === false)
              .map(x => `${x.module}: ${x.message || 'errore'}`)
              .join(' | ');

            showToast('⚠️ Salvataggio moduli incompleto: ' + (msgs || ''), 'warning', 5000);
          }
        }
      } catch (e) {
        console.warn('Errore savePlateModulesIfPresent:', e);
        showToast('⚠️ Errore salvataggio moduli', 'warning', 4500);
      }

      // 4) refresh UI (lista targhe) + reselect, ripristinando filtri/ricerca se esistono
      if (__keep.plateId && typeof loadPlates === 'function') {
        loadPlates(() => {
          const fd =
            document.getElementById('filterDay') ||
            document.getElementById('dayFilter') ||
            document.querySelector('select[name="dayFilter"]');

          if (fd && __keep.dayFilter) fd.value = __keep.dayFilter;

          const si =
            document.getElementById('searchInput') ||
            document.getElementById('searchBox') ||
            document.querySelector('input[name="search"]');

          if (si && __keep.search) si.value = __keep.search;

          // micro-delay per evitare race con render lista
          setTimeout(() => {
            if (typeof selectPlate === 'function') selectPlate(__keep.plateId);
          }, 80);
        });
      }

      return;
    }

    showToast('⚠️ Nessuna scheda aperta da salvare', 'warning', 3000);

  } catch (e) {
    console.error('handleSave error:', e);
    showToast('❌ Errore salvataggio: ' + (e?.message || e), 'error', 4500);
  }
};
// =====================================================
// EXPORT/ALIAS compatibilità render
// Serve perché altri file (app.js/plates.js) chiamano funzioni globali.
// =====================================================

// renderDetails -> window.renderDetails
if (typeof window.renderDetails !== 'function' && typeof renderDetails === 'function') {
  window.renderDetails = renderDetails;
}

// alias richiesto da alcuni punti del codice
if (typeof window.renderPlateDetailsWithTimes !== 'function') {
  // se esiste renderDetails, usala come implementazione
  if (typeof window.renderDetails === 'function') {
    window.renderPlateDetailsWithTimes = window.renderDetails;
  }
}

// (opzionale) alias “renderPlateDetails” se qualche chiamata lo usa
if (typeof window.renderPlateDetails !== 'function') {
  if (typeof window.renderDetails === 'function') {
    window.renderPlateDetails = window.renderDetails;
  }
}

async function emettiRicevutaTarga() {
  try {
    const plateId = parseInt(document.getElementById('plateId')?.value || '0', 10);
    const invoiceCode = (document.getElementById('invoiceCodeHidden')?.value || '').trim();

    if (!plateId) {
      showToast('❌ plateId mancante', 'error', 4000);
      return;
    }

    // Se già emessa, non riemettere (UI già mostra Ristampa)
    if (invoiceCode) {
      showToast('⚠️ Ricevuta già emessa: ' + invoiceCode, 'warning', 4500);
      return;
    }

    // REQ: blocco se annullato (coerente col passaggio)
    if (document.getElementById('annullato')?.checked) {
      showToast('❌ Ticket annullato: non puoi emettere la ricevuta', 'error', 4500);
      return;
    }

    // Prezzo obbligatorio: il PHP scala e salva prezzo/invoice_price
    const price = parseFloat(document.getElementById('prezzo')?.value || '0');
    if (!price || Number.isNaN(price) || price <= 0) {
      showToast('⚠️ Calcola/imposta un prezzo valido prima di emettere la ricevuta', 'warning', 4500);
      return;
    }

    const payload = {
      plate_id: plateId,
      price: price,
      fascia: (document.getElementById('fascia')?.value || '').trim(),

      Tpaid: document.getElementById('paid')?.checked ? 1 : 0,
      TpayC: document.getElementById('pay_cash')?.checked ? 1 : 0,
      TpayE: document.getElementById('pay_electronic')?.checked ? 1 : 0,
      Tannullato: document.getElementById('annullato')?.checked ? 1 : 0,
      Tannultxt: document.getElementById('motivo')?.value || ''
    };

    // mutua esclusione contante/elettronico
    if (payload.TpayC === 1 && payload.TpayE === 1) payload.TpayE = 0;

    showToast('⏳ Emissione ricevuta...', 'info', 2000);

       const resp = await fetch(`${API_BASE}/emit_receipt_plate.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    // ============================================================
    // ✅ PATCH DEBUG: gestisce risposte non-JSON (HTML/500/warning)
    // NON cancelliamo nulla: la vecchia logica resp.json() resta commentata.
    // ============================================================

    // --- VECCHIO (lasciare come storico) ---
    // let j = null;
    // try {
    //   j = await resp.json();
    // } catch (e) {
    //   showToast('❌ Risposta non valida dal server (JSON)', 'error', 4500);
    //   return;
    // }

    let j = null;
    let rawText = '';

    try {
      rawText = await resp.text();
      try {
        j = JSON.parse(rawText);
      } catch (eJson) {
        // non JSON
      }
    } catch (eTxt) {
      console.error('emit_receipt_plate: errore lettura risposta:', eTxt);
    }

    if (!j) {
      console.error('emit_receipt_plate non-JSON response:', rawText);
      showToast('❌ Errore server: risposta non JSON (vedi Console/Network)', 'error', 7000);
      return;
    }

    if (!j.success) {
      showToast(j.message || '❌ Errore emissione ricevuta', 'error', 4500);
      return;
    }

    const receipt = j.data?.receipt_code || '';
    showToast(receipt ? ('✅ Ricevuta: ' + receipt) : '✅ Ricevuta emessa', 'success', 4500);

    // Refresh scheda targa
    if (typeof loadPlates === 'function') {
      loadPlates(() => {
        if (typeof selectPlate === 'function') selectPlate(plateId);
      });
    }
  } catch (e) {
    console.error('emettiRicevutaTarga error:', e);
    showToast('❌ Errore emissione ricevuta: ' + (e?.message || e), 'error', 4500);
  }
}

// ✅ export globale per onclick inline (NON sovrascrive se già definita)
if (typeof window.emettiRicevutaTarga !== 'function') {
  window.emettiRicevutaTarga = emettiRicevutaTarga;
}
function calcPrezzoByFascia(minutiTotali, prezzoOra, prezzoDay, tolleranzaMin, nore) {
  // minutiTotali: differenza ingresso/uscita in minuti
  // prezzoOra: PrezzoF1 (€/h)
  // prezzoDay: PrezzoDayF1 (€/giorno)
  // tolleranzaMin: TolleranzaF1 (minuti)
  // nore: Nore (ore soglia giornaliera)

  minutiTotali = Math.max(0, Math.floor(Number(minutiTotali) || 0));
  tolleranzaMin = Math.max(0, Math.floor(Number(tolleranzaMin) || 0));
  nore = Math.max(1, Math.floor(Number(nore) || 5));

  if (minutiTotali < tolleranzaMin) {
    return { prezzo: 0, motivo: 'Sosta troppo breve (tolleranza)' };
  }

  // Helper: arrotondamento a scatti di 1h con tolleranza
  // - se eccede (h*60 + tolleranza) => passa a h+1
  // - altrimenti resta a h
  // - minimo 1h se >= tolleranza
  function oreConTolleranza(minuti) {
    if (minuti < tolleranzaMin) return 0;

    const h = Math.floor(minuti / 60);
    const rem = minuti % 60;

    if (h === 0) return 1; // tra tolleranza e 59' => 1 ora

    // se supero h ore + tolleranza => scatto a h+1
    if (rem > tolleranzaMin) return h + 1;

    // se rem <= tolleranza => resto a h
    return h;
  }

  // Spezza in giorni completi (24h) + residuo
  const dayMin = 24 * 60;
  const giorniInteri = Math.floor(minutiTotali / dayMin);
  let residuo = minutiTotali % dayMin;

  let prezzo = giorniInteri * prezzoDay;

  // Calcola prezzo del residuo dentro la giornata:
  // - fino a Nore => ore * prezzoOra
  // - oltre Nore => prezzoDay (giorno pieno) se residuo >= tolleranza
  function prezzoResiduo(minutiResidui) {
    if (minutiResidui < tolleranzaMin) return 0;

    const ore = oreConTolleranza(minutiResidui);

    // Se le ore (a scatti) restano entro Nore => orario
    if (ore > 0 && ore <= nore) return ore * prezzoOra;

    // Se supero Nore => giorno pieno
    return prezzoDay;
  }

  prezzo += prezzoResiduo(residuo);

  return { prezzo, motivo: '' };
}

(function () {
  // Se già esiste una calcolaUscitaPerTarga valida, non faccio nulla
  if (typeof window.calcolaUscitaPerTarga === 'function') return;

  window.calcolaUscitaPerTarga = async function () {
    try {
      // 0) Se esiste la tua versione "fissa" (quella che mette uscita=now e blocca), usa quella
      if (typeof window.calcolaUscitaTarga_Fissa === 'function') {
        window.calcolaUscitaTarga_Fissa();
      }
      // 1) Altrimenti prova altri nomi possibili (se esistono in altri file)
      else if (typeof window.calcolaUscita === 'function') {
        await window.calcolaUscita();
      } else if (typeof window.calcola === 'function') {
        await window.calcola();
      } else {
        // NON cancellare nulla: mantengo lo stesso messaggio che già usi
        console.error('calcolaUscitaTarga_Fissa non definita');
        if (typeof window.showToast === 'function') {
          window.showToast('❌ Funzione calcolo uscita mancante', 'error', 4000);
        } else {
          alert('Funzione calcolo uscita mancante');
        }
        return;
      }

      // 2) Aggiorna durata (se presente)
      if (typeof window.aggiornaSelettoriDurataFromDom === 'function') {
        window.aggiornaSelettoriDurataFromDom();
      } else if (typeof window._aggiornaDurataDaCampi === 'function') {
        window._aggiornaDurataDaCampi();
      }

      // 3) Calcola prezzo (se presente)
      if (typeof window.calcolaPrezzoTarga === 'function') {
        window.calcolaPrezzoTarga();
      }
    } catch (e) {
      console.error('calcolaUscitaPerTarga compat error:', e);
      if (typeof window.showToast === 'function') {
        window.showToast('❌ Errore calcolo uscita: ' + (e?.message || e), 'error', 4500);
      }
    }
  };
})();
/*
======= MODIFICHE PRINCIPALI =======
- AGGIUNTO: window.currentPassage = data all'inizio della funzione, per memorizzare l'oggetto corrente da riusare per il salvataggio.
- MODIFICATO: bottone SALVA ora chiama savePassageDataNew(window.currentPassage, ...) invece che solo savePassageDataNew(selectedPassageId, ...), così la funzione riceve SEMPRE l'oggetto e non va più in errore "ID passaggio mancante".
- TUTTO il resto resta invariato.
====================================
*/
// ========== (ALTRE FUNZIONI SALVA ECC. INVARIATE) ===========
// ✅ EXPORT GLOBALE: calcolaUscitaTarga_Fissa
if (typeof window.calcolaUscitaTarga_Fissa !== 'function' && typeof calcolaUscitaTarga_Fissa === 'function') {
  window.calcolaUscitaTarga_Fissa = calcolaUscitaTarga_Fissa;
}
// =====================================================
// ✅ FUNZIONE MANCANTE: calcolaUscitaTarga_Fissa
// Imposta uscita=adesso e blocca i campi
// =====================================================


// ✅ EXPORT GLOBALE: calcolaUscitaTarga_Fissa
if (typeof window.calcolaUscitaTarga_Fissa !== 'function') {
  window.calcolaUscitaTarga_Fissa = calcolaUscitaTarga_Fissa;
}

console.log('✅ details.js caricato correttamente!');
console.log('✅ details.js caricato correttamente!');