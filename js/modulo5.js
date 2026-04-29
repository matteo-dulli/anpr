// modulo5.js - 💳 Tessera a Scalare (layout come image5 + preview prossimo ID)
// API:
// - GET tessera:        modulo5_tessera_get.php?plate_number=...
// - SAVE tessera:       modulo5_tessera_save.php
// - RICARICA:           modulo5_tessera_ricarica.php
// - NUOVA tessera:      modulo5_tessera_new.php
// - FASCE scalare:      get_fasce_scalare.php
// - NEXT ID (preview):  modulo5_tessera_next_id.php

window.Modulo5 = window.Modulo5 || {};

window.Modulo5.mount = async function mountModulo5(container, ctx) {
  if (!container) return;

  const plateNumber = (ctx?.plate_number || '').toString().trim();
  if (!plateNumber) {
    container.innerHTML = `<div style="color:#b00">plate_number mancante</div>`;
    return;
  }

  container.innerHTML = `
    <div id="m5_root" style="display:flex;flex-direction:column;gap:10px;">

      <!-- RIGA 1: ID | RESIDUO | IMPORTO RICARICA -->
      <div style="display:grid;grid-template-columns: 220px 140px 1fr;gap:14px;align-items:end;">
        <div class="form-group">
          <label>N. TESSERA (ID)</label>
          <input id="m5_id" type="text" readonly>
          <div id="m5_id_hint" style="font-size:12px;color:#666;margin-top:4px;"></div>
        </div>

        <div class="form-group">
          <label>RESIDUO €</label>
          <input id="m5_res1" type="text" readonly
                 style="font-size:20px;font-weight:700;background:#fff7d6;border:1px solid #e5e7eb;padding:8px;">
        </div>

        <div class="form-group">
          <label>IMPORTO RICARICA € (INPUT, NON STORICO)</label>
          <input id="m5_importo" type="number" step="0.01" min="0">
        </div>
      </div>

      <!-- RIGA 2: FASCIA | PAGATO/CONTANTE/ELETTRONICO -->
      <div style="display:grid;grid-template-columns: 420px 1fr;gap:14px;align-items:end;">
        <div class="form-group" style="position:relative;">
          <label>FASCIA</label>
          <select id="m5_fascias"></select>

          <!-- overlay: non modifica l'altezza della riga e non sposta i flag -->
          <div id="m5_fascia_hint"
               style="position:absolute;left:0;bottom:-16px;font-size:12px;color:#666;line-height:12px;pointer-events:none;">
          </div>
        </div>

        <div style="display:flex;gap:18px;align-items:center;justify-content:flex-end;">
          <label style="display:flex;gap:8px;align-items:center;margin:0;">
            <input type="checkbox" id="m5_Apay"> PAGATO
          </label>
          <label style="display:flex;gap:8px;align-items:center;margin:0;">
            <input type="checkbox" id="m5_SpayC"> CONTANTE
          </label>
          <label style="display:flex;gap:8px;align-items:center;margin:0;">
            <input type="checkbox" id="m5_SpayE"> ELETTRONICO
          </label>
        </div>
      </div>

      <!-- RIGA 3: ATTIVA/ANNULLA + MOTIVO -->
      <div style="display:grid;grid-template-columns: 1fr 1fr;gap:14px;align-items:start;">
        <div style="display:flex;flex-direction:column;gap:10px;">
          <div style="display:flex;gap:18px;align-items:center;padding-top:6px;">
            <label style="display:flex;gap:8px;align-items:center;margin:0;">
              <input type="checkbox" id="m5_attivo"> ATTIVA
            </label>
            <label style="display:flex;gap:8px;align-items:center;margin:0;">
              <input type="checkbox" id="m5_canc"> ANNULLA TESSERA
            </label>
          </div>

          <div class="form-group" style="margin:0;">
            <label>MOTIVO ANNULLAMENTO</label>
            <input id="m5_motivo" type="text" maxlength="200">
          </div>
        </div>

        <div></div>
      </div>

      <!-- RIGA 4: LOGPAY (riga dedicata) -->
      <div class="form-group">
        <label>STORICO RICARICHE (LOGPAY)</label>
        <textarea id="m5_logpay" rows="4" readonly style="width:100%;"></textarea>
      </div>

      <!-- RIGA 5: BOTTONI -->
      <div style="display:flex;gap:10px;align-items:center;">
        <button type="button" class="btn-small" id="m5_btn_ricarica" style="background:#667eea;color:white;">
          ➕ Conferma ricarica
        </button>
        <button type="button" class="btn-small" id="m5_btn_new" style="background:#f59e42;color:white;">
          🆕 Genera nuova Tessera
        </button>
        <span id="m5_msg" style="font-size:12px;color:#666;"></span>
      </div>

      <hr style="margin:6px 0;">

      <!-- ANAGRAFICA -->
      <div style="display:grid;grid-template-columns: 1fr 1fr 120px;gap:10px;">
        <div class="form-group"><label>NOME/COGNOME</label><input id="m5_nome" type="text"></div>
        <div class="form-group"><label>INDIRIZZO</label><input id="m5_indirizzo" type="text"></div>
        <div class="form-group"><label>CAP</label><input id="m5_cap" type="text"></div>
      </div>

      <div style="display:grid;grid-template-columns: 1fr 120px 120px;gap:10px;">
        <div class="form-group"><label>CITTÀ</label><input id="m5_citta" type="text"></div>
        <div class="form-group"><label>PROV.</label><input id="m5_prov" type="text"></div>
        <div class="form-group"><label>STATO</label><input id="m5_stato" type="text"></div>
      </div>

      <div style="display:grid;grid-template-columns: 1fr 1fr 1fr;gap:10px;">
        <div class="form-group"><label>P.IVA</label><input id="m5_pi" type="text"></div>
        <div class="form-group"><label>C.F.</label><input id="m5_cf" type="text"></div>
        <div class="form-group"><label>CODICE UNIVOCO</label><input id="m5_codun" type="text"></div>
      </div>

      <div class="form-group">
        <label>ALTRE INFORMAZIONI</label>
        <textarea id="m5_info" rows="3"></textarea>
      </div>

    </div>
  `;

  // Mutua esclusione pagamenti (Contante/Elettronico)
  const cbC = container.querySelector('#m5_SpayC');
  const cbE = container.querySelector('#m5_SpayE');
  if (cbC && cbE) {
    cbC.addEventListener('change', () => { if (cbC.checked) cbE.checked = false; });
    cbE.addEventListener('change', () => { if (cbE.checked) cbC.checked = false; });
  }

  // Carica fasce + next-id preview (in parallelo)
  await Promise.all([
    window.Modulo5.loadFasceScalare(container, null),
    window.Modulo5.loadNextIdPreview(container)
  ]);

  // Load tessera
  await window.Modulo5.load(container, plateNumber);

  // Actions
  container.querySelector('#m5_btn_ricarica')?.addEventListener('click', async () => {
    await window.Modulo5.ricarica(container, plateNumber);
  });
  container.querySelector('#m5_btn_new')?.addEventListener('click', async () => {
    await window.Modulo5.generaNuova(container, plateNumber);
  });

  // hint fascia
  container.querySelector('#m5_fascias')?.addEventListener('change', () => {
    window.Modulo5._updateFasciaHint(container);
  });
};

