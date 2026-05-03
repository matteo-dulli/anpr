// ================== CONFIG GLOBALE ==================
console.log('🚀 app.js caricato (config globale)');

// ✅ usa il valore bootstrappato da index.html, con fallback
const API_BASE = window.API_BASE || '/anpr/api';

// ================== HELPERS RICERCA (mancanti) ==================
// Normalizza input: trim, uppercase, rimuove spazi multipli
function normalizeSearchInput(raw) {
  let q = (raw ?? '').toString().trim().toUpperCase();
  // evita caratteri invisibili / newline da barcode scanner
  q = q.replace(/\s+/g, ' ').trim();
  return q;
}

/**
 * Restituisce solo la parte finale del codice ticket (dopo l'ultimo '-').
 * Es: "T20260419-170228-D27BE" -> "D27BE"
 */
function ticketCodeSuffix(code) {
  if (!code) return '';
  const s = String(code);
  const idx = s.lastIndexOf('-');
  return idx !== -1 ? s.slice(idx + 1) : s;
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
let selectedIsPassage = false;
let selectPlateLock = false;
let autoRefreshInterval = null;
let autoScanInterval = null;
let justCreatedManualPlateId = null;
let connectionStartTime = new Date();

// ================== FLAG UM (Ultima Modalità) ==================
// 1 = ricevuta deve mostrare UM (barcode secondario, ricerca targa, selezione manuale, finale primario)
// 0 = ricevuta NON mostra UM (ricerca per codice ticket primario completo)
window.currentUM = 0;

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

      // ✅ Se risultato UNICO (tipico del codice secondario univoco), apri subito senza click
      if (results.length === 1) {
        const row = results[0];

        // Ricerca non-full-code: attiva UM
        window.currentUM = 1;

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
          hideTicketSearchDropdown();
          return;
        } catch (e) {
          console.error('single result open error:', e);
          if (typeof selectedIsPassage !== 'undefined') selectedIsPassage = false;
          showToast('❌ Errore apertura scheda', 'error', 4000);
          return;
        }
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
          row.ticket_code ? `🎫 ${ticketCodeSuffix(row.ticket_code)}` : '',
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

          // Ricerca non-full-code: attiva UM
          window.currentUM = 1;

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
    // Per codice primario completo: UM = 0 (nessuna modalità UM)
    if (isFullTicketCode(query) || isFullReceiptCode(query)) {
      window.currentUM = 0;
      await applySearchResult(results[0]);
      return;
    }

    // Se non è codice completo: UM = 1
    if (results.length === 1) {
      window.currentUM = 1;
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
          // Rollup da ricerca non-full-code: UM = 1
          window.currentUM = 1;
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
    // Per i ticket mostra solo la parte finale del codice
    const displayCode = row.code_type === 'receipt' ? code : ticketCodeSuffix(code);
    const plate = row.plate_id ? `targa#${row.plate_id}` : '';
    const passage = row.passage_id ? `passaggio#${row.passage_id}` : '';
    const extra = [plate, passage].filter(Boolean).join(' · ');

    // se dal ticket abbiamo ricevuta collegata, mostro anche quella
    const receipt = row.receipt_code ? ` → ${row.receipt_code}` : '';

    return `${type}: ${displayCode}${receipt}${extra ? '  (' + extra + ')' : ''}`;
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
        row.ticket_code ? `🎫 ${ticketCodeSuffix(row.ticket_code)}` : '',
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

        // Autocomplete = ricerca non-full-code: UM = 1
        window.currentUM = 1;

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
          if (typeof selectPassage === 'function') {
            selectPassage(keepPassageId);
          } else if (typeof renderPassageDetails === 'function' && keepPassageId) {
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
setTimeout(bindSearchDaysAutoRefresh, 300);

// ================== CARICA TARGHE + PASSAGGI DA API ==================
async function loadPlates(callback, dedupeByPlateNumber = false) {
    const daysEl = document.getElementById('searchDays');
    const days = daysEl ? (parseInt(daysEl.value, 10) || 5) : 5;

    // ✅ garantisce che la colonna sinistra resti su "Targhe"
    selectedIsPassage = false;

    if (typeof setDbStatus === 'function') setDbStatus('wait');

    try {
        const url = `${API_BASE}/get_plates.php?days=${days}&sort=desc&t=${Date.now()}`;
        console.log('loadPlates: fetch', url);
        const response = await fetch(url, { cache: 'no-store' });
        console.log('loadPlates: status', response.status);
        if (!response.ok) throw new Error('HTTP ' + response.status);

        const data = await response.json();

        if (data.success && Array.isArray(data.data)) {
            if (typeof setDbStatus === 'function') setDbStatus('ok');

            allPlates = data.data.sort((a, b) => {
                const dateA = a.is_passage === 1
                    ? (a.entry_datetime || a.created_at || a.date_detected || '')
                    : (a.date_detected || a.created_at || '');

                const dateB = b.is_passage === 1
                    ? (b.entry_datetime || b.created_at || b.date_detected || '')
                    : (b.date_detected || b.created_at || '');

                return new Date(dateB) - new Date(dateA);
            });

            // ✅ COMPAT: esponi la lista anche come globale (serve a details.js/moduli/altre parti)
            window.allPlates = allPlates;

            if (dedupeByPlateNumber === true) {
                const plateMap = new Map();

                const getPriority = (p) => {
                    const isAnnullato = (Number(p.Tannullato) === 1) || (Number(p.Pannullato) === 1);
                    const isPagato = (Number(p.Tpaid) === 1) || (Number(p.Ppaid) === 1) || (Number(p.paid) === 1);
                    return isAnnullato ? 2 : (isPagato ? 1 : 0);
                };

                for (const plate of allPlates) {
                    if (plate.is_passage === 1) {
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
                window.allPlates = allPlates;
            }

            if (typeof updatePlatesList === 'function') {
                updatePlatesList(allPlates);
            }

            if (typeof updateStats === 'function') {
                updateStats(data.count ?? allPlates.length);
            }

            if (typeof callback === 'function') setTimeout(() => callback(), 0);
        } else {
            if (typeof setDbStatus === 'function') setDbStatus('bad');
            allPlates = [];
            window.allPlates = allPlates;
            if (typeof updatePlatesList === 'function') updatePlatesList(allPlates);
            if (typeof updateStats === 'function') updateStats(0);
            if (typeof callback === 'function') setTimeout(() => callback(), 0);
        }
    } catch (error) {
        console.error('❌ Errore caricamento targhe/passaggi:', error);
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
    if (justCreatedManualPlateId) return;
    if (__scanInFlight) return;
    __scanInFlight = true;

    try {
      const url = `${API_BASE}/scan_folder.php?t=${Date.now()}`;
      const response = await fetch(url, { cache: 'no-store' });
      if (!response.ok) return;

      const data = await response.json();
      if (data?.data?.skipped) return;

      const n = Number(data?.data?.new_plates || 0);
      if (data.success && n > 0) {
        showToast(`📸 ${n} nuove targhe rilevate`, 'success', 2000);
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

            input.value = '';

            // aggiorna solo dal backend e seleziona la nuova targa
            loadPlates(() => selectPlate(result.plate_id));

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

// ================== AVVIO APP ==================
document.addEventListener('DOMContentLoaded', () => {
    console.log('✅ DOM Loaded');

    if (typeof setupGlobalListeners === 'function') {
        setupGlobalListeners();
    }

    loadPlates();
    initAutoRefresh();
    initAutoScan();
    initInlineNewPlate();
    initTicketSearch();

    const emitBtn = document.getElementById('emitTicketBtn');
    if (emitBtn) emitBtn.addEventListener('click', () => emitTicket());

    const reprintBtn = document.getElementById('reprintTicketBtn');
    if (reprintBtn) reprintBtn.addEventListener('click', () => reprintTicket());

    const searchBtn = document.getElementById('searchBtn');
    if (searchBtn) searchBtn.addEventListener('click', () => handleTicketSearch());
});
