// modulo6.js - ✅ Autorizzazioni (plates.autor)
// Salvataggio: tasto Salva principale (details.js -> savePlateModulesIfPresent)
//
// ✅ PATCH: compatibilità risposta types:
// - Se backend ritorna array di stringhe ["1 = Residente", ...] -> parsifica in {code,label}
// - Se backend ritorna array di oggetti [{code:"1",label:"Residente"}, ...] -> usa direttamente
// Salviamo il CODE in plates.autor (consigliato)

window.Modulo6 = window.Modulo6 || {};

function _m6ParseItemToCodeLabel(it) {
  // Caso 1: oggetto già pronto
  if (it && typeof it === 'object') {
    const code = (it.code != null) ? String(it.code).trim() : '';
    const label = (it.label != null) ? String(it.label).trim() : code;
    return { code, label };
  }

  // Caso 2: stringa tipo "1 = Residente"
  const s = (it != null) ? String(it).trim() : '';
  if (!s) return { code: '', label: '' };

  if (s.includes('=')) {
    const parts = s.split('=');
    const a = (parts[0] || '').trim();
    const b = (parts.slice(1).join('=') || '').trim();

    const aIsNum = /^\d+$/.test(a);
    const bIsNum = /^\d+$/.test(b);

    if (aIsNum && !bIsNum) return { code: a, label: b };
    if (!aIsNum && bIsNum) return { code: b, label: a };

    // ambiguo: prendo a come code
    return { code: a, label: b || a };
  }

  // fallback: code=label
  return { code: s, label: s };
}

window.Modulo6.mount = async function mountModulo6(container, ctx) {
  if (!container) return;

  const plateId = ctx?.plate_id;

  container.innerHTML = `
    <div class="form-group">
      <label>Categoria autorizzazione</label>
      <select id="m6_autor"></select>
    </div>
    <div style="margin-top:8px;color:#666;font-size:12px;">
      <b>Salvataggio:</b> usa il tasto <b>💾 Salva</b> principale.
    </div>
  `;

  const sel = container.querySelector('#m6_autor');
  if (!sel) return;

  // 1) Load types
  try {
    const r = await fetch(`${API_BASE}/modulo6_types_get.php?t=${Date.now()}`, { cache: 'no-store' });
    const j = await r.json();

    const rawItems = (j.success && Array.isArray(j.data)) ? j.data : [];
    const items = rawItems
      .map(_m6ParseItemToCodeLabel)
      .filter(x => x.code !== '' || x.label !== '');

    sel.innerHTML =
      `<option value=""></option>` +
      items.map(x => {
        const safeCode = String(x.code).replaceAll('"', '&quot;');
        const safeLabel = String(x.label).replaceAll('<', '&lt;').replaceAll('>', '&gt;');
        return `<option value="${safeCode}">${safeLabel}</option>`;
      }).join('');

  } catch (e) {
    console.warn('Modulo6: errore caricamento tipi', e);
    sel.innerHTML = `<option value=""></option>`;
  }

  // 2) Load valore corrente
  try {
    if (plateId) {
      const r = await fetch(`${API_BASE}/modulo6_autor_get.php?plate_id=${encodeURIComponent(plateId)}&t=${Date.now()}`, { cache: 'no-store' });
      const j = await r.json();
      if (j.success && j.data) {
        sel.value = (j.data.autor != null) ? String(j.data.autor) : '';
      }
    }
  } catch (e) {
    console.warn('Modulo6: errore caricamento autor', e);
  }
};