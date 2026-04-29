// modulo1.js - 🎫 Ticket (read-only)
// Espone window.Modulo1.mount(container, ctx)

window.Modulo1 = window.Modulo1 || {};

window.Modulo1.mount = async function mountModulo1(container, ctx) {
  if (!container) return;

  container.innerHTML = `
    <div class="form-group">
      <label>Caricamento ticket...</label>
    </div>
  `;

  try {
    const plateId = ctx?.plate_id;
    if (!plateId) {
      container.innerHTML = `<div class="form-group"><label>plate_id mancante</label></div>`;
      return;
    }

    const resp = await fetch(`${API_BASE}/modulo1_ticket_get.php?plate_id=${encodeURIComponent(plateId)}&t=${Date.now()}`, { cache: 'no-store' });
    const j = await resp.json();

    if (!j.success) {
      container.innerHTML = `<div class="form-group"><label>${j.message || 'Ticket non disponibile'}</label></div>`;
      return;
    }

    const t = j.data || {};
    container.innerHTML = `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div class="form-group">
          <label>ID (progressivo)</label>
          <input type="text" value="${t.id ?? ''}" readonly>
        </div>

        <div class="form-group">
          <label>Codice Ticket</label>
          <input type="text" value="${t.ticket_code ?? ''}" readonly>
        </div>

        <div class="form-group">
          <label>Entrata</label>
          <input type="text" value="${t.entry_datetime ?? ''}" readonly>
        </div>

        <div class="form-group">
          <label>Uscita</label>
          <input type="text" value="${t.exit_datetime ?? ''}" readonly>
        </div>

        <div class="form-group">
          <label>Plate ID</label>
          <input type="text" value="${t.plate_id ?? ''}" readonly>
        </div>

        <div class="form-group">
          <label>Targa</label>
          <input type="text" value="${t.plate_number ?? ''}" readonly>
        </div>
      </div>
    `;
  } catch (e) {
    container.innerHTML = `<div class="form-group"><label>Errore: ${(e && e.message) ? e.message : e}</label></div>`;
  }
};