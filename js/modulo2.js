// modulo2.js - 🚗 Veicolo
// ⚠️ PATCH: rimosso pulsante Salva interno, perché si usa il tasto Salva principale (details.js)

window.Modulo2 = window.Modulo2 || {};

window.Modulo2.mount = async function mountModulo2(container, ctx) {
  if (!container) return;

  const plateId = ctx?.plate_id;
  const plateNumber = ctx?.plate_number || '';

  container.innerHTML = `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
      <div class="form-group">
        <label>Tipo</label>
        <input type="text" id="m2_tipo">
      </div>
      <div class="form-group">
        <label>Marca/Modello</label>
        <input type="text" id="m2_marca">
      </div>
      <div class="form-group">
        <label>Colore</label>
        <input type="text" id="m2_colore">
      </div>
      <div class="form-group">
        <label>Posizione (solo scheda corrente)</label>
        <input type="text" id="m2_posizione">
      </div>
      <div class="form-group" style="grid-column:1 / -1;">
        <label>Note veicolo</label>
        <textarea id="m2_notev" rows="3"></textarea>
      </div>
    </div>

    <!--
    ✅ VECCHIO: pulsante salva interno (ORA NON SI USA)
    <div style="display:flex;gap:10px;margin-top:10px;align-items:center;">
      <button class="btn-small" id="m2_save" style="background:#22c55e;color:white;">💾 Salva Veicolo</button>
      <div id="m2_msg" style="font-size:13px;"></div>
    </div>
    -->

    <div style="margin-top:8px;color:#666;font-size:12px;">
      <b>Nota:</b> Tipo/Marca/Colore/Note veicolo aggiornano tutte le targhe <i>${plateNumber || '(non disponibile)'}</i>.
      La Posizione viene salvata solo per questa scheda.
      <br>
      <b>Salvataggio:</b> usa il tasto <b>💾 Salva</b> principale.
    </div>
  `;

  if (!plateId) return;

  // Load
  try {
    const r = await fetch(`${API_BASE}/modulo2_vehicle_get.php?plate_id=${encodeURIComponent(plateId)}&t=${Date.now()}`, { cache: 'no-store' });
    const j = await r.json();
    if (j.success && j.data) {
      container.querySelector('#m2_tipo').value = j.data.tipo || '';
      container.querySelector('#m2_marca').value = j.data.marca || '';
      container.querySelector('#m2_colore').value = j.data.colore || '';
      container.querySelector('#m2_posizione').value = j.data.posizione || '';
      container.querySelector('#m2_notev').value = j.data.notev || '';
    }
  } catch (e) {
    // silenzioso: non blocchiamo UI
    console.warn('Modulo2 load error:', e);
  }

  // ✅ PATCH: non aggiungo listener salva (gestito da details.js)
};