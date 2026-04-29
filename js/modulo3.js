// modulo3.js - 🗒️ Note ingresso/uscita (Soluzione A robusta: con o senza ticket)
//
// - Se esiste ticket_code: legge/scrive su tickets_printed.note
// - Se NON esiste ticket_code (o ticket non trovato): fallback su plates.note_io (solo scheda corrente / plate_id)
//
// Salvataggio: avviene col tasto "💾 Salva" principale
// (details.js -> handleSave -> savePlateModulesIfPresent)

window.Modulo3 = window.Modulo3 || {};

window.Modulo3.mount = async function mountModulo3(container, ctx) {
  if (!container) return;

  const plateId = ctx?.plate_id;

  container.innerHTML = `
    <div class="form-group">
      <label>Note ingresso/uscita</label>
      <textarea id="m3_note" rows="5" placeholder="Inserisci note per questo evento..."></textarea>
    </div>

    <div style="margin-top:8px;color:#666;font-size:12px;">
      <b>Salvataggio:</b> usa il tasto <b>💾 Salva</b> principale.<br>
      Le note vengono abbinate al ticket generato oppure alla targa in relazione all'orario di ingresso.
    </div>
  `;

  if (!plateId) return;

  // ✅ ticket_code: se c'è, useremo tickets_printed; altrimenti fallback su plates.note_io
  const ticket_code = (ctx?.ticket_code || '').toString().trim();

  // Load note (Soluzione A: non deve fallire se ticket non c'è)
  try {
    // ✅ MODIFICA: entry_date/entry_time rimossi dalla URL perché i tuoi PHP non li usano.
    //             Manteniamo solo plate_id + ticket_code.
    const url =
      `${API_BASE}/modulo3_notes_get.php` +
      `?plate_id=${encodeURIComponent(plateId)}` +
      `&ticket_code=${encodeURIComponent(ticket_code)}` +
      `&t=${Date.now()}`;

    const r = await fetch(url, { cache: 'no-store' });
    const j = await r.json();

    if (j.success) {
      // Soluzione A:
      // - se ticket trovato: j.data.note
      // - se ticket NON trovato: j.data.note_io (fallback)
      const note = (j.data && (j.data.note ?? j.data.note_io)) ?? '';
      const ta = container.querySelector('#m3_note');
      if (ta) ta.value = note;
    } else {
      console.warn('Modulo3 GET not success:', j.message);
      const ta = container.querySelector('#m3_note');
      if (ta) ta.value = '';
    }
  } catch (e) {
    console.warn('Modulo3 load error:', e);
    // non blocco UI
  }

  // NB: nessun listener di salvataggio qui (gestito da details.js)
};