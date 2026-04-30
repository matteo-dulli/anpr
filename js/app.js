// ================== CONFIG GLOBALE ==================
console.log('🚀 app.js caricato (config globale)');

const API_BASE = '/anpr/api';

// ================== HELPERS RICERCA (mancanti) ==================
// Normalizza input: trim, uppercase, rimuove spazi multipli
function normalizeSearchInput(raw) {
  let q = (raw ?? '').toString().trim().toUpperCase();
  // evita caratteri invisibili / newline da barcode scanner
  q = q.replace(/\s+/g, ' ').trim();
  return q;
}

// Ticket code tipico: "TYYYYMMDD-HHMMSS-XXXXXX" (es: T20260425-204016-403FD)
// Regex tollerante: basta che inizi con T + cifre, abbia almeno un trattino e lunghezza minima.
function isFullTicketCode(s) {
  const q = normalizeSearchInput(s);
  // accetta: T + 8 cifre + - + 6 cifre + - + 3..20 alfanumerici
  return /^T\d{8}-\d{6}-[A-Z0-9]{3,20}$/.test(q);
}

// Ricevuta: non hai fornito formato certo.
// Metto un matcher permissivo: se inizia con R o contiene "R_" e ha una lunghezza minima.
function isFullReceiptCode(s) {
  const q = normalizeSearchInput(s);
  // esempi possibili: R20260425-..., R_..., R123...
  return /^(R_|R)\w{6,}$/.test(q);
}

let allPlates = [];
let selectedPlateId = null;
let selectedIsPassage = false;  // ✅ AGGIUNGI QUESTA LINEA
let selectPlateLock = false;
let autoRefreshInterval = null;
let autoScanInterval = null;
let justCreatedManualPlateId = null;
let connectionStartTime = new Date();

// ================== RICERCA TICKET / BARCODE ==================
async function handleTicketSearch() {
  const searchTicketInput = document.getElementById('searchTicketInfo');
  const rawQuery = searchTicketInput ? searchTicketInput.value : '';
  const query = normalizeSearchInput(rawQuery);

  if (!query) {
    showToast('⚠️ Inserisci un codice ticket o ricevuta', 'error');
    return;
  }

  console.log('🔍 Ricerca ticket/ricevuta:', query);

  try {
    // ==========================================================
    // ✅ FIX: se query è SOLO numeri => prova ad aprire PASSAGGIO per ID
    // ==========================================================
    if (/^\d+$/.test(query)) {
      const passageId = parseInt(query, 10);

      if (passageId > 0) {
        if (typeof selectPassage !== 'function') {
          showToast('⚠️ Gestione passaggi non disponibile in questa versione', 'warning', 4000);
          if (typeof selectedIsPassage !== 'undefined') selectedIsPassage = false;
          return;
        }

        if (typeof selectedPassageId !== 'undefined') selectedPassageId = passageId;

        // modalità passaggi SOLO se hai anche la lista passaggi
        if (typeof updatePassagesList === 'function' || typeof loadPassages === 'function') {
          if (typeof selectedIsPassage !== 'undefined') selectedIsPassage = true;
        } else {
          if (typeof selectedIsPassage !== 'undefined') selectedIsPassage = false;
        }

        try {
          await selectPassage(passageId);
        } catch (e) {
          console.error('selectPassage error:', e);
          if (typeof selectedIsPassage !== 'undefined') selectedIsPassage = false;
          showToast('❌ Errore apertura passaggio', 'error', 4000);
          return;
        }

        if (searchTicketInput) searchTicketInput.value = '';
        hideTicketSearchDropdown();
        return;
      }
    }

    // ==========================================================
    // NEW: Se NON è codice completo ticket/ricevuta, usa search_plate.php
    //      (targhe manuali/rilevate + passaggi + partial ticket_code) con dropdown
    // ==========================================================
    const isFull = (isFullTicketCode(query) || isFullReceiptCode(query));

    if (!isFull) {
      const urlPlate = `${API_BASE}/search_plate.php?q=${encodeURIComponent(query)}&limit=20&t=${Date.now()}`;
      const respPlate = await fetch(urlPlate, { cache: 'no-store' });
      if (!respPlate.ok) throw new Error('HTTP ' + respPlate.status);

      const resPlate = await respPlate.json();

      if (!resPlate.success) {
        showToast(resPlate.message || '❌ Errore ricerca', 'error', 4000);
        return;
      }

      const results = Array.isArray(resPlate.data) ? resPlate.data : [];
      if (results.length === 0) {
        showToast('⚠️ Nessun risultato', 'warning', 3000);
        return;
      }

      const dd = ensureTicketSearchDropdown();
      positionTicketSearchDropdown();

      dd.innerHTML = results.map((row, idx) => {
        const isPassage = Number(row.is_passage) === 1;
        const displayMain = (row.plate_corrected || row.plate_number || '').toString().toUpperCase();

        const when = row.date_detected
          ? new Date(String(row.date_detected).replace(' ', 'T')).toLocaleString('it-IT')
          : '';

        const meta = [
          isPassage ? `🚶 passaggio#${row.passage_id}` : `🚗 targa#${row.id}`,
          row.ticket_code ? `🎫 ${row.ticket_code}` : '',
          when ? `🕒 ${when}` : ''
        ].filter(Boolean).join(' · ');

        const safeMain = displayMain.replace(/</g, '&lt;').replace(/>/g, '&gt;');
        const safeMeta = meta.replace(/</g, '&lt;').replace(/>/g, '&gt;');

        return `
          <div class="ticket-search-item"
               data-idx="${idx}"
               style="padding:10px;cursor:pointer;border-bottom:1px solid #eee;">
            <div style="font-weight:700;">${safeMain || '(senza targa)'}</div>
            <div style="font-size:11px;color:#666;margin-top:2px;">${safeMeta}</div>
          </div>
        `;
      }).join('');

      dd.style.display = 'block';

      dd.querySelectorAll('.ticket-search-item').forEach(el => {
        el.addEventListener('click', async () => {
          const idx = parseInt(el.dataset.idx, 10);
          const row = results[idx];
          hideTicketSearchDropdown();

          try {
            // Apri scheda
            if (Number(row.is_passage) === 1 && row.passage_id) {
              if (typeof selectPassage !== 'function') {
                showToast('⚠️ Gestione passaggi non disponibile in questa versione', 'warning', 4000);
                if (typeof selectedIsPassage !== 'undefined') selectedIsPassage = false;
                return;
              }

              if (typeof updatePassagesList === 'function' || typeof loadPassages === 'function') {
                if (typeof selectedIsPassage !== 'undefined') selectedIsPassage = true;
              } else {
                if (typeof selectedIsPassage !== 'undefined') selectedIsPassage = false;
              }

              await selectPassage(parseInt(row.passage_id, 10));
            } else if (row.id) {
              if (typeof selectedIsPassage !== 'undefined') selectedIsPassage = false;
              await selectPlate(parseInt(row.id, 10));
            } else {
              showToast('⚠️ Risultato non apribile', 'warning', 3000);
              return;
            }

            // pulizia input dopo selezione
            if (searchTicketInput) searchTicketInput.value = '';
          } catch (e) {
            console.error('dropdown click open error:', e);
            if (typeof selectedIsPassage !== 'undefined') selectedIsPassage = false;
            showToast('❌ Errore apertura scheda', 'error', 4000);
          }
        });
      });

      // IMPORTANT: qui usciamo, perché per targhe/passaggi/partial vogliamo dropdown
      return;
    }

    // ==========================================================
    // OLD: ricerca solo su search_code.php (esiste ancora per codici completi)
    // ==========================================================

    // Se è chiaramente un codice completo, facciamo exact.
    // Altrimenti possiamo comunque fare exact (utile per barcode scanner che invia Enter).
    const mode = (isFullTicketCode(query) || isFullReceiptCode(query)) ? 'exact' : 'exact';

    const url = `${API_BASE}/search_code.php?q=${encodeURIComponent(query)}&mode=${mode}&limit=10&t=${Date.now()}`;
    const resp = await fetch(url, { cache: 'no-store' });
    if (!resp.ok) throw new Error('HTTP ' + resp.status);
    const res = await resp.json();

    if (!res.success) {
      showToast(res.message || '❌ Errore ricerca', 'error', 4000);
      return;
    }

    const results = (res.data && Array.isArray(res.data.results)) ? res.data.results : [];
    if (results.length === 0) {
      showToast('⚠️ Nessun risultato', 'warning', 3000);
      return;
    }

    // ✅ Se è codice completo e ho almeno 1 match, apro SUBITO il primo match
    if (isFullTicketCode(query) || isFullReceiptCode(query)) {
      await applySearchResult(results[0]);
      return;
    }

    // Se non è codice completo:
    // - se 1 risultato, apri subito
    // - se >1, mostra rollup
    if (results.length === 1) {
      await applySearchResult(results[0]);
      return;
    }

    // Rollup per scegliere
    const dd = ensureTicketSearchDropdown();
    positionTicketSearchDropdown();
    dd.innerHTML = results.map((row, idx) => {
      const label = formatSearchRow(row);
      const safeLabel = label.replace(/</g, '&lt;').replace(/>/g, '&gt;');
      return `
        <div class="ticket-search-item"
             data-idx="${idx}"
             style="padding:10px;cursor:pointer;border-bottom:1px solid #eee;">
          ${safeLabel}
        </div>
      `;
    }).join('');
    dd.style.display = 'block';

    dd.querySelectorAll('.ticket-search-item').forEach(el => {
      el.addEventListener('click', async () => {
        try {
          const idx = parseInt(el.dataset.idx, 10);
          const row = results[idx];
          hideTicketSearchDropdown();
          await applySearchResult(row);
        } catch (e) {
          console.error('applySearchResult error:', e);
          showToast('❌ Errore apertura risultato', 'error', 4000);
        }
      });
    });
  } catch (e) {
    console.error('handleTicketSearch error:', e);
    showToast('❌ Errore ricerca: ' + (e.message || e), 'error', 4000);
    if (typeof selectedIsPassage !== 'undefined') selectedIsPassage = false;
  }
}

  // ==========================================================
  // OLD: pulizia subito dell'input
  // ⚠️ Se pulisci subito, quando dropdown è aperto l’utente non vede cosa ha digitato.
  // Ti consiglio di NON farlo quando mostriamo il dropdown (sopra, return).
  // Qui lo lascio ma lo commento.
  // ==========================================================
  // if (searchTicketInput) searchTicketInput.value = '';
  // hideTicketSearchDropdown();


