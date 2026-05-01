async function loadPresenzeOptions() {
  const sel = document.getElementById('presentiDays');
  if (!sel) return;

  const r = await fetch(`/anpr/api/get_presenze_options.php?t=${Date.now()}`, { cache: 'no-store' });
  const j = await r.json();
  if (!j.success) return;

  sel.innerHTML = '';
  for (const opt of (j.data.options || [])) {
    const o = document.createElement('option');
    o.value = String(opt.value);
    o.textContent = opt.label;
    sel.appendChild(o);
  }

  // ✅ default = 0 (Totale)
  sel.value = '0';
}

function getSelectedDays(sel) {
  // ✅ supporta 0 (Totale)
  let days = parseInt((sel && sel.value != null ? sel.value : '0'), 10);
  if (Number.isNaN(days)) days = 0;
  if (days < 0) days = 0;
  return days;
}

async function refreshPresenti() {
  const sel = document.getElementById('presentiDays');
  const days = getSelectedDays(sel);

  const r = await fetch(`/anpr/api/get_presenti.php?days=${days}&t=${Date.now()}`, { cache: 'no-store' });
  const j = await r.json();
  if (!j.success) return;

  const el = document.getElementById('presentiCounter');
  if (el) el.textContent = (j.data && j.data.presenti != null) ? j.data.presenti : '-';
}

async function initPresenzeTopBar() {
  // ✅ guard: evita doppia init (se chiamata due volte da più script)
  if (window.__presenzeTopbarInited) return;
  window.__presenzeTopbarInited = true;

  const sel = document.getElementById('presentiDays');
  const counter = document.getElementById('presentiCounter');
  if (!sel || !counter) return;

  await loadPresenzeOptions();

  sel.addEventListener('change', refreshPresenti);
  await refreshPresenti();

  // refresh ogni 10 secondi
  setInterval(refreshPresenti, 10000);
}

// Inizializza
document.addEventListener('DOMContentLoaded', initPresenzeTopBar);