window.Modulo5.loadNextIdPreview = async function loadNextIdPreview(container) {
  const idInput = container.querySelector('#m5_id');
  const hint = container.querySelector('#m5_id_hint');
  if (!idInput || !hint) return;

  // Se esiste già un id reale, non mostrare preview
  if ((idInput.value || '').trim() !== '') return;

  try {
    const r = await fetch(`${API_BASE}/modulo5_tessera_next_id.php?t=${Date.now()}`, { cache: 'no-store' });
    const j = await r.json();
    if (j.success && j.data && j.data.next_id != null) {
      idInput.value = String(j.data.next_id);
      hint.textContent = 'Prossimo ID (preview): verrà confermato al salvataggio';
    } else {
      hint.textContent = '';
    }
  } catch (e) {
    hint.textContent = '';
  }
};

window.Modulo5.loadFasceScalare = async function loadFasceScalare(container, selectedValue) {
  const sel = container.querySelector('#m5_fascias');
  if (!sel) return;

  sel.innerHTML = `<option value="">Caricamento...</option>`;

  try {
    const r = await fetch(`${API_BASE}/get_fasce_scalare.php?t=${Date.now()}`, { cache: 'no-store' });
    const j = await r.json();

    if (!j.success || !Array.isArray(j.data)) {
      sel.innerHTML = `<option value="">(fasce non disponibili)</option>`;
      return;
    }

    const options = j.data.map(f => {
      const value = (f.codice || '').toString();
      const label = (f.label || value).toString();
      const selected = (selectedValue && value === selectedValue) ? 'selected' : '';
      const prezzo = (typeof f.prezzo === 'number' && !isNaN(f.prezzo)) ? f.prezzo.toFixed(2) : '';
      const testo = (f.testo || '').toString();

      return `<option value="${value}" data-prezzo="${prezzo}" data-testo="${encodeURIComponent(testo)}" ${selected}>${label}</option>`;
    }).join('');

    sel.innerHTML = `<option value="">(seleziona)</option>` + options;

  } catch (e) {
    sel.innerHTML = `<option value="">(errore caricamento fasce)</option>`;
  }
};