function initTicketSearch() {
    const searchTicketInput = document.getElementById('searchTicketInfo');
    if (!searchTicketInput) {
        console.warn('initTicketSearch: #searchTicketInfo non trovato');
        return;
    }

    // ✅ input handler: maiuscolo + auto-open su match exact + fallback autocomplete
    searchTicketInput.addEventListener('input', (e) => {
        const old = e.target.value || '';
        const upper = old.toUpperCase();
        if (old !== upper) {
            const pos = e.target.selectionStart;
            e.target.value = upper;
            try { e.target.setSelectionRange(pos, pos); } catch {}
        }

        const q = normalizeSearchInput(e.target.value);

        // Se vuoto, chiudi dropdown e reset
        if (!q) {
            hideTicketSearchDropdown();
            lastAutoOpenedQuery = '';
            return;
        }

        // debounce unico per (1) exact auto-open e (2) autocomplete
        if (ticketSearchTimer) clearTimeout(ticketSearchTimer);

        ticketSearchTimer = setTimeout(async () => {
            // 1) AUTO-OPEN: prova prima exact (se è un codice completo, o se è lungo abbastanza)
            //    - Codice completo: T.... o R_....
            //    - Scanner barcode spesso mette tutto in una volta -> qui lo intercettiamo senza Enter
            const looksFull = isFullTicketCode(q) || isFullReceiptCode(q);

            // opzionale: se è "quasi completo" (lungo), proviamo comunque exact
            const tryExact = looksFull || q.length >= 10;

            if (tryExact) {
                // evita loop/duplicati
                if (!autoOpenInFlight && q !== lastAutoOpenedQuery) {
                    autoOpenInFlight = true;
                    try {
                        const url = `${API_BASE}/search_code.php?q=${encodeURIComponent(q)}&mode=exact&limit=2&t=${Date.now()}`;
                        const resp = await fetch(url, { cache: 'no-store' });
                        if (resp.ok) {
                            const res = await resp.json();
                            const results = (res.data && Array.isArray(res.data.results)) ? res.data.results : [];

                            if (res.success && results.length === 1) {
                                // ✅ trovato match unico -> apri SUBITO
                                lastAutoOpenedQuery = q;
                                hideTicketSearchDropdown();
                                await applySearchResult(results[0]);
                                autoOpenInFlight = false;
                                return; // stop: già aperto
                            }
                        }
                    } catch (err) {
                        // non bloccare: se exact fallisce, passiamo ad autocomplete
                        console.warn('auto-open exact failed:', err.message);
                    } finally {
                        autoOpenInFlight = false;
                    }
                }
            }

            // 2) AUTOCOMPLETE: per parziali (>=2)
            if (q.length >= 2) {
                runTicketSearchAutocomplete(q);
            } else {
                hideTicketSearchDropdown();
            }
        }, 180);
    });

    // //// VECCHIO: solo Enter cercava exact
    // searchTicketInput.addEventListener('keydown', (e) => {
    //     if (e.key === 'Enter') {
    //         e.preventDefault();
    //         handleTicketSearch();
    //         return;
    //     }
    //     if (e.key === 'Escape') {
    //         hideTicketSearchDropdown();
    //         return;
    //     }
    // });

    // ✅ resta utile: Enter forza ricerca / Esc chiude
    searchTicketInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            handleTicketSearch(); // fallback manuale
            return;
        }
        if (e.key === 'Escape') {
            hideTicketSearchDropdown();
            return;
        }
    });

    // click fuori = chiudi dropdown
    document.addEventListener('click', (e) => {
        const dd = document.getElementById('ticketSearchDropdown');
        if (!dd) return;
        if (e.target === searchTicketInput) return;
        if (dd.contains(e.target)) return;
        hideTicketSearchDropdown();
    });

    // reposition su resize/scroll
    window.addEventListener('resize', () => positionTicketSearchDropdown());
    window.addEventListener('scroll', () => positionTicketSearchDropdown(), true);
}

