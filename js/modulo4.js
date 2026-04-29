// modulo4.js - 🪪 Abbonamento
// Salvataggio: tasto Salva principale (details.js -> savePlateModulesIfPresent)
// Dati per targa: plate_number

window.Modulo4 = window.Modulo4 || {};

window.Modulo4.mount = async function mountModulo4(container, ctx) {
  if (!container) return;

  const plateNumber = (ctx?.plate_number || '').trim();

  container.innerHTML = `
    <div id="m4_alert" style="margin-bottom:8px;font-weight:600;"></div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
      <div class="form-group">
        <label>Valido dal</label>
        <input type="date" id="m4_inabb">
      </div>
      <div class="form-group">
        <label>Valido al</label>
        <input type="date" id="m4_finabb">
      </div>
      <div class="form-group" style="display:flex;gap:12px;align-items:flex-end;">
        <label style="display:flex;gap:6px;align-items:center;margin:0;">
          <input type="checkbox" id="m4_attivo"> Attivo
        </label>
        <label style="display:flex;gap:6px;align-items:center;margin:0;">
          <input type="checkbox" id="m4_Apay"> Pagato
        </label>
      </div>

      <div class="form-group">
        <label>Tipo abbonamento</label>
        <select id="m4_tipo"></select>
      </div>
      <div class="form-group">
        <label>Prezzo €</label>
        <input type="number" id="m4_prezzo" step="0.01" min="0">
      </div>
      <div class="form-group" style="display:flex;gap:12px;align-items:flex-end;">
        <label style="display:flex;gap:6px;align-items:center;margin:0;">
          <input type="checkbox" id="m4_SpayC"> Contante
        </label>
        <label style="display:flex;gap:6px;align-items:center;margin:0;">
          <input type="checkbox" id="m4_SpayE"> Elettronico
        </label>
      </div>

      <div class="form-group">
        <label>Data pagamento</label>
        <input type="datetime-local" id="m4_Dpay">
      </div>
    </div>

    <hr style="margin:12px 0;">

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
      <div class="form-group">
        <label>Rag. Soc. / Nome Cognome</label>
        <input type="text" id="m4_nome">
      </div>
      <div class="form-group">
        <label>Indirizzo</label>
        <input type="text" id="m4_indirizzo">
      </div>
      <div class="form-group">
        <label>Città</label>
        <input type="text" id="m4_citta">
      </div>
      <div class="form-group">
        <label>CAP</label>
        <input type="text" id="m4_cap">
      </div>
      <div class="form-group">
        <label>Provincia</label>
        <input type="text" id="m4_prov">
      </div>
      <div class="form-group">
        <label>Stato</label>
        <input type="text" id="m4_stato">
      </div>
      <div class="form-group">
        <label>Partita IVA</label>
        <input type="text" id="m4_pi">
      </div>
      <div class="form-group">
        <label>Cod. Fiscale</label>
        <input type="text" id="m4_cf">
      </div>
      <div class="form-group">
        <label>Cod. Univoco</label>
        <input type="text" id="m4_codun">
      </div>
      <div class="form-group" style="grid-column:1 / -1;">
        <label>Altre informazioni</label>
        <textarea id="m4_info" rows="3"></textarea>
      </div>
    </div>

    <div style="margin-top:8px;color:#666;font-size:12px;">
      <b>Salvataggio:</b> usa il tasto <b>💾 Salva</b> principale.
    </div>
  `;

  if (!plateNumber) return;

  // dropdown types
  try {
    const rt = await fetch(`${API_BASE}/modulo4_types_get.php?t=${Date.now()}`, { cache: 'no-store' });
    const jt = await rt.json();
    const sel = container.querySelector('#m4_tipo');
    const types = (jt.success && Array.isArray(jt.data)) ? jt.data : [];
    sel.innerHTML = types.map(x => `<option value="${String(x).replaceAll('"','&quot;')}">${x}</option>`).join('');
  } catch (e) {
    // fallback vuoto
  }

  // load data
  try {
    const r = await fetch(`${API_BASE}/modulo4_abbonamento_get.php?plate_number=${encodeURIComponent(plateNumber)}&t=${Date.now()}`, { cache: 'no-store' });
    const j = await r.json();
    if (j.success && j.data) {
      const d = j.data;

      container.querySelector('#m4_inabb').value = d.inabb || '';
      container.querySelector('#m4_finabb').value = d.finabb || '';
      container.querySelector('#m4_attivo').checked = Number(d.attivo || 0) === 1;

      container.querySelector('#m4_tipo').value = d.tipo || (container.querySelector('#m4_tipo').value || '');
      container.querySelector('#m4_prezzo').value = (d.prezzo != null ? d.prezzo : '');

      container.querySelector('#m4_Apay').checked = Number(d.Apay || 0) === 1;
      container.querySelector('#m4_SpayC').checked = Number(d.SpayC || 0) === 1;
      container.querySelector('#m4_SpayE').checked = Number(d.SpayE || 0) === 1;

      // datetime-local expects YYYY-MM-DDTHH:MM
      container.querySelector('#m4_Dpay').value = (d.Dpay ? String(d.Dpay).replace(' ', 'T').slice(0,16) : '');

      container.querySelector('#m4_nome').value = d.nome || '';
      container.querySelector('#m4_indirizzo').value = d.indirizzo || '';
      container.querySelector('#m4_citta').value = d.citta || '';
      container.querySelector('#m4_cap').value = d.cap || '';
      container.querySelector('#m4_prov').value = d.prov || '';
      container.querySelector('#m4_stato').value = d.stato || '';
      container.querySelector('#m4_pi').value = d.pi || '';
      container.querySelector('#m4_cf').value = d.cf || '';
      container.querySelector('#m4_codun').value = d.codun || '';
      container.querySelector('#m4_info').value = d.info || '';
    }
  } catch (e) {}

  // alert scadenza
  try {
    const fin = container.querySelector('#m4_finabb').value;
    const attivo = container.querySelector('#m4_attivo').checked;

    const alertEl = container.querySelector('#m4_alert');
    alertEl.textContent = '';
    alertEl.style.color = '';

    if (attivo && fin) {
      const today = new Date();
      const dtFin = new Date(fin + 'T00:00:00');
      const diffDays = Math.ceil((dtFin - today) / 86400000);

      if (diffDays < 0) {
        alertEl.textContent = '❌ Abbonamento scaduto';
        alertEl.style.color = '#dc2626';
      } else if (diffDays <= 7) {
        alertEl.textContent = `⚠️ Abbonamento in scadenza (${diffDays} giorni)`;
        alertEl.style.color = '#f59e0b';
      } else {
        alertEl.textContent = '✅ Abbonamento valido';
        alertEl.style.color = '#16a34a';
      }
    }
  } catch (e) {}
};