window.Modulo5._updateFasciaHint = function _updateFasciaHint(container) {
  const sel = container.querySelector('#m5_fascias');
  const hint = container.querySelector('#m5_fascia_hint');
  if (!sel || !hint) return;

  const opt = sel.options[sel.selectedIndex];
  if (!opt || !opt.value) {
    hint.textContent = '';
    return;
  }

  // ✅ Mostro SOLO la descrizione, così non duplichi "€3.00/h" (già dentro la select)
  const testo = opt.dataset.testo ? decodeURIComponent(opt.dataset.testo) : '';
  hint.textContent = testo;
};

window.Modulo5.load = async function loadModulo5(container, plateNumber) {
  const msg = container.querySelector('#m5_msg');
  const idHint = container.querySelector('#m5_id_hint');
  if (msg) msg.textContent = 'Caricamento...';

  const r = await fetch(`${API_BASE}/modulo5_tessera_get.php?plate_number=${encodeURIComponent(plateNumber)}&t=${Date.now()}`, { cache: 'no-store' });
  const j = await r.json();

  if (!j.success) {
    if (msg) msg.textContent = j.message || 'Errore';
    return;
  }

  const d = j.data;

  if (!d) {
    container.querySelector('#m5_id').value = container.querySelector('#m5_id').value || '';
    container.querySelector('#m5_res1').value = '0.00';
    container.querySelector('#m5_logpay').value = '';
    container.querySelector('#m5_importo').value = '';
    if (msg) msg.textContent = 'Nessuna tessera attiva';

    // Se non c'è tessera, prova a mostrare preview ID
    await window.Modulo5.loadNextIdPreview(container);
    return;
  }

  // ✅ ID reale: sostituisce preview
  container.querySelector('#m5_id').value = d.id ?? '';
  if (idHint) idHint.textContent = '';

  container.querySelector('#m5_res1').value = (Number(d.res1 || 0)).toFixed(2);

  container.querySelector('#m5_attivo').checked = Number(d.attivo || 0) === 1;
  container.querySelector('#m5_Apay').checked = Number(d.Apay || 0) === 1;
  container.querySelector('#m5_SpayC').checked = Number(d.SpayC || 0) === 1;
  container.querySelector('#m5_SpayE').checked = Number(d.SpayE || 0) === 1;

  container.querySelector('#m5_canc').checked = Number(d.canc || 0) === 1;
  container.querySelector('#m5_motivo').value = d.motivo ?? '';

  // fascia
  const sel = container.querySelector('#m5_fascias');
  if (sel) {
    sel.value = d.fascias ?? '';
    window.Modulo5._updateFasciaHint(container);
  }

  container.querySelector('#m5_logpay').value = d.logpay ?? '';

  container.querySelector('#m5_nome').value = d.nome ?? '';
  container.querySelector('#m5_indirizzo').value = d.indirizzo ?? '';
  container.querySelector('#m5_cap').value = d.cap ?? '';
  container.querySelector('#m5_citta').value = d.citta ?? '';
  container.querySelector('#m5_prov').value = d.prov ?? '';
  container.querySelector('#m5_stato').value = d.stato ?? '';
  container.querySelector('#m5_pi').value = d.pi ?? '';
  container.querySelector('#m5_cf').value = d.cf ?? '';
  container.querySelector('#m5_codun').value = d.codun ?? '';
  container.querySelector('#m5_info').value = d.info ?? '';

  // input ricarica sempre vuoto
  container.querySelector('#m5_importo').value = '';

  if (msg) msg.textContent = '';
};

window.Modulo5.ricarica = async function ricaricaModulo5(container, plateNumber) {
  const msg = container.querySelector('#m5_msg');
  const importo = Number(container.querySelector('#m5_importo')?.value || 0);

  if (!importo || importo <= 0) {
    if (msg) msg.textContent = 'Inserisci un importo valido';
    return;
  }

  const SpayC = container.querySelector('#m5_SpayC')?.checked ? 1 : 0;
  const SpayE = container.querySelector('#m5_SpayE')?.checked ? 1 : 0;

  const resp = await fetch(`${API_BASE}/modulo5_tessera_ricarica.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ plate_number: plateNumber, importo, SpayC, SpayE })
  });
  const j = await resp.json();

  if (!j.success) {
    if (msg) msg.textContent = j.message || 'Errore ricarica';
    return;
  }

  if (msg) msg.textContent = j.message || 'Ricarica OK';
  await window.Modulo5.load(container, plateNumber);
};

window.Modulo5.generaNuova = async function generaNuovaModulo5(container, plateNumber) {
  const msg = container.querySelector('#m5_msg');
  if (msg) msg.textContent = 'Creazione nuova tessera...';

  const resp = await fetch(`${API_BASE}/modulo5_tessera_new.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ plate_number: plateNumber })
  });
  const j = await resp.json();

  if (!j.success) {
    if (msg) msg.textContent = j.message || 'Errore nuova tessera';
    return;
  }

  if (msg) msg.textContent = j.message || 'Nuova tessera creata';
  await window.Modulo5.load(container, plateNumber);
};