// ================== RICERCA TICKET/RICEVUTA CON ROLLUP ==================
let ticketSearchTimer = null;
// evita auto-open ripetuti mentre l'input non cambia
let lastAutoOpenedQuery = '';
let autoOpenInFlight = false;

function ensureTicketSearchDropdown() {
  let dd = document.getElementById('ticketSearchDropdown');
  if (dd) return dd;

  dd = document.createElement('div');
  dd.id = 'ticketSearchDropdown';

  // STILE “hard” per non farlo sparire dietro o rimanere non posizionato
  dd.style.position = 'absolute';
  dd.style.zIndex = '999999';
  dd.style.background = '#fff';
  dd.style.border = '1px solid #ddd';
  dd.style.borderRadius = '6px';
  dd.style.boxShadow = '0 8px 24px rgba(0,0,0,.12)';
  dd.style.display = 'none';
  dd.style.maxHeight = '280px';
  dd.style.overflowY = 'auto';
  dd.style.minWidth = '260px';

  document.body.appendChild(dd);
  return dd;
}

function positionTicketSearchDropdown() {
  const input = document.getElementById('searchTicketInfo');
  const dd = ensureTicketSearchDropdown();
  if (!input || !dd) return;

  const r = input.getBoundingClientRect();

  dd.style.left = (window.scrollX + r.left) + 'px';
  dd.style.top  = (window.scrollY + r.bottom + 6) + 'px';
  dd.style.width = r.width + 'px';
}

function hideTicketSearchDropdown() {
    const dd = document.getElementById('ticketSearchDropdown');
    if (dd) dd.style.display = 'none';
}

function formatSearchRow(row) {
    const code = row.code || '';
    const type = row.code_type === 'receipt' ? 'RICEVUTA' : 'TICKET';
    const plate = row.plate_id ? `targa#${row.plate_id}` : '';
    const passage = row.passage_id ? `passaggio#${row.passage_id}` : '';
    const extra = [plate, passage].filter(Boolean).join(' · ');

    // se dal ticket abbiamo ricevuta collegata, mostro anche quella
    const receipt = row.receipt_code ? ` → ${row.receipt_code}` : '';

    return `${type}: ${code}${receipt}${extra ? '  (' + extra + ')' : ''}`;
}

async function fetchSearchSuggestions(q) {
    const url = `${API_BASE}/search_code.php?q=${encodeURIComponent(q)}&mode=prefix&limit=10&t=${Date.now()}`;
    const resp = await fetch(url, { cache: 'no-store' });
    if (!resp.ok) throw new Error('HTTP ' + resp.status);
    return resp.json();
}

async function runTicketSearchAutocomplete(q) {
  const dd = ensureTicketSearchDropdown();
  if (!q || q.trim().length < 2) {
    dd.style.display = 'none';
    return;
  }

  positionTicketSearchDropdown();
  dd.innerHTML = `<div style="padding:10px;color:#666;">Ricerca...</div>`;
  dd.style.display = 'block';

  try {
    const url = `${API_BASE}/search_plate.php?q=${encodeURIComponent(q.trim())}&limit=20&t=${Date.now()}`;
    const resp = await fetch(url, { cache: 'no-store' });
    if (!resp.ok) throw new Error('HTTP ' + resp.status);
    const res = await resp.json();

    if (!res.success) {
      dd.innerHTML = `<div style="padding:10px;color:#b00;">${res.message || 'Errore ricerca'}</div>`;
      return;
    }

    const results = Array.isArray(res.data) ? res.data : [];
    if (results.length === 0) {
      dd.innerHTML = `<div style="padding:10px;color:#666;">Nessun risultato</div>`;
      return;
    }

    dd.innerHTML = results.map((row, idx) => {
      const isPassage = Number(row.is_passage) === 1;
      const main = (row.plate_corrected || row.plate_number || '').toString().toUpperCase();
      const when = row.date_detected ? new Date(String(row.date_detected).replace(' ', 'T')).toLocaleString('it-IT') : '';
      const meta = [
        isPassage ? `🚶 passaggio#${row.passage_id}` : `🚗 targa#${row.id}`,
        row.ticket_code ? `🎫 ${row.ticket_code}` : '',
        when ? `🕒 ${when}` : ''
      ].filter(Boolean).join(' · ');

      const safeMain = main.replace(/</g, '&lt;').replace(/>/g, '&gt;');
      const safeMeta = meta.replace(/</g, '&lt;').replace(/>/g, '&gt;');

      return `
        <div class="ticket-search-item"
             data-idx="${idx}"
             style="padding:10px;cursor:pointer;border-bottom:1px solid #eee;">
          <div style="font-weight:700;">${safeMain || '(senza targa)'}</div>
          <div style="font-size:11px;color:#666;margin-top:2px;">${safeMeta}</div>
        </div>
      `;
    }).join('');

    dd.querySelectorAll('.ticket-search-item').forEach(el => {
      el.addEventListener('click', async () => {
        const idx = parseInt(el.dataset.idx, 10);
        const row = results[idx];
        hideTicketSearchDropdown();

        if (Number(row.is_passage) === 1 && row.passage_id) {
          await selectPassage(parseInt(row.passage_id, 10));
        } else if (row.id) {
          await selectPlate(parseInt(row.id, 10));
        } else {
          showToast('⚠️ Risultato non apribile', 'warning', 2500);
        }

        const input = document.getElementById('searchTicketInfo');
        if (input) input.value = '';
      });
    });
  } catch (e) {
    dd.innerHTML = `<div style="padding:10px;color:#b00;">Errore: ${e.message}</div>`;
  }
}

async function applySearchResult(row) {
    // apre subito la scheda associata
    if (row.passage_id) {
        await selectPassage(parseInt(row.passage_id, 10));
    } else if (row.plate_id) {
        await selectPlate(parseInt(row.plate_id, 10));
    } else if (row.receipt_passage_id) {
        await selectPassage(parseInt(row.receipt_passage_id, 10));
    } else if (row.receipt_plate_id) {
        await selectPlate(parseInt(row.receipt_plate_id, 10));
    } else {
        showToast('⚠️ Codice trovato ma non associato a targa/passaggio', 'warning', 4000);
        return;
    }

    // ✅ dopo apertura scheda, libera il campo per prossimo inserimento
    const input = document.getElementById('searchTicketInfo');
    if (input) input.value = '';

    hideTicketSearchDropdown();

    // opzionale: info ricevuta se c’è
    if (row.receipt_code) {
        showToast(`🧾 Ricevuta associata: ${row.receipt_code}`, 'info', 3000);
    }
}
function bindSearchDaysAutoRefresh() {
  const daysEl = document.getElementById('searchDays');
  if (!daysEl) return;

  // evita doppio bind
  if (daysEl.dataset.boundAutoRefresh === '1') return;
  daysEl.dataset.boundAutoRefresh = '1';

  const refreshKeepSelection = () => {
    // 1) prova a capire cosa è aperto ora
    const openType = (typeof getOpenSheetType === 'function') ? getOpenSheetType() : 'unknown';

    const keepPlateId = parseInt(document.getElementById('plateId')?.value || '0', 10);
    const keepPassageId =
      (typeof selectedPassageId !== 'undefined' && selectedPassageId) ? parseInt(selectedPassageId, 10) : 0;

    // 2) reload lista (colonna sinistra)
    if (typeof loadPlates !== 'function') return;

    loadPlates(() => {
      // 3) ripristina selezione (best-effort)
      try {
        if (openType === 'plate' && keepPlateId && typeof selectPlate === 'function') {
          selectPlate(keepPlateId);
        } else if (openType === 'passage' && keepPassageId) {
          // dipende da come selezioni un passaggio nel tuo UI
          // prova: se esiste selectPassage, usala; altrimenti lascia perdere
          if (typeof selectPassage === 'function') {
            selectPassage(keepPassageId);
          } else if (typeof renderPassageDetails === 'function' && keepPassageId) {
            // fallback: ricarica dati passaggio e renderizza
            fetch(`${API_BASE}/get_passage.php?id=${keepPassageId}&t=${Date.now()}`, { cache: 'no-store' })
              .then(r => r.json())
              .then(j => { if (j.success) renderPassageDetails(j.data); });
          }
        }
      } catch (e) {
        console.warn('refreshKeepSelection warning:', e);
      }
    });
  };

  daysEl.addEventListener('change', refreshKeepSelection);
  daysEl.addEventListener('input', refreshKeepSelection);
}

document.addEventListener('DOMContentLoaded', bindSearchDaysAutoRefresh);
// fallback se searchDays viene creato dopo
setTimeout(bindSearchDaysAutoRefresh, 300);
// ================== CARICA TARGHE + PASSAGGI DA API ==================
// ================== CARICA TARGHE + PASSAGGI DA API ==================
/**
 * PATCH: AGGIUNTO parametro callback! Viene eseguito DOPO la lista aggiornata.
 * 
 * @param {function} [callback] - funzione da eseguire al termine del caricamento & update
 */
async function loadPlates(callback, dedupeByPlateNumber = false) { // PATCH: aggiunto parametro callback! (+ flag dedupe opzionale)
    const daysEl = document.getElementById('searchDays');
    const days = daysEl ? (parseInt(daysEl.value, 10) || 5) : 5;

// ✅ garantisce che la colonna sinistra resti su "Targhe"
     selectedIsPassage = false;

    // ✅ PATCH: stato DB "sto leggendo"
    if (typeof setDbStatus === 'function') setDbStatus('wait');

    try {
        const url = `${API_BASE}/get_plates.php?days=${days}&sort=desc&t=${Date.now()}`;
        console.log('loadPlates: fetch', url);
        const response = await fetch(url, { cache: 'no-store' });
        console.log('loadPlates: status', response.status);
        if (!response.ok) throw new Error('HTTP ' + response.status);

        const data = await response.json();
        console.log(
            'loadPlates: data.success=',
            data.success,
            'count=',
            data.count,
            'len=',
            Array.isArray(data.data) ? data.data.length : 'n/a'
        );

        if (data.success && Array.isArray(data.data)) {
            // ✅ PATCH: lettura DB OK
            if (typeof setDbStatus === 'function') setDbStatus('ok');

            // ✅ ORDINA PER DATA/ORA (DECRESCENTE - più nuovi in alto)
            allPlates = data.data.sort((a, b) => {
                const dateA = a.is_passage === 1 
                    ? (a.entry_datetime || a.created_at || a.date_detected || '')
                    : (a.date_detected || a.created_at || '');
                
                const dateB = b.is_passage === 1 
                    ? (b.entry_datetime || b.created_at || b.date_detected || '')
                    : (b.date_detected || b.created_at || '');
                
                return new Date(dateB) - new Date(dateA);
            });
            
            console.log('loadPlates: allPlates.length =', allPlates.length);

            // ===== PATCH #4: deduplica plate_number mostrando solo la versione "prioritaria" =====
            // FIX: questa deduplica NASCONDE volutamente i duplicati (stesso plate_number).
            // Per il tuo caso (vuoi vedere anche rilevate + manuali con stesso nome) deve essere disattivata.
            if (dedupeByPlateNumber === true) {
                const plateMap = new Map();

                // FIX: priorità basata su campi REALI restituiti da get_plates.php
                // - annullato: Tannullato o Pannullato
                // - pagato: Tpaid o Ppaid o paid (passaggi)
                // Nota: qui assegniamo priorità più ALTA a:
                //   2 = annullato (se vuoi nasconderli/mostrarli prima puoi invertire),
                //   1 = pagato,
                //   0 = non pagato
                const getPriority = (p) => {
                    const isAnnullato =
                        (Number(p.Tannullato) === 1) || (Number(p.Pannullato) === 1);
                    const isPagato =
                        (Number(p.Tpaid) === 1) || (Number(p.Ppaid) === 1) || (Number(p.paid) === 1);

                    return isAnnullato ? 2 : (isPagato ? 1 : 0);
                };

                for (const plate of allPlates) {
                    // FIX: non deduplicare i passaggi "🚶 PASSAGGIO N" con chiave plate_number,
                    // altrimenti rischi collisioni/accorpamenti indesiderati.
                    if (plate.is_passage === 1) {
                        // chiave univoca passaggio: usa id
                        plateMap.set(`passage:${plate.id}`, plate);
                        continue;
                    }

                    const key = (plate.plate_number || '').trim().toUpperCase();
                    if (!plateMap.has(key)) {
                        plateMap.set(key, plate);
                    } else {
                        const existing = plateMap.get(key);
                        if (getPriority(plate) > getPriority(existing)) {
                            plateMap.set(key, plate);
                        }
                    }
                }

                allPlates = Array.from(plateMap.values());
                console.log('loadPlates: dedupe attiva => allPlates.length =', allPlates.length);
            } else {
                console.log('loadPlates: dedupe DISATTIVA => duplicati plate_number visibili');
            }
            // ===== FINE PATCH #4 =====

            if (typeof updatePlatesList === 'function') {
                console.log('loadPlates: chiamo updatePlatesList');
                updatePlatesList(allPlates);
            } else {
                console.warn('loadPlates: updatePlatesList NON è una funzione');
            }

            if (typeof updateStats === 'function') {
                updateStats(data.count ?? allPlates.length);
            }

            if (typeof callback === 'function') {
                console.log('loadPlates: eseguo callback post-aggiornamento');
                setTimeout(() => callback(), 0);
            }
        } else {
            // ✅ PATCH: risposta API non valida => DB BAD
            if (typeof setDbStatus === 'function') setDbStatus('bad');

            allPlates = [];
            if (typeof updatePlatesList === 'function') updatePlatesList(allPlates);
            if (typeof updateStats === 'function') updateStats(0);

            if (typeof callback === 'function') setTimeout(() => callback(), 0);
        }
    } catch (error) {
        console.error('❌ Errore caricamento targhe/passaggi:', error);

        // ✅ PATCH: errore rete/API => DB BAD
        if (typeof setDbStatus === 'function') setDbStatus('bad');

        showToast('❌ Errore caricamento elenco', 'error', 3000);

        if (typeof callback === 'function') setTimeout(() => callback(), 0);
    }
}

// ================== AUTO REFRESH LISTA (get_plates.php) ==================
function initAutoRefresh() {
    const refreshSelect = document.getElementById('refreshInterval');
    if (!refreshSelect) return;

    const applyInterval = () => {
        const seconds = parseInt(refreshSelect.value, 10) || 0;

        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
        }

        if (seconds > 0) {
            autoRefreshInterval = setInterval(() => {
                if (!selectPlateLock && !justCreatedManualPlateId) {
                    loadPlates();
                }
            }, seconds * 1000);
        }
    };

    refreshSelect.addEventListener('change', applyInterval);
    applyInterval();
}

// ================== AUTO SCAN CARTELLA (scan_folder.php) ==================
let __scanInFlight = false;

function initAutoScan() {
  const scanSeconds = 5;

  if (autoScanInterval) {
    clearInterval(autoScanInterval);
    autoScanInterval = null;
  }

  autoScanInterval = setInterval(async () => {
    // non disturbare mentre stai creando una targa manuale
    if (justCreatedManualPlateId) return;

    // ✅ evita richieste sovrapposte (causano lock e "skip")
    if (__scanInFlight) return;
    __scanInFlight = true;

    try {
      const url = `${API_BASE}/scan_folder.php?t=${Date.now()}`;
      const response = await fetch(url, { cache: 'no-store' });
      if (!response.ok) return;

      const data = await response.json();

      // se il backend risponde "skipped" = c'è già uno scan in corso → lascia fare e riprova al giro dopo
      if (data?.data?.skipped) return;

      const n = Number(data?.data?.new_plates || 0);
      if (data.success && n > 0) {
        showToast(`📸 ${n} nuove targhe rilevate`, 'success', 2000);

        // ✅ refresh come prima (NON lo abbiamo tolto)
        loadPlates();
      }
    } catch (e) {
      console.error('scan_folder: errore', e?.message || e);
    } finally {
      __scanInFlight = false;
    }
  }, scanSeconds * 1000);
}
// ================== NUOVA TARGA INLINE (input + pulsante) ==================
function initInlineNewPlate() {
    const input = document.getElementById('newPlateInline');
    const btn   = document.getElementById('createNewPlateInline');
    if (!input || !btn) {
        console.warn('Inline new plate: elementi non trovati');
        return;
    }

    const create = async () => {
        let plateNumber = (input.value || '').trim().toUpperCase();

        if (!plateNumber) {
            alert('Inserisci un numero di targa');
            input.focus();
            return;
        }
        if (plateNumber.length > 10) {
            alert('La targa può avere al massimo 10 caratteri');
            input.focus();
            return;
        }

        try {
            showToast('⏳ Creazione targa manuale...', 'info', 2000);

            const response = await fetch(`${API_BASE}/create_manual_plate.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ plate_number: plateNumber })
            });

            const result = await response.json();

            if (!result.success) {
                showToast('❌ ' + (result.message || 'Errore creazione targa manuale'), 'error', 4000);
                return;
            }

            const nowIso = result.date_detected || new Date().toISOString();

            /*
            // ========== PATCH: COMMENTO tutto il vecchio codice che causa duplicati ==========
            const newPlate = {
                id: result.plate_id,
                plate_number: plateNumber,
                plate_corrected: plateNumber,
                date_detected: nowIso,
                is_manual: 1,
                is_passage: 0,
                passage_id: null,
                ticket_code: null
            };

            allPlates.unshift(newPlate);
            if (typeof updatePlatesList === 'function') {
                updatePlatesList(allPlates);
            }
            if (typeof updateStats === 'function') {
                updateStats(allPlates.length);
            }

            selectedPlateId = newPlate.id;
            selectedIsPassage = false;  // ✅ È UNA TARGA
            selectPlateLock = true;
            if (typeof renderDetails === 'function') {
                renderDetails(newPlate);
            }
            if (typeof loadTicketData === 'function' && typeof updateFormWithTicketData === 'function') {
                loadTicketData(newPlate.id).then(() => {
                    updateFormWithTicketData(newPlate);
                });
            }
            // ========== FINE VECCHIO CODICE ==========
            */

            input.value = '';

            // === PATCH: aggiorna solo dal backend, elimina ogni duplicato e seleziona la nuova targa ===
            loadPlates(() => selectPlate(result.plate_id));
            // === FINE PATCH ===

            justCreatedManualPlateId = result.plate_id;
            setTimeout(() => {
                justCreatedManualPlateId = null;
            }, 10000);

            showToast('✅ Targa manuale creata', 'success', 3000);
        } catch (err) {
            console.error('Errore creazione targa manuale:', err);
            showToast('❌ Errore creazione targa manuale', 'error', 4000);
        }
    };

    btn.addEventListener('click', (e) => {
        e.preventDefault();
        create();
    });

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            create();
        }
    });
}

// ================== FASCIA POPUP ==================
/**
 * Mostra un popup per la selezione della fascia tarifaria.
 * Ritorna una Promise<string|null>: la fascia selezionata (es: 'F1') oppure null se annullato.
 */
function showFasciaPopup(defaultFascia) {
    return new Promise((resolve) => {
        // Lista fasce da costanti (F1-F5)
        const fasce = [
            { id: 'F1', label: window.COSTANTI?.TestoF1 || 'Auto piccola' },
            { id: 'F2', label: window.COSTANTI?.TestoF2 || 'Auto media' },
            { id: 'F3', label: window.COSTANTI?.TestoF3 || 'Auto grande' },
            { id: 'F4', label: window.COSTANTI?.TestoF4 || 'Auto Lusso' },
            { id: 'F5', label: window.COSTANTI?.TestoF5 || 'Furgone' },
        ];
        const def = defaultFascia || 'F1';

        // Rimuovi popup precedente se esiste
        const existing = document.getElementById('fasciaPopupModal');
        if (existing) existing.remove();

        const modal = document.createElement('div');
        modal.id = 'fasciaPopupModal';
        modal.className = 'modal show';
        modal.style.cssText = 'z-index:9999;';

        const optionsHtml = fasce.map(f => `
            <label style="display:flex;align-items:center;padding:10px 12px;border:2px solid ${f.id === def ? '#10b981' : '#e5e7eb'};
                border-radius:8px;cursor:pointer;background:${f.id === def ? '#ecfdf5' : '#fff'};transition:all 0.15s;"
                id="fasciaLbl_${f.id}">
                <input type="radio" name="fasciaSelect" value="${f.id}" ${f.id === def ? 'checked' : ''}
                    style="margin-right:10px;accent-color:#10b981;">
                <span style="font-weight:600;color:#1f2937;">${f.id}</span>
                <span style="margin-left:8px;color:#4b5563;">— ${f.label}</span>
            </label>
        `).join('');

        modal.innerHTML = `
            <div class="modal-content" style="max-width:420px;width:95vw;gap:0;align-items:stretch;">
                <h3 style="margin:0 0 16px;color:#1f2937;font-size:1.1rem;">📋 Seleziona Fascia Tarifaria</h3>
                <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:20px;">
                    ${optionsHtml}
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end;">
                    <button id="fasciaPopupCancel" class="btn" style="background:#e5e7eb;color:#374151;">Annulla</button>
                    <button id="fasciaPopupConfirm" class="btn btn-primary" style="background:#10b981;">✅ Conferma</button>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        // Highlight on click
        modal.querySelectorAll('input[name="fasciaSelect"]').forEach(radio => {
            radio.addEventListener('change', () => {
                modal.querySelectorAll('label[id^="fasciaLbl_"]').forEach(lbl => {
                    lbl.style.borderColor = '#e5e7eb';
                    lbl.style.background = '#fff';
                });
                const lbl = document.getElementById('fasciaLbl_' + radio.value);
                if (lbl) { lbl.style.borderColor = '#10b981'; lbl.style.background = '#ecfdf5'; }
            });
        });

        document.getElementById('fasciaPopupConfirm').onclick = () => {
            const selected = modal.querySelector('input[name="fasciaSelect"]:checked');
            modal.remove();
            resolve(selected ? selected.value : def);
        };

        document.getElementById('fasciaPopupCancel').onclick = () => {
            modal.remove();
            resolve(null);
        };

        modal.addEventListener('click', (e) => {
            if (e.target === modal) { modal.remove(); resolve(null); }
        });
    });
}

// ================== EMETTI TICKET ==================
async function emitTicket() {
    console.log("emitTicket CALLED", { selectedPlateId, selectedIsPassage }, new Error().stack);
    try {
        // ✅ SE NULLA È SELEZIONATO, COMPORTATI COME PASSAGGIO (plate_id = 0)
        let plateId = 0;
        let isPassageMode = true;
        let selectedFascia = null;

        // ✅ SE È UNA TARGA, CONTROLLA SE HA GIÀ UN TICKET
        if (selectedPlateId && selectedPlateId !== null && selectedPlateId !== undefined && !selectedIsPassage) {
            const plate = allPlates.find(p => p.id === selectedPlateId);
            if (plate && plate.ticket_code && plate.ticket_code.trim() !== '') {
                showToast('⚠️ Questa targa ha già un ticket: ' + plate.ticket_code, 'warning', 4000);
                console.log('🚫 Targa già con ticket:', plate);
                return;
            }

            plateId = selectedPlateId;
            isPassageMode = false;

            // ✅ SE HA TESSERA PREPAGATA ATTIVA: usa fascia dalla tessera automaticamente
            if (plate && plate.tessera_attiva) {
                try {
                    const plateNumber = (plate.plate_corrected || plate.plate_number || '').trim();
                    const tesseraResponse = await fetch(`${API_BASE}/modulo5_tessera_get.php?plate_number=${encodeURIComponent(plateNumber)}&t=${Date.now()}`, { cache: 'no-store' });
                    const tesseraJson = await tesseraResponse.json();
                    if (tesseraJson.success && tesseraJson.data && tesseraJson.data.fascias) {
                        selectedFascia = tesseraJson.data.fascias;
                        console.log('💳 Fascia da tessera prepagata:', selectedFascia);
                    }
                } catch (e) {
                    console.warn('⚠️ Errore caricamento tessera fascia:', e);
                }
            }

            // ✅ SE NON HA TESSERA O FASCIA NON TROVATA: chiedi all'utente
            if (!selectedFascia) {
                selectedFascia = await showFasciaPopup('F1');
                if (!selectedFascia) {
                    showToast('❌ Emissione ticket annullata', 'warning', 2000);
                    return;
                }
            }
        }

        // ✅ Se è un passaggio selezionato o nulla, crea nuovo passaggio (plate_id = 0)
        if (selectedIsPassage || !selectedPlateId) {
            plateId = 0;
            isPassageMode = true;

            // ✅ Per i passaggi: chiedi sempre la fascia
            selectedFascia = await showFasciaPopup('F1');
            if (!selectedFascia) {
                showToast('❌ Emissione ticket annullata', 'warning', 2000);
                return;
            }
        }

        console.log('🎫 emitTicket: plateId =', plateId, 'isPassageMode =', isPassageMode, 'fascia =', selectedFascia);

        showToast('⏳ Emissione ticket in corso...', 'info', 2000);

     const response = await fetch(`${API_BASE}/emit_ticket.php`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    plate_id: plateId,
    fascia: selectedFascia || 'F1',

    // ✅ NEW: per targhe manuali, usa l'ingresso inserito in scheda (se presente)
    // Se i campi non esistono o sono vuoti, il backend userà il fallback (date_detected).
    entry_date: document.getElementById('entryDate')?.value || null,
    entry_time: document.getElementById('entryTime')?.value || null
  })
});

        const result = await response.json();

        if (!result.success) {
            showToast(result.message || '❌ Errore emissione ticket', 'error', 4000);
            console.error('emitTicket error:', result);
            return;
        }

        const data = result.data;
        showToast(`✅ Ticket emesso: ${data.ticket_code}`, 'success', 4000);
        console.log('emitTicket data:', data);

        // ✅ SE È UNA TARGA, aggiorna UI IMMEDIATAMENTE (elimina "punto 3")
        if (!isPassageMode && selectedPlateId > 0) {
            const plateIdx = allPlates.findIndex(p => p.id === selectedPlateId);
            if (plateIdx !== -1) {
                allPlates[plateIdx].ticket_code = data.ticket_code;
            }

            const ticketCodeInput = document.getElementById('ticketCode');
            if (ticketCodeInput) {
                ticketCodeInput.value = data.ticket_code;
                ticketCodeInput.disabled = true;
                ticketCodeInput.style.background = '#f3f4f6';
                ticketCodeInput.style.color = '#6b7280';
            }

            // ✅ NUOVO: ricarica la lista e RI-SELEZIONA la targa,
            // così la terza colonna si aggiorna senza ricliccare manualmente.
            // (Usiamo la callback già presente in loadPlates)
            loadPlates(() => selectPlate(selectedPlateId));

            // //// VECCHIO: NON ricaricava la lista -> la terza colonna non si aggiornava finché non ricliccavi la targa
            // // ✅ NON ricarica la lista per targhe - evita duplicati

            // Mantieni anche l'update_ticket (se ti serve come metadato)
            setTimeout(async () => {
                const ticketInfo = document.getElementById('ticketInfo')?.value || '';

                const currentPlate = allPlates.find(p => p.id === selectedPlateId);
                const plateNumber = currentPlate ? currentPlate.plate_number : (data.plate_number || '');

                await fetch(`${API_BASE}/update_ticket.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        plate_id: selectedPlateId,
                        plate_number: plateNumber,
                        ticket_code: data.ticket_code,
                        ticket_info: ticketInfo,
                        fascia: selectedFascia || data.fascia || ''
                    })
                });
            }, 300);

        } else {
            // ✅ SE È UN PASSAGGIO O NULLA SELEZIONATO, RICARICA LA LISTA
            setTimeout(() => {
                loadPlates();
                selectedIsPassage = false;
                selectedPlateId = null;
            }, 500);
        }

    } catch (err) {
        console.error('❌ emitTicket exception:', err);
        showToast('❌ Errore emissione ticket', 'error', 4000);
    }
}

// ================== RISTAMPA TICKET ==================
// ================== RISTAMPA TICKET ==================
async function reprintTicket() {
    try {
        console.log('🖨️ reprintTicket: selectedPlateId =', selectedPlateId, 'isPassage =', selectedIsPassage);

        // ✅ CONTROLLA SE È STATA SELEZIONATA UNA TARGA/PASSAGGIO
        if (selectedPlateId === null || selectedPlateId === undefined) {
            showToast('⚠️ Seleziona una targa o passaggio prima di ristampare', 'warning', 3000);
            return;
        }

        // ✅ CARICA I DATI DELL'ELEMENTO SELEZIONATO
        let ticketCode = null;
        
        if (selectedIsPassage) {
            // ✅ È UN PASSAGGIO: carica da get_passage.php
            const url = `${API_BASE}/get_passage.php?id=${selectedPlateId}&t=${Date.now()}`;
            const resp = await fetch(url, { cache: 'no-store' });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const data = await resp.json();
            
            if (!data.success || !data.data) {
                showToast('⚠️ Passaggio non trovato', 'error', 3000);
                return;
            }
            
            ticketCode = data.data.ticket_code;
            console.log('🖨️ reprintTicket (PASSAGGIO):', { passageId: selectedPlateId, ticketCode });
        } else {
            // ✅ È UNA TARGA: cerca in allPlates
            const selectedItem = allPlates.find(p => p.id === selectedPlateId);
            if (!selectedItem) {
                showToast('⚠️ Targa non trovata nella lista', 'error', 3000);
                return;
            }
            
            ticketCode = selectedItem.ticket_code;
            console.log('🖨️ reprintTicket (TARGA):', { plateId: selectedPlateId, ticketCode });
        }

        // ✅ CONTROLLA SE HA UN TICKET
        if (!ticketCode || ticketCode.trim() === '') {
            showToast('⚠️ Nessun ticket associato a questo elemento. Emetti prima un ticket.', 'warning', 4000);
            return;
        }

        // ✅ RISTAMPA IL TICKET
        showToast('⏳ Ristampa ticket in corso...', 'info', 2000);

        const response = await fetch(`${API_BASE}/reprint_ticket.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ticket_code: ticketCode,
                plate_id: selectedIsPassage ? null : selectedPlateId,
                passage_id: selectedIsPassage ? selectedPlateId : null
            })
        });

        const result = await response.json();

        console.log('🖨️ reprintTicket response:', result);

        if (!result.success) {
            showToast(result.message || '❌ Errore ristampa ticket', 'error', 4000);
            console.error('reprintTicket error:', result);
            return;
        }

        showToast(`✅ Ticket ristampato: ${ticketCode}`, 'success', 4000);
        console.log('🖨️ reprintTicket success:', result.data);

    } catch (err) {
        console.error('❌ reprintTicket exception:', err);
        showToast('❌ Errore ristampa ticket', 'error', 4000);
    }
}


// ================== SELEZIONE TARGA ==================
async function selectPlate(plateId, event) {
    console.log("selectPlate CALLED", plateId, new Error().stack);
    if (event && event.stopPropagation) {
        event.stopPropagation();
    }

    try {
        selectedPlateId = plateId;
        selectedIsPassage = false;
        selectPlateLock = true;

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

        highlightPlateItemByPlateId(plateId);

        // //// VECCHIO: preferiva renderPlateDetailsWithTimes e quindi tagliava abbonamento/tessera/veicoli autorizzati ecc.
        // if (typeof renderPlateDetailsWithTimes === 'function') {
        //     renderPlateDetailsWithTimes(plate);
        // } else if (typeof renderDetails === 'function') {
        //     renderDetails(plate);
        // } else {
        //     showToast('❌ renderDetails non disponibile', 'error');
        // }

        // ✅ PATCH CONTEXT (NON RIMUOVERE):
        // Rende disponibile l'ID a details.js e a tutte le funzioni "calcola"
        try {
            // 1) Globali (compat con codice esistente)
            window.PLATE_ID = plateId;
            window.PASSAGE_ID = null;

            window.obj = window.obj || {};
            // per le targhe: obj.id = plateId (compat con vecchio details.js)
            window.obj.id = plateId;
            window.obj.plate_id = plateId;
            window.obj.passage_id = null;

            // 2) DOM dataset (robusto, se hai #details-root in index.html)
            const root = document.getElementById('details-root');
            if (root) {
                root.dataset.plateId = String(plateId);
                root.dataset.passageId = '';
                // opzionale: compat se qualcuno legge dataset.id
                root.dataset.id = String(plateId);
            }

            // 3) se esiste la sync helper, chiamala (non fa danni)
            if (window.ANPR_syncDetailsIds) window.ANPR_syncDetailsIds();
        } catch (e) {
            console.error('sync details context failed (plate)', e);
        }

        // ✅ NUOVO: per le TARGHE usa SEMPRE renderDetails (scheda completa con tutte le sezioni)
        if (typeof renderDetails === 'function') {
            renderDetails(plate);
        } else if (typeof renderPlateDetailsWithTimes === 'function') {
            // fallback solo se renderDetails non esiste
            renderPlateDetailsWithTimes(plate);
        } else {
            showToast('❌ Nessuna funzione render disponibile (renderDetails / renderPlateDetailsWithTimes)', 'error');
        }

    } catch (err) {
        console.error('❌ selectPlate error:', err);
        showToast('❌ Errore caricamento targa', 'error', 3000);
    }
}

// ================== SELEZIONE PASSAGGIO ==================
async function selectPassage(passageId, event) {
  if (event && event.stopPropagation) event.stopPropagation();

  // salva stato precedente per ripristino se fallisce
  const prevSelectedPlateId = selectedPlateId;
  const prevSelectedIsPassage = selectedIsPassage;
  const prevSelectPlateLock = selectPlateLock;

  try {
    // NON bloccare/settare selezione prima di sapere che esiste
    const url = `${API_BASE}/get_passage.php?id=${encodeURIComponent(passageId)}&t=${Date.now()}`;
    console.log('🚶 selectPassage: carico da', url);

    const resp = await fetch(url, { cache: 'no-store' });
    if (!resp.ok) throw new Error('HTTP ' + resp.status);

    const data = await resp.json();
    console.log('🔍 GET_PASSAGE risposta:', data);

    if (!data?.success || !data.data) {
      showToast(data?.message || 'Passaggio non trovato', 'warning', 2500);
      // ripristina selezione precedente (non lasciare UI in uno stato “mezzo selezionato”)
      selectedPlateId = prevSelectedPlateId;
      selectedIsPassage = prevSelectedIsPassage;
      return;
    }

    // ✅ SOLO ORA aggiorna lo stato di selezione e blocco
    selectedPlateId = passageId;
    selectedIsPassage = true;
    selectPlateLock = true;

    const passage = data.data;
    console.log('✅ Passaggio caricato completo:', passage);

    highlightPlateItemByPassageId(passageId);

    // ✅ PATCH CONTEXT (NON RIMUOVERE)
    try {
      window.PASSAGE_ID = passageId;
      window.PLATE_ID = null;

      window.obj = window.obj || {};
      window.obj.id = passageId;
      window.obj.passage_id = passageId;
      window.obj.plate_id = null;

      const root = document.getElementById('details-root');
      if (root) {
        root.dataset.passageId = String(passageId);
        root.dataset.plateId = '';
        root.dataset.id = String(passageId);
      }

      if (window.ANPR_syncDetailsIds) window.ANPR_syncDetailsIds();
    } catch (e) {
      console.error('sync details context failed (passage)', e);
    }

    if (typeof renderPassageDetails === 'function') {
      renderPassageDetails(passage);
    } else {
      showToast('❌ renderPassageDetails non disponibile', 'error');
    }

  } catch (err) {
    console.error('❌ selectPassage error:', err);
    showToast('❌ Errore caricamento passaggio', 'error', 3000);

    // ripristina anche qui
    selectedPlateId = prevSelectedPlateId;
    selectedIsPassage = prevSelectedIsPassage;

  } finally {
    // ✅ IMPORTANTISSIMO: non lasciare il lock attivo se qualcosa va storto
    // Se vuoi mantenerlo attivo SOLO dopo successo, allora mettilo true solo nel ramo success,
    // e qui lo rimetti al valore precedente se non è andata a buon fine.
    if (selectedPlateId !== passageId) {
      selectPlateLock = prevSelectPlateLock;
    }
  }
}

function highlightPlateItemByPlateId(plateId) {
    document.querySelectorAll('.plate-item').forEach(el => {
        el.classList.remove('active');
        if (el.dataset.isPassage !== '1' && parseInt(el.dataset.plateId, 10) === plateId) {
            el.classList.add('active');
        }
    });
}

function highlightPlateItemByPassageId(passageId) {
    document.querySelectorAll('.plate-item').forEach(el => {
        el.classList.remove('active');
        if (el.dataset.isPassage === '1' && parseInt(el.dataset.passageId, 10) === passageId) {
            el.classList.add('active');
        }
    });
}



// ================== AVVIO APP ==================
document.addEventListener('DOMContentLoaded', () => {
    console.log('✅ DOM Loaded');

    if (typeof setupGlobalListeners === 'function') {
        setupGlobalListeners();
    }
    
 //   if (typeof initPlateSearch === 'function') {
  //      initPlateSearch();
  //  }

    loadPlates();

    initAutoRefresh();

    initAutoScan();

    initInlineNewPlate();

    initTicketSearch();

    // ===== EMETTI TICKET =====
    const emitBtn = document.getElementById('emitTicketBtn');
    if (emitBtn) {
        emitBtn.addEventListener('click', () => {
            emitTicket();
        });
    }

    // ===== RISTAMPA TICKET =====
    const reprintBtn = document.getElementById('reprintTicketBtn');
    if (reprintBtn) {
        reprintBtn.addEventListener('click', () => {
            reprintTicket();
        });
    }
	

    // ===== RICERCA TICKET =====
    const searchBtn = document.getElementById('searchBtn');
    if (searchBtn) {
        searchBtn.addEventListener('click', () => {
            handleTicketSearch();
        });
    }
});
function startHeaderClock() {
  const el = document.getElementById('headerDateTime');
  if (!el) return;

  const fmt = new Intl.DateTimeFormat('it-IT', {
    weekday: 'long',
    day: '2-digit',
    month: 'long',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });

  const tick = () => {
    let s = fmt.format(new Date());

    // ✅ normalizza: toglie virgole e spazi doppi
    s = s.replace(',', ' ').replace(/\s+/g, ' ').trim();

    // ✅ rimuove qualunque "alle" / "alle ore" se qualche altro script lo aggiunge
    // Esempi rimossi:
    // "Martedì ... alle ore 16:52" -> "Martedì ... 16:52"
    // "Martedì ... alle 16:52"     -> "Martedì ... 16:52"
    s = s.replace(/\s+alle(\s+ore)?\s+/i, ' ');

    // ✅ prima lettera maiuscola
    s = s.charAt(0).toUpperCase() + s.slice(1);

    el.textContent = s;
  };

  tick();
  setInterval(tick, 30 * 1000);
}

// IMPORTANT: assicurati che sia registrata UNA SOLA VOLTA
document.addEventListener('DOMContentLoaded', startHeaderClock);

function setDbStatus(state) {
  const wrap = document.getElementById('dbStatusWrap');
  if (!wrap) return;

  // non cambiare mai testo => niente vibrazioni
  wrap.classList.remove('db-ok', 'db-wait', 'db-bad');

  if (state === 'ok') {
    wrap.classList.add('db-ok');
  } else if (state === 'wait') {
    wrap.classList.add('db-wait');
  } else {
    wrap.classList.add('db-bad');
  }
}
