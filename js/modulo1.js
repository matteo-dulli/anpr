// modulo1.js - 🧼 Lavaggi e Ricariche (Semplificato)
// Espone window.Modulo1.mount(container, ctx)
// - Pannello SINISTRA: Lavaggi + Accessori + Prodotti
// - Pannello DESTRA: Ricariche + Quantità/Ore
// - Solo quantità modificabili prima del salvataggio
// - Prezzi sempre readonly, calcolati da costanti.txt

// modulo1.js - 🧼 Lavaggi e Ricariche (Completo con caricamento DB)
// Espone window.Modulo1.mount(container, ctx)

window.Modulo1 = window.Modulo1 || {};

window.Modulo1.mount = async function mountModulo1(container, ctx) {
  if (!container) return;

  const primaryBarcode = ctx?.ticket_code || ctx?.secondary_barcode || '';
  const plateNumber = ctx?.plate_number || '';
  const plateId = ctx?.plate_id || 0;

  // HTML Pannello 2 colonne verticali
  container.innerHTML = `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;height:100%;">
      
      <!-- COLONNA SINISTRA: LAVAGGIO -->
      <div id="m1_wash_panel" style="border:1px solid #ddd;padding:15px;border-radius:6px;display:flex;flex-direction:column;">
        <div style="flex:1;overflow-y:auto;">
          <h3 style="margin-top:0;color:#2563eb;">🧼 Lavaggio</h3>
          
          <div class="form-group">
            <label>Tipo Lavaggio:</label>
            <select id="m1_wash_type" style="width:100%;padding:8px;border-radius:4px;border:1px solid #ccc;">
              <option value="">-- Seleziona --</option>
            </select>
          </div>

          <div class="form-group">
            <label>Prezzo Lavaggio:</label>
            <input type="text" id="m1_wash_price" readonly style="width:100%;padding:8px;border-radius:4px;border:1px solid #ddd;background:#f5f5f5;">
          </div>

          <div class="form-group">
            <label><b>Accessori:</b></label>
            <div id="m1_accessories_list" style="margin:8px 0;"></div>
            <button id="m1_add_accessory_btn" style="margin-top:8px;padding:6px 12px;background:#28a745;color:white;border:none;border-radius:4px;cursor:pointer;">
              ➕ Aggiungi Accessorio
            </button>
          </div>

          <div class="form-group">
            <label><b>Prodotti:</b></label>
            <div id="m1_products_list" style="margin:8px 0;"></div>
            <button id="m1_add_product_btn" style="margin-top:8px;padding:6px 12px;background:#28a745;color:white;border:none;border-radius:4px;cursor:pointer;">
              ➕ Aggiungi Prodotto
            </button>
          </div>

          <div style="margin-top:15px;padding:10px;background:#e8f4f8;border-radius:4px;">
            <label style="font-weight:bold;">Totale Lavaggio:</label>
            <div style="font-size:18px;color:#2563eb;font-weight:bold;">€ <span id="m1_wash_total">0,00</span></div>
          </div>
        </div>

        <!-- PULSANTI LAVAGGIO (in fondo) -->
        <div style="border-top:1px solid #ddd;padding-top:12px;margin-top:12px;display:flex;flex-direction:column;gap:8px;">
          <button id="m1_wash_save_btn" style="width:100%;padding:10px;background:#28a745;color:white;border:none;border-radius:4px;cursor:pointer;font-weight:bold;">
            💾 Salva Lavaggio
          </button>
          <button id="m1_wash_cancel_btn" style="width:100%;padding:10px;background:#dc3545;color:white;border:none;border-radius:4px;cursor:pointer;font-weight:bold;">
            🗑️ Cancella Lavaggio
          </button>
        </div>
      </div>

      <!-- COLONNA DESTRA: RICARICA -->
      <div id="m1_recharge_panel" style="border:1px solid #ddd;padding:15px;border-radius:6px;display:flex;flex-direction:column;">
        <div style="flex:1;overflow-y:auto;">
          <h3 style="margin-top:0;color:#dc3545;">🔋 Ricarica Elettrica</h3>

          <div class="form-group">
            <label>Tipo Ricarica:</label>
            <select id="m1_recharge_type" style="width:100%;padding:8px;border-radius:4px;border:1px solid #ccc;">
              <option value="">-- Seleziona --</option>
            </select>
          </div>

          <div class="form-group">
            <label>Prezzo:</label>
            <input type="text" id="m1_recharge_price" readonly style="width:100%;padding:8px;border-radius:4px;border:1px solid #ddd;background:#f5f5f5;">
          </div>

          <div class="form-group">
            <label>Quantità / Ore:</label>
            <div style="display:flex;align-items:center;gap:10px;">
              <button id="m1_decrease_qty_btn" style="padding:6px 12px;background:#dc3545;color:white;border:none;border-radius:4px;cursor:pointer;font-weight:bold;">−</button>
              <input type="number" id="m1_recharge_qty" min="0" step="0.1" value="0" readonly style="width:60px;padding:8px;text-align:center;border-radius:4px;border:1px solid #ccc;background:#f5f5f5;">
              <button id="m1_increase_qty_btn" style="padding:6px 12px;background:#28a745;color:white;border:none;border-radius:4px;cursor:pointer;font-weight:bold;">+</button>
            </div>
          </div>

          <div style="margin-top:15px;padding:10px;background:#fff3cd;border-radius:4px;">
            <label style="font-weight:bold;">Totale Ricarica:</label>
            <div style="font-size:18px;color:#dc3545;font-weight:bold;">€ <span id="m1_recharge_total">0,00</span></div>
          </div>
        </div>

        <!-- PULSANTI RICARICA (in fondo) -->
        <div style="border-top:1px solid #ddd;padding-top:12px;margin-top:12px;display:flex;flex-direction:column;gap:8px;">
          <button id="m1_recharge_save_btn" style="width:100%;padding:10px;background:#28a745;color:white;border:none;border-radius:4px;cursor:pointer;font-weight:bold;">
            💾 Salva Ricarica
          </button>
          <button id="m1_recharge_cancel_btn" style="width:100%;padding:10px;background:#dc3545;color:white;border:none;border-radius:4px;cursor:pointer;font-weight:bold;">
            🗑️ Cancella Ricarica
          </button>
        </div>
      </div>

    </div>

    <!-- MODAL CONFERMA CANCELLAZIONE -->
    <div id="m1_modal_backdrop" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;"></div>
    <div id="m1_modal_confirm" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:white;padding:30px;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,0.3);z-index:10000;min-width:350px;text-align:center;">
      <h2 id="m1_modal_title" style="margin-top:0;color:#333;"></h2>
      <p id="m1_modal_message" style="color:#666;font-size:16px;margin:15px 0;"></p>
      <div style="display:flex;gap:10px;justify-content:center;margin-top:20px;">
        <button id="m1_modal_cancel" style="padding:10px 20px;background:#cccccc;color:#333;border:none;border-radius:4px;cursor:pointer;font-weight:bold;">
          ❌ Annulla
        </button>
        <button id="m1_modal_confirm_btn" style="padding:10px 20px;background:#dc3545;color:white;border:none;border-radius:4px;cursor:pointer;font-weight:bold;">
          🗑️ Conferma Cancellazione
        </button>
      </div>
    </div>
  `;

  // Salva stato globale modulo1
  window.Modulo1.__state = {
    primaryBarcode,
    plateNumber,
    plateId,
    wash: {
      type: '',
      price: 0,
      accessories: [],
      products: [],
      total: 0,
      id_lavaggio: null,
      saved: false,
      stop: 0
    },
    recharge: {
      type: '',
      price: 0,
      qty: 0,
      total: 0,
      id_ricarica: null,
      saved: false,
      stop: 0
    }
  };

  // Carica costanti e popola dropdown
  await window.Modulo1.__loadCostanti();

  // Event listeners Dropdown
  document.getElementById('m1_wash_type').addEventListener('change', async (e) => {
    window.Modulo1.__state.wash.type = e.target.value;
    await window.Modulo1.__updateWashPrice();
  });

  document.getElementById('m1_recharge_type').addEventListener('change', async (e) => {
    window.Modulo1.__state.recharge.type = e.target.value;
    await window.Modulo1.__updateRechargePrice();
  });

  // Event Listeners PULSANTI (CAPTURE PHASE)
  const washSaveBtn = document.getElementById('m1_wash_save_btn');
  if (washSaveBtn) {
    washSaveBtn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      window.Modulo1.__saveWash();
    }, true);
  }

  const washCancelBtn = document.getElementById('m1_wash_cancel_btn');
  if (washCancelBtn) {
    washCancelBtn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      console.log('🗑️ CLICK RILEVATO - CANCELLA LAVAGGIO');
      window.Modulo1.__cancelWash();
    }, true);
  }

  const rechargeSaveBtn = document.getElementById('m1_recharge_save_btn');
  if (rechargeSaveBtn) {
    rechargeSaveBtn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      window.Modulo1.__saveRecharge();
    }, true);
  }

  const rechargeCancelBtn = document.getElementById('m1_recharge_cancel_btn');
  if (rechargeCancelBtn) {
    rechargeCancelBtn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      console.log('🗑️ CLICK RILEVATO - CANCELLA RICARICA');
      window.Modulo1.__cancelRecharge();
    }, true);
  }

  // Event Listeners QUANTITÀ
  const decreaseBtn = document.getElementById('m1_decrease_qty_btn');
  if (decreaseBtn) {
    decreaseBtn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      window.Modulo1.__decreaseQty();
    }, true);
  }

  const increaseBtn = document.getElementById('m1_increase_qty_btn');
  if (increaseBtn) {
    increaseBtn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      window.Modulo1.__increaseQty();
    }, true);
  }

  // Event Listeners AGGIUNGI ACCESSORIO/PRODOTTO
  const addAccessoryBtn = document.getElementById('m1_add_accessory_btn');
  if (addAccessoryBtn) {
    addAccessoryBtn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      window.Modulo1.__addAccessory();
    }, true);
  }

  const addProductBtn = document.getElementById('m1_add_product_btn');
  if (addProductBtn) {
    addProductBtn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      window.Modulo1.__addProduct();
    }, true);
  }

  // Popola dropdown
  window.Modulo1.__populateWashTypes();
  window.Modulo1.__populateRechargeTypes();

  // ✅ Carica i dati salvati dal DB
  await window.Modulo1.__loadExistingData();
};

// ===== HELPER: Carica costanti da config =====
window.Modulo1.__loadCostanti = async function() {
  try {
    const resp = await fetch(`${API_BASE}/get_costanti.php?t=${Date.now()}`, { cache: 'no-store' });
    const json = await resp.json();
    if (json.success) {
      window.Modulo1.__costanti = json.data || {};
    }
  } catch (e) {
    console.warn('Errore caricamento costanti:', e);
    window.Modulo1.__costanti = {};
  }
};

// ===== HELPER: Carica dati esistenti dal DB =====
window.Modulo1.__loadExistingData = async function() {
  try {
    const plateId = window.Modulo1.__state.plateId;
    console.log('📥 Caricamento dati da DB per plate_id:', plateId);

    const resp = await fetch(`${API_BASE}/modulo1_ticket_get.php?plate_id=${plateId}`);
    const json = await resp.json();

    if (!json.success) {
      console.warn('⚠️ Nessun dato salvato:', json.message);
      return;
    }

    const data = json.data;

    // ✅ Carica LAVAGGIO
    if (data.wash) {
      const wash = data.wash;
      console.log('Lavaggio trovato:', wash);

      window.Modulo1.__state.wash.id_lavaggio = wash.id;
      window.Modulo1.__state.wash.type = wash.tipo_lavaggio;
      window.Modulo1.__state.wash.price = parseFloat(wash.prezzo_lavaggio) || 0;
      window.Modulo1.__state.wash.stop = wash.stop;

      // ✅ Carica accessories
      const accessories = wash.accessori_json || [];
      window.Modulo1.__state.wash.accessories = accessories.map(a => ({
        desc: a.desc || '',
        qty: parseInt(a.qty) || 1,
        price: parseFloat(a.price) || 0
      }));

      // ✅ Carica prodotti
      const products = wash.prodotti_json || [];
      window.Modulo1.__state.wash.products = products.map(p => ({
        desc: p.desc || '',
        qty: parseInt(p.qty) || 1,
        price: parseFloat(p.price) || 0
      }));

      window.Modulo1.__state.wash.total = parseFloat(wash.totale_lavaggio) || 0;
      window.Modulo1.__state.wash.saved = true;

      // ✅ Popola UI LAVAGGIO
      document.getElementById('m1_wash_type').value = wash.tipo_lavaggio || '';
      document.getElementById('m1_wash_price').value = '€ ' + window.Modulo1.__state.wash.price.toFixed(2);
      document.getElementById('m1_wash_total').textContent = window.Modulo1.__state.wash.total.toFixed(2).replace('.', ',');

      // ✅ Renderizza accessories
      const accList = document.getElementById('m1_accessories_list');
      accList.innerHTML = '';
      accessories.forEach((acc, idx) => {
        window.Modulo1.__renderAccessory(acc, idx);
      });

      // ✅ Renderizza prodotti
      const prodList = document.getElementById('m1_products_list');
      prodList.innerHTML = '';
      products.forEach((prod, idx) => {
        window.Modulo1.__renderProduct(prod, idx);
      });

      // ✅ Se stop=1, disabilita tutto
      if (wash.stop === 1 || wash.stop === '1') {
        window.Modulo1.__disableWashPanel(true);
      }
    }

    // ✅ Carica RICARICA
    if (data.recharge) {
      const recharge = data.recharge;
      console.log('Ricarica trovata:', recharge);

      window.Modulo1.__state.recharge.id_ricarica = recharge.id;
      window.Modulo1.__state.recharge.type = recharge.tipo_ricarica;
      window.Modulo1.__state.recharge.price = parseFloat(recharge.prezzo_ricarica) || 0;
      window.Modulo1.__state.recharge.qty = parseFloat(recharge.quantita_ore) || 0;
      window.Modulo1.__state.recharge.total = parseFloat(recharge.totale_ricarica) || 0;
      window.Modulo1.__state.recharge.stop = recharge.stop;
      window.Modulo1.__state.recharge.saved = true;

      // ✅ Popola UI RICARICA
      document.getElementById('m1_recharge_type').value = recharge.tipo_ricarica || '';
      document.getElementById('m1_recharge_price').value = '€ ' + window.Modulo1.__state.recharge.price.toFixed(2);
      document.getElementById('m1_recharge_qty').value = window.Modulo1.__state.recharge.qty;
      document.getElementById('m1_recharge_total').textContent = window.Modulo1.__state.recharge.total.toFixed(2).replace('.', ',');

      // ✅ Se stop=1, disabilita tutto
      if (recharge.stop === 1 || recharge.stop === '1') {
        window.Modulo1.__disableRechargePanel(true);
      }
    }

  } catch (e) {
    console.error('⚠️ Errore caricamento dati:', e);
  }
};

// ===== HELPER: Renderizza Accessorio dal DB =====
window.Modulo1.__renderAccessory = function(acc, idx) {
  const accessories = window.Modulo1.__getAccessoriesList();
  const list = document.getElementById('m1_accessories_list');

  const row = document.createElement('div');
  row.id = `m1_acc_${idx}`;
  row.style.cssText = 'margin:8px 0;padding:8px;background:#f9f9f9;border-radius:4px;border-left:3px solid #28a745;';

  let html = `
    <div style="display:grid;grid-template-columns:1fr 80px 40px;gap:8px;align-items:center;">
      <select id="m1_acc_name_${idx}" style="padding:6px;border-radius:4px;border:1px solid #ccc;">
        <option value="">-- Seleziona --</option>
  `;

  accessories.forEach(ac => {
    const selected = ac.name === acc.desc ? 'selected' : '';
    html += `<option value="${ac.name}" data-price="${ac.price}" ${selected}>${ac.name}</option>`;
  });

  html += `
      </select>
      <input type="number" id="m1_acc_qty_${idx}" min="1" value="${acc.qty || 1}" style="padding:6px;border-radius:4px;border:1px solid #ccc;">
      <button class="m1_remove_acc_btn" data-idx="${idx}" style="padding:6px 8px;background:#dc3545;color:white;border:none;border-radius:4px;cursor:pointer;">❌</button>
    </div>
  `;

  row.innerHTML = html;
  list.appendChild(row);

  // Event listeners
  document.getElementById(`m1_acc_name_${idx}`).addEventListener('change', (e) => {
    const price = parseFloat(e.target.selectedOptions[0].dataset.price || 0);
    window.Modulo1.__state.wash.accessories[idx].desc = e.target.value;
    window.Modulo1.__state.wash.accessories[idx].price = price;
    window.Modulo1.__calculateWashTotal();
  });

  document.getElementById(`m1_acc_qty_${idx}`).addEventListener('change', (e) => {
    window.Modulo1.__state.wash.accessories[idx].qty = parseInt(e.target.value || 1);
    window.Modulo1.__calculateWashTotal();
  });

  document.querySelector(`[data-idx="${idx}"].m1_remove_acc_btn`).addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    window.Modulo1.__removeAccessory(idx);
  }, true);
};

// ===== HELPER: Renderizza Prodotto dal DB =====
window.Modulo1.__renderProduct = function(prod, idx) {
  const products = window.Modulo1.__getProductsList();
  const list = document.getElementById('m1_products_list');

  const row = document.createElement('div');
  row.id = `m1_prod_${idx}`;
  row.style.cssText = 'margin:8px 0;padding:8px;background:#f9f9f9;border-radius:4px;border-left:3px solid #ffc107;';

  let html = `
    <div style="display:grid;grid-template-columns:1fr 80px 40px;gap:8px;align-items:center;">
      <select id="m1_prod_name_${idx}" style="padding:6px;border-radius:4px;border:1px solid #ccc;">
        <option value="">-- Seleziona --</option>
  `;

  products.forEach(pr => {
    const selected = pr.name === prod.desc ? 'selected' : '';
    html += `<option value="${pr.name}" data-price="${pr.price}" ${selected}>${pr.name}</option>`;
  });

  html += `
      </select>
      <input type="number" id="m1_prod_qty_${idx}" min="1" value="${prod.qty || 1}" style="padding:6px;border-radius:4px;border:1px solid #ccc;">
      <button class="m1_remove_prod_btn" data-idx="${idx}" style="padding:6px 8px;background:#dc3545;color:white;border:none;border-radius:4px;cursor:pointer;">❌</button>
    </div>
  `;

  row.innerHTML = html;
  list.appendChild(row);

  // Event listeners
  document.getElementById(`m1_prod_name_${idx}`).addEventListener('change', (e) => {
    const price = parseFloat(e.target.selectedOptions[0].dataset.price || 0);
    window.Modulo1.__state.wash.products[idx].desc = e.target.value;
    window.Modulo1.__state.wash.products[idx].price = price;
    window.Modulo1.__calculateWashTotal();
  });

  document.getElementById(`m1_prod_qty_${idx}`).addEventListener('change', (e) => {
    window.Modulo1.__state.wash.products[idx].qty = parseInt(e.target.value || 1);
    window.Modulo1.__calculateWashTotal();
  });

  document.querySelector(`[data-idx="${idx}"].m1_remove_prod_btn`).addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    window.Modulo1.__removeProduct(idx);
  }, true);
};

// ===== HELPER: Disabilita panel LAVAGGIO =====
window.Modulo1.__disableWashPanel = function(disable) {
  const panel = document.getElementById('m1_wash_panel');
  const selects = panel.querySelectorAll('select');
  const inputs = panel.querySelectorAll('input[type="number"]');
  const buttons = panel.querySelectorAll('button');

  if (disable) {
    selects.forEach(s => s.disabled = true);
    inputs.forEach(i => i.disabled = true);
    buttons.forEach(b => {
      b.disabled = true;
      b.style.background = '#cccccc';
      b.style.cursor = 'not-allowed';
    });
    panel.style.background = '#f0f0f0';
  } else {
    selects.forEach(s => s.disabled = false);
    inputs.forEach(i => i.disabled = false);
    buttons.forEach(b => {
      b.disabled = false;
      b.style.background = b.id.includes('save') ? '#28a745' : '#dc3545';
      b.style.cursor = 'pointer';
    });
    panel.style.background = 'white';
  }
};

// ===== HELPER: Disabilita panel RICARICA =====
window.Modulo1.__disableRechargePanel = function(disable) {
  const panel = document.getElementById('m1_recharge_panel');
  const selects = panel.querySelectorAll('select');
  const inputs = panel.querySelectorAll('input[type="number"]');
  const buttons = panel.querySelectorAll('button');

  if (disable) {
    selects.forEach(s => s.disabled = true);
    inputs.forEach(i => i.disabled = true);
    buttons.forEach(b => {
      b.disabled = true;
      b.style.background = '#cccccc';
      b.style.cursor = 'not-allowed';
    });
    panel.style.background = '#f0f0f0';
  } else {
    selects.forEach(s => s.disabled = false);
    inputs.forEach(i => i.disabled = false);
    buttons.forEach(b => {
      b.disabled = false;
      b.style.background = b.id.includes('save') ? '#28a745' : '#dc3545';
      b.style.cursor = 'pointer';
    });
    panel.style.background = 'white';
  }
};

// ===== HELPER: Popola dropdown Lavaggio =====
window.Modulo1.__populateWashTypes = function() {
  const select = document.getElementById('m1_wash_type');
  const costanti = window.Modulo1.__costanti || {};
  
  select.innerHTML = '<option value="">-- Seleziona --</option>';
  for (let i = 1; i <= 5; i++) {
    const key = `TestoL${i}`;
    if (costanti[key]) {
      const opt = document.createElement('option');
      opt.value = costanti[key];
      opt.textContent = costanti[key];
      select.appendChild(opt);
    }
  }
};

// ===== HELPER: Popola dropdown Ricarica =====
window.Modulo1.__populateRechargeTypes = function() {
  const select = document.getElementById('m1_recharge_type');
  const costanti = window.Modulo1.__costanti || {};
  
  select.innerHTML = '<option value="">-- Seleziona --</option>';
  for (let i = 1; i <= 5; i++) {
    const key = `TestoE${i}`;
    if (costanti[key]) {
      const opt = document.createElement('option');
      opt.value = costanti[key];
      opt.textContent = costanti[key];
      select.appendChild(opt);
    }
  }
};

// ===== HELPER: Popola dropdown Accessori =====
window.Modulo1.__getAccessoriesList = function() {
  const costanti = window.Modulo1.__costanti || {};
  const accessories = [];
  
  const accessoryKeys = ['Tergicristalli', 'Tappetini anteriori', 'Tappetini Posteriori'];
  accessoryKeys.forEach(key => {
    if (costanti[key]) {
      accessories.push({ name: key, price: costanti[key] });
    }
  });
  
  return accessories;
};

// ===== HELPER: Popola dropdown Prodotti =====
window.Modulo1.__getProductsList = function() {
  const costanti = window.Modulo1.__costanti || {};
  const products = [];
  
  const productKeys = ['Shampoo', 'Spazzola', 'Telo copertura', 'Copri cerchi'];
  productKeys.forEach(key => {
    if (costanti[key]) {
      products.push({ name: key, price: costanti[key] });
    }
  });
  
  return products;
};

// ===== HELPER: Aggiungi riga Accessorio =====
window.Modulo1.__addAccessory = function() {
  const accessories = window.Modulo1.__getAccessoriesList();
  const list = document.getElementById('m1_accessories_list');
  
  const idx = window.Modulo1.__state.wash.accessories.length;
  const rowId = `m1_acc_${idx}`;
  
  const row = document.createElement('div');
  row.id = rowId;
  row.style.cssText = 'margin:8px 0;padding:8px;background:#f9f9f9;border-radius:4px;border-left:3px solid #28a745;';
  
  let html = `
    <div style="display:grid;grid-template-columns:1fr 80px 40px;gap:8px;align-items:center;">
      <select id="m1_acc_name_${idx}" style="padding:6px;border-radius:4px;border:1px solid #ccc;">
        <option value="">-- Seleziona --</option>
  `;
  
  accessories.forEach(acc => {
    html += `<option value="${acc.name}" data-price="${acc.price}">${acc.name}</option>`;
  });
  
  html += `
      </select>
      <input type="number" id="m1_acc_qty_${idx}" min="1" value="1" style="padding:6px;border-radius:4px;border:1px solid #ccc;">
      <button class="m1_remove_acc_btn" data-idx="${idx}" style="padding:6px 8px;background:#dc3545;color:white;border:none;border-radius:4px;cursor:pointer;">❌</button>
    </div>
  `;
  
  row.innerHTML = html;
  list.appendChild(row);
  
  window.Modulo1.__state.wash.accessories.push({
    desc: '',
    qty: 1,
    price: 0
  });
  
  // Event listeners
  document.getElementById(`m1_acc_name_${idx}`).addEventListener('change', (e) => {
    const price = parseFloat(e.target.selectedOptions[0].dataset.price || 0);
    window.Modulo1.__state.wash.accessories[idx].desc = e.target.value;
    window.Modulo1.__state.wash.accessories[idx].price = price;
    window.Modulo1.__calculateWashTotal();
  });
  
  document.getElementById(`m1_acc_qty_${idx}`).addEventListener('change', (e) => {
    window.Modulo1.__state.wash.accessories[idx].qty = parseInt(e.target.value || 1);
    window.Modulo1.__calculateWashTotal();
  });

  document.querySelector(`[data-idx="${idx}"].m1_remove_acc_btn`).addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    window.Modulo1.__removeAccessory(idx);
  }, true);
};

// ===== HELPER: Aggiungi riga Prodotto =====
window.Modulo1.__addProduct = function() {
  const products = window.Modulo1.__getProductsList();
  const list = document.getElementById('m1_products_list');
  
  const idx = window.Modulo1.__state.wash.products.length;
  const rowId = `m1_prod_${idx}`;
  
  const row = document.createElement('div');
  row.id = rowId;
  row.style.cssText = 'margin:8px 0;padding:8px;background:#f9f9f9;border-radius:4px;border-left:3px solid #ffc107;';
  
  let html = `
    <div style="display:grid;grid-template-columns:1fr 80px 40px;gap:8px;align-items:center;">
      <select id="m1_prod_name_${idx}" style="padding:6px;border-radius:4px;border:1px solid #ccc;">
        <option value="">-- Seleziona --</option>
  `;
  
  products.forEach(prod => {
    html += `<option value="${prod.name}" data-price="${prod.price}">${prod.name}</option>`;
  });
  
  html += `
      </select>
      <input type="number" id="m1_prod_qty_${idx}" min="1" value="1" style="padding:6px;border-radius:4px;border:1px solid #ccc;">
      <button class="m1_remove_prod_btn" data-idx="${idx}" style="padding:6px 8px;background:#dc3545;color:white;border:none;border-radius:4px;cursor:pointer;">❌</button>
    </div>
  `;
  
  row.innerHTML = html;
  list.appendChild(row);
  
  window.Modulo1.__state.wash.products.push({
    desc: '',
    qty: 1,
    price: 0
  });
  
  // Event listeners
  document.getElementById(`m1_prod_name_${idx}`).addEventListener('change', (e) => {
    const price = parseFloat(e.target.selectedOptions[0].dataset.price || 0);
    window.Modulo1.__state.wash.products[idx].desc = e.target.value;
    window.Modulo1.__state.wash.products[idx].price = price;
    window.Modulo1.__calculateWashTotal();
  });
  
  document.getElementById(`m1_prod_qty_${idx}`).addEventListener('change', (e) => {
    window.Modulo1.__state.wash.products[idx].qty = parseInt(e.target.value || 1);
    window.Modulo1.__calculateWashTotal();
  });

  document.querySelector(`[data-idx="${idx}"].m1_remove_prod_btn`).addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    window.Modulo1.__removeProduct(idx);
  }, true);
};

// ===== HELPER: Rimuovi Accessorio =====
window.Modulo1.__removeAccessory = function(idx) {
  const row = document.getElementById(`m1_acc_${idx}`);
  if (row) row.remove();
  window.Modulo1.__state.wash.accessories.splice(idx, 1);
  window.Modulo1.__calculateWashTotal();
};

// ===== HELPER: Rimuovi Prodotto =====
window.Modulo1.__removeProduct = function(idx) {
  const row = document.getElementById(`m1_prod_${idx}`);
  if (row) row.remove();
  window.Modulo1.__state.wash.products.splice(idx, 1);
  window.Modulo1.__calculateWashTotal();
};

// ===== HELPER: Aggiorna prezzo lavaggio =====
window.Modulo1.__updateWashPrice = async function() {
  const costanti = window.Modulo1.__costanti || {};
  const type = window.Modulo1.__state.wash.type;
  
  let price = 0;
  for (let i = 1; i <= 5; i++) {
    if (costanti[`TestoL${i}`] === type) {
      price = costanti[`PrezzoL${i}`] || 0;
      break;
    }
  }
  
  window.Modulo1.__state.wash.price = price;
  document.getElementById('m1_wash_price').value = '€ ' + price.toFixed(2);
  window.Modulo1.__calculateWashTotal();
};

// ===== HELPER: Calcola totale lavaggio =====
window.Modulo1.__calculateWashTotal = function() {
  const wash = window.Modulo1.__state.wash;
  let total = wash.price;
  
  wash.accessories.forEach(acc => {
    if (acc.desc && acc.qty > 0) {
      total += acc.qty * acc.price;
    }
  });
  
  wash.products.forEach(prod => {
    if (prod.desc && prod.qty > 0) {
      total += prod.qty * prod.price;
    }
  });
  
  wash.total = total;
  document.getElementById('m1_wash_total').textContent = total.toFixed(2).replace('.', ',');
};

// ===== HELPER: Aggiorna prezzo ricarica =====
window.Modulo1.__updateRechargePrice = function() {
  const costanti = window.Modulo1.__costanti || {};
  const type = window.Modulo1.__state.recharge.type;
  
  let price = 0;
  for (let i = 1; i <= 5; i++) {
    if (costanti[`TestoE${i}`] === type) {
      price = costanti[`PrezzoE${i}`] || 0;
      break;
    }
  }
  
  window.Modulo1.__state.recharge.price = price;
  document.getElementById('m1_recharge_price').value = '€ ' + price.toFixed(2);
  window.Modulo1.__calculateRechargeTotal();
};

// ===== HELPER: Calcola totale ricarica =====
window.Modulo1.__calculateRechargeTotal = function() {
  const recharge = window.Modulo1.__state.recharge;
  recharge.total = recharge.price * recharge.qty;
  document.getElementById('m1_recharge_total').textContent = recharge.total.toFixed(2).replace('.', ',');
};

// ===== HELPER: Aumenta quantità ricarica =====
window.Modulo1.__increaseQty = function() {
  const input = document.getElementById('m1_recharge_qty');
  window.Modulo1.__state.recharge.qty = parseFloat(input.value || 0) + 1;
  input.value = window.Modulo1.__state.recharge.qty;
  window.Modulo1.__calculateRechargeTotal();
};

// ===== HELPER: Diminuisci quantità ricarica =====
window.Modulo1.__decreaseQty = function() {
  const input = document.getElementById('m1_recharge_qty');
  const qty = parseFloat(input.value || 0) - 1;
  window.Modulo1.__state.recharge.qty = Math.max(0, qty);
  input.value = window.Modulo1.__state.recharge.qty;
  window.Modulo1.__calculateRechargeTotal();
};

// ===== HELPER: MOSTRA MODAL CONFERMA =====
window.Modulo1.__showConfirmModal = function(title, message, onConfirm) {
  const backdrop = document.getElementById('m1_modal_backdrop');
  const modal = document.getElementById('m1_modal_confirm');
  
  document.getElementById('m1_modal_title').textContent = title;
  document.getElementById('m1_modal_message').textContent = message;
  
  backdrop.style.display = 'block';
  modal.style.display = 'block';
  
  // Pulsante Conferma
  const confirmBtn = document.getElementById('m1_modal_confirm_btn');
  confirmBtn.onclick = () => {
    backdrop.style.display = 'none';
    modal.style.display = 'none';
    confirmBtn.onclick = null;
    document.getElementById('m1_modal_cancel').onclick = null;
    onConfirm();
  };
  
  // Pulsante Annulla
  const cancelBtn = document.getElementById('m1_modal_cancel');
  cancelBtn.onclick = () => {
    backdrop.style.display = 'none';
    modal.style.display = 'none';
    confirmBtn.onclick = null;
    cancelBtn.onclick = null;
  };
};

// ===== HELPER: SALVA LAVAGGIO =====
window.Modulo1.__saveWash = async function() {
  const primaryBarcode = window.Modulo1.__state.primaryBarcode;
  const plateNumber = window.Modulo1.__state.plateNumber;
  
  // ✅ VALIDAZIONE TICKET
  if (!primaryBarcode || primaryBarcode.trim() === '') {
    alert('⚠️ Nessun ticket associato!');
    return;
  }
  
  if (!plateNumber) {
    alert('⚠️ Targa mancante');
    return;
  }

  try {
    const washData = window.Modulo1.__state.wash;
    
    if (!washData.type) {
      alert('⚠️ Seleziona un tipo di lavaggio');
      return;
    }

    let totale_accessori = 0;
    washData.accessories.forEach(acc => {
      if (acc.desc && acc.qty > 0) {
        totale_accessori += acc.qty * acc.price;
      }
    });

    let totale_prodotti = 0;
    washData.products.forEach(prod => {
      if (prod.desc && prod.qty > 0) {
        totale_prodotti += prod.qty * prod.price;
      }
    });

    const payload = {
      id_lavaggio: washData.id_lavaggio || null,
      plate_id: window.Modulo1.__state.plateId,
      plate_number: plateNumber,
      primary_barcode: primaryBarcode,
      tipo_lavaggio: washData.type,
      prezzo_lavaggio: washData.price,
      totale_accessori: totale_accessori,
      totale_prodotti: totale_prodotti,
      totale_lavaggio: washData.total,
      accessori: washData.accessories.filter(a => a.desc),
      prodotti: washData.products.filter(p => p.desc),
      id_turno: window.CURRENT_TURNO_ID || null
    };

    const res = await fetch(`${API_BASE}/modulo1_wash_save.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    const json = await res.json();

    if (!json.success) {
      alert('❌ Errore: ' + json.message);
      return;
    }

    window.Modulo1.__state.wash.id_lavaggio = json.data.id_lavaggio;
    window.Modulo1.__state.wash.saved = true;

    const saveBtn = document.getElementById('m1_wash_save_btn');
    if (saveBtn) {
      saveBtn.disabled = true;
      saveBtn.style.background = '#cccccc';
      saveBtn.style.cursor = 'not-allowed';
    }
    
    alert('✅ Lavaggio salvato');

  } catch (e) {
    alert('❌ Errore: ' + e.message);
    console.error('Errore salvataggio lavaggio:', e);
  }
};

// ===== HELPER: CANCELLA LAVAGGIO =====
window.Modulo1.__cancelWash = async function() {
  console.log('🗑️ Cancella lavaggio premuto');
  
  window.Modulo1.__showConfirmModal(
    '🗑️ Cancella Lavaggio',
    'Sei sicuro di voler cancellare?',
    async () => {
      try {
        const id_lavaggio = window.Modulo1.__state.wash.id_lavaggio;

        if (id_lavaggio) {
          const res = await fetch(`${API_BASE}/modulo1_wash_cancel.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_lavaggio: id_lavaggio })
          });

          const json = await res.json();
          if (!json.success) {
            console.warn('Avviso:', json.message);
          }
        }

        window.Modulo1.__state.wash = {
          type: '',
          price: 0,
          accessories: [],
          products: [],
          total: 0,
          id_lavaggio: null,
          saved: false,
          stop: 0
        };

        document.getElementById('m1_wash_type').value = '';
        document.getElementById('m1_wash_price').value = '';
        document.getElementById('m1_accessories_list').innerHTML = '';
        document.getElementById('m1_products_list').innerHTML = '';
        document.getElementById('m1_wash_total').textContent = '0,00';

        const saveBtn = document.getElementById('m1_wash_save_btn');
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.style.background = '#28a745';
          saveBtn.style.cursor = 'pointer';
        }

        alert('✅ Lavaggio cancellato');

      } catch (e) {
        alert('❌ Errore: ' + e.message);
      }
    }
  );
};

// ===== HELPER: SALVA RICARICA =====
window.Modulo1.__saveRecharge = async function() {
  const primaryBarcode = window.Modulo1.__state.primaryBarcode;
  const plateNumber = window.Modulo1.__state.plateNumber;
  
  if (!primaryBarcode || primaryBarcode.trim() === '') {
    alert('⚠️ Nessun ticket associato!');
    return;
  }
  
  if (!plateNumber) {
    alert('⚠️ Targa mancante');
    return;
  }

  try {
    const rechargeData = window.Modulo1.__state.recharge;
    
    if (!rechargeData.type) {
      alert('⚠️ Seleziona un tipo di ricarica');
      return;
    }

    const payload = {
      id_ricarica: rechargeData.id_ricarica || null,
      plate_id: window.Modulo1.__state.plateId,
      plate_number: plateNumber,
      primary_barcode: primaryBarcode,
      tipo_ricarica: rechargeData.type,
      prezzo_ricarica: rechargeData.price,
      quantita_ore: rechargeData.qty,
      totale_ricarica: rechargeData.total,
      id_turno: window.CURRENT_TURNO_ID || null
    };

    const res = await fetch(`${API_BASE}/modulo1_recharge_save.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    const json = await res.json();

    if (!json.success) {
      alert('❌ Errore: ' + json.message);
      return;
    }

    window.Modulo1.__state.recharge.id_ricarica = json.data.id_ricarica;
    window.Modulo1.__state.recharge.saved = true;

    const saveBtn = document.getElementById('m1_recharge_save_btn');
    if (saveBtn) {
      saveBtn.disabled = true;
      saveBtn.style.background = '#cccccc';
      saveBtn.style.cursor = 'not-allowed';
    }
    
    alert('✅ Ricarica salvata');

  } catch (e) {
    alert('❌ Errore: ' + e.message);
    console.error('Errore salvataggio ricarica:', e);
  }
};

// ===== HELPER: CANCELLA RICARICA =====
window.Modulo1.__cancelRecharge = async function() {
  console.log('🗑️ Cancella ricarica premuto');
  
  window.Modulo1.__showConfirmModal(
    '🗑️ Cancella Ricarica',
    'Sei sicuro di voler cancellare?',
    async () => {
      try {
        const id_ricarica = window.Modulo1.__state.recharge.id_ricarica;

        if (id_ricarica) {
          const res = await fetch(`${API_BASE}/modulo1_recharge_cancel.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_ricarica: id_ricarica })
          });

          const json = await res.json();
          if (!json.success) {
            console.warn('Avviso:', json.message);
          }
        }

        window.Modulo1.__state.recharge = {
          type: '',
          price: 0,
          qty: 0,
          total: 0,
          id_ricarica: null,
          saved: false,
          stop: 0
        };

        document.getElementById('m1_recharge_type').value = '';
        document.getElementById('m1_recharge_price').value = '';
        document.getElementById('m1_recharge_qty').value = '0';
        document.getElementById('m1_recharge_total').textContent = '0,00';

        const saveBtn = document.getElementById('m1_recharge_save_btn');
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.style.background = '#28a745';
          saveBtn.style.cursor = 'pointer';
        }

        alert('✅ Ricarica cancellata');

      } catch (e) {
        alert('❌ Errore: ' + e.message);
      }
    }
  );
};

// ===== EXPORT: Recupera dati =====
window.Modulo1.getData = function() {
  return window.Modulo1.__state || {
    wash: { type: '', accessories: [], products: [], total: 0 },
    recharge: { type: '', qty: 0, total: 0 }
  };
};


// ===== HELPER: Disabilita panel LAVAGGIO =====
window.Modulo1.__disableWashPanel = function() {
  document.getElementById('m1_wash_type').disabled = true;
  document.getElementById('m1_wash_type').style.background = '#cccccc';
  document.getElementById('m1_wash_type').style.cursor = 'not-allowed';

  document.getElementById('m1_add_accessory_btn').disabled = true;
  document.getElementById('m1_add_accessory_btn').style.background = '#cccccc';
  document.getElementById('m1_add_accessory_btn').style.cursor = 'not-allowed';

  document.getElementById('m1_add_product_btn').disabled = true;
  document.getElementById('m1_add_product_btn').style.background = '#cccccc';
  document.getElementById('m1_add_product_btn').style.cursor = 'not-allowed';

  const saveBtn = document.getElementById('m1_wash_save_btn');
  saveBtn.disabled = true;
  saveBtn.style.background = '#cccccc';
  saveBtn.style.cursor = 'not-allowed';

  const cancelBtn = document.getElementById('m1_wash_cancel_btn');
  cancelBtn.disabled = true;
  cancelBtn.style.background = '#cccccc';
  cancelBtn.style.cursor = 'not-allowed';

  // Disabilita tutti i select di accessori/prodotti
  document.querySelectorAll('#m1_accessories_list select, #m1_products_list select').forEach(sel => {
    sel.disabled = true;
    sel.style.background = '#cccccc';
  });

  document.querySelectorAll('#m1_accessories_list input, #m1_products_list input').forEach(inp => {
    inp.disabled = true;
    inp.style.background = '#cccccc';
  });

  document.querySelectorAll('#m1_accessories_list button, #m1_products_list button').forEach(btn => {
    btn.disabled = true;
    btn.style.background = '#cccccc';
    btn.style.cursor = 'not-allowed';
  });
};

// ===== HELPER: Disabilita panel RICARICA =====
window.Modulo1.__disableRechargePanel = function() {
  document.getElementById('m1_recharge_type').disabled = true;
  document.getElementById('m1_recharge_type').style.background = '#cccccc';
  document.getElementById('m1_recharge_type').style.cursor = 'not-allowed';

  document.getElementById('m1_decrease_qty_btn').disabled = true;
  document.getElementById('m1_decrease_qty_btn').style.background = '#cccccc';
  document.getElementById('m1_decrease_qty_btn').style.cursor = 'not-allowed';

  document.getElementById('m1_increase_qty_btn').disabled = true;
  document.getElementById('m1_increase_qty_btn').style.background = '#cccccc';
  document.getElementById('m1_increase_qty_btn').style.cursor = 'not-allowed';

  const saveBtn = document.getElementById('m1_recharge_save_btn');
  saveBtn.disabled = true;
  saveBtn.style.background = '#cccccc';
  saveBtn.style.cursor = 'not-allowed';

  const cancelBtn = document.getElementById('m1_recharge_cancel_btn');
  cancelBtn.disabled = true;
  cancelBtn.style.background = '#cccccc';
  cancelBtn.style.cursor = 'not-allowed';
};

// ===== HELPER: Renderizza Accessorio dal DB =====
window.Modulo1.__renderAccessory = function(acc, idx) {
  const accessories = window.Modulo1.__getAccessoriesList();
  const list = document.getElementById('m1_accessories_list');
  
  const rowId = `m1_acc_${idx}`;
  
  const row = document.createElement('div');
  row.id = rowId;
  row.style.cssText = 'margin:8px 0;padding:8px;background:#f9f9f9;border-radius:4px;border-left:3px solid #28a745;';
  
  let html = `
    <div style="display:grid;grid-template-columns:1fr 80px 40px;gap:8px;align-items:center;">
      <select id="m1_acc_name_${idx}" style="padding:6px;border-radius:4px;border:1px solid #ccc;">
        <option value="">-- Seleziona --</option>
  `;
  
  accessories.forEach(ac => {
    const selected = ac.name === acc.desc ? 'selected' : '';
    html += `<option value="${ac.name}" data-price="${ac.price}" ${selected}>${ac.name}</option>`;
  });
  
  html += `
      </select>
      <input type="number" id="m1_acc_qty_${idx}" min="1" value="${acc.qty}" style="padding:6px;border-radius:4px;border:1px solid #ccc;">
      <button class="m1_remove_acc_btn" data-idx="${idx}" style="padding:6px 8px;background:#dc3545;color:white;border:none;border-radius:4px;cursor:pointer;">❌</button>
    </div>
  `;
  
  row.innerHTML = html;
  list.appendChild(row);
  
  // Event listeners
  document.getElementById(`m1_acc_name_${idx}`).addEventListener('change', (e) => {
    const price = parseFloat(e.target.selectedOptions[0].dataset.price || 0);
    window.Modulo1.__state.wash.accessories[idx].desc = e.target.value;
    window.Modulo1.__state.wash.accessories[idx].price = price;
    window.Modulo1.__calculateWashTotal();
  });
  
  document.getElementById(`m1_acc_qty_${idx}`).addEventListener('change', (e) => {
    window.Modulo1.__state.wash.accessories[idx].qty = parseInt(e.target.value || 1);
    window.Modulo1.__calculateWashTotal();
  });

  document.querySelector(`[data-idx="${idx}"].m1_remove_acc_btn`).addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    window.Modulo1.__removeAccessory(idx);
  }, true);
};

// ===== HELPER: Renderizza Prodotto dal DB =====
window.Modulo1.__renderProduct = function(prod, idx) {
  const products = window.Modulo1.__getProductsList();
  const list = document.getElementById('m1_products_list');
  
  const rowId = `m1_prod_${idx}`;
  
  const row = document.createElement('div');
  row.id = rowId;
  row.style.cssText = 'margin:8px 0;padding:8px;background:#f9f9f9;border-radius:4px;border-left:3px solid #ffc107;';
  
  let html = `
    <div style="display:grid;grid-template-columns:1fr 80px 40px;gap:8px;align-items:center;">
      <select id="m1_prod_name_${idx}" style="padding:6px;border-radius:4px;border:1px solid #ccc;">
        <option value="">-- Seleziona --</option>
  `;
  
  products.forEach(pr => {
    const selected = pr.name === prod.desc ? 'selected' : '';
    html += `<option value="${pr.name}" data-price="${pr.price}" ${selected}>${pr.name}</option>`;
  });
  
  html += `
      </select>
      <input type="number" id="m1_prod_qty_${idx}" min="1" value="${prod.qty}" style="padding:6px;border-radius:4px;border:1px solid #ccc;">
      <button class="m1_remove_prod_btn" data-idx="${idx}" style="padding:6px 8px;background:#dc3545;color:white;border:none;border-radius:4px;cursor:pointer;">❌</button>
    </div>
  `;
  
  row.innerHTML = html;
  list.appendChild(row);
  
  // Event listeners
  document.getElementById(`m1_prod_name_${idx}`).addEventListener('change', (e) => {
    const price = parseFloat(e.target.selectedOptions[0].dataset.price || 0);
    window.Modulo1.__state.wash.products[idx].desc = e.target.value;
    window.Modulo1.__state.wash.products[idx].price = price;
    window.Modulo1.__calculateWashTotal();
  });
  
  document.getElementById(`m1_prod_qty_${idx}`).addEventListener('change', (e) => {
    window.Modulo1.__state.wash.products[idx].qty = parseInt(e.target.value || 1);
    window.Modulo1.__calculateWashTotal();
  });

  document.querySelector(`[data-idx="${idx}"].m1_remove_prod_btn`).addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    window.Modulo1.__removeProduct(idx);
  }, true);
};

// ===== HELPER: Carica costanti da config =====
window.Modulo1.__loadCostanti = async function() {
  try {
    const resp = await fetch(`${API_BASE}/get_costanti.php?t=${Date.now()}`, { cache: 'no-store' });
    const json = await resp.json();
    if (json.success) {
      window.Modulo1.__costanti = json.data || {};
    }
  } catch (e) {
    console.warn('Errore caricamento costanti:', e);
    window.Modulo1.__costanti = {};
  }
};

// ===== HELPER: Popola dropdown Lavaggio =====
window.Modulo1.__populateWashTypes = function() {
  const select = document.getElementById('m1_wash_type');
  const costanti = window.Modulo1.__costanti || {};
  
  select.innerHTML = '<option value="">-- Seleziona --</option>';
  for (let i = 1; i <= 5; i++) {
    const key = `TestoL${i}`;
    if (costanti[key]) {
      const opt = document.createElement('option');
      opt.value = costanti[key];
      opt.textContent = costanti[key];
      select.appendChild(opt);
    }
  }
};

// ===== HELPER: Popola dropdown Ricarica =====
window.Modulo1.__populateRechargeTypes = function() {
  const select = document.getElementById('m1_recharge_type');
  const costanti = window.Modulo1.__costanti || {};
  
  select.innerHTML = '<option value="">-- Seleziona --</option>';
  for (let i = 1; i <= 5; i++) {
    const key = `TestoE${i}`;
    if (costanti[key]) {
      const opt = document.createElement('option');
      opt.value = costanti[key];
      opt.textContent = costanti[key];
      select.appendChild(opt);
    }
  }
};

// ===== HELPER: Popola dropdown Accessori =====
window.Modulo1.__getAccessoriesList = function() {
  const costanti = window.Modulo1.__costanti || {};
  const accessories = [];
  
  const accessoryKeys = ['Tergicristalli', 'Tappetini anteriori', 'Tappetini Posteriori'];
  accessoryKeys.forEach(key => {
    if (costanti[key]) {
      accessories.push({ name: key, price: costanti[key] });
    }
  });
  
  return accessories;
};

// ===== HELPER: Popola dropdown Prodotti =====
window.Modulo1.__getProductsList = function() {
  const costanti = window.Modulo1.__costanti || {};
  const products = [];
  
  const productKeys = ['Shampoo', 'Spazzola', 'Telo copertura', 'Copri cerchi'];
  productKeys.forEach(key => {
    if (costanti[key]) {
      products.push({ name: key, price: costanti[key] });
    }
  });
  
  return products;
};

// ===== HELPER: Aggiungi riga Accessorio =====
window.Modulo1.__addAccessory = function() {
  const accessories = window.Modulo1.__getAccessoriesList();
  const list = document.getElementById('m1_accessories_list');
  
  const idx = window.Modulo1.__state.wash.accessories.length;
  const rowId = `m1_acc_${idx}`;
  
  const row = document.createElement('div');
  row.id = rowId;
  row.style.cssText = 'margin:8px 0;padding:8px;background:#f9f9f9;border-radius:4px;border-left:3px solid #28a745;';
  
  let html = `
    <div style="display:grid;grid-template-columns:1fr 80px 40px;gap:8px;align-items:center;">
      <select id="m1_acc_name_${idx}" style="padding:6px;border-radius:4px;border:1px solid #ccc;">
        <option value="">-- Seleziona --</option>
  `;
  
  accessories.forEach(acc => {
    html += `<option value="${acc.name}" data-price="${acc.price}">${acc.name}</option>`;
  });
  
  html += `
      </select>
      <input type="number" id="m1_acc_qty_${idx}" min="1" value="1" style="padding:6px;border-radius:4px;border:1px solid #ccc;">
      <button class="m1_remove_acc_btn" data-idx="${idx}" style="padding:6px 8px;background:#dc3545;color:white;border:none;border-radius:4px;cursor:pointer;">❌</button>
    </div>
  `;
  
  row.innerHTML = html;
  list.appendChild(row);
  
  window.Modulo1.__state.wash.accessories.push({
    desc: '',
    qty: 1,
    price: 0
  });
  
  // Event listeners
  document.getElementById(`m1_acc_name_${idx}`).addEventListener('change', (e) => {
    const price = parseFloat(e.target.selectedOptions[0].dataset.price || 0);
    window.Modulo1.__state.wash.accessories[idx].desc = e.target.value;
    window.Modulo1.__state.wash.accessories[idx].price = price;
    window.Modulo1.__calculateWashTotal();
  });
  
  document.getElementById(`m1_acc_qty_${idx}`).addEventListener('change', (e) => {
    window.Modulo1.__state.wash.accessories[idx].qty = parseInt(e.target.value || 1);
    window.Modulo1.__calculateWashTotal();
  });

  document.querySelector(`[data-idx="${idx}"].m1_remove_acc_btn`).addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    window.Modulo1.__removeAccessory(idx);
  }, true);
};

// ===== HELPER: Aggiungi riga Prodotto =====
window.Modulo1.__addProduct = function() {
  const products = window.Modulo1.__getProductsList();
  const list = document.getElementById('m1_products_list');
  
  const idx = window.Modulo1.__state.wash.products.length;
  const rowId = `m1_prod_${idx}`;
  
  const row = document.createElement('div');
  row.id = rowId;
  row.style.cssText = 'margin:8px 0;padding:8px;background:#f9f9f9;border-radius:4px;border-left:3px solid #ffc107;';
  
  let html = `
    <div style="display:grid;grid-template-columns:1fr 80px 40px;gap:8px;align-items:center;">
      <select id="m1_prod_name_${idx}" style="padding:6px;border-radius:4px;border:1px solid #ccc;">
        <option value="">-- Seleziona --</option>
  `;
  
  products.forEach(prod => {
    html += `<option value="${prod.name}" data-price="${prod.price}">${prod.name}</option>`;
  });
  
  html += `
      </select>
      <input type="number" id="m1_prod_qty_${idx}" min="1" value="1" style="padding:6px;border-radius:4px;border:1px solid #ccc;">
      <button class="m1_remove_prod_btn" data-idx="${idx}" style="padding:6px 8px;background:#dc3545;color:white;border:none;border-radius:4px;cursor:pointer;">❌</button>
    </div>
  `;
  
  row.innerHTML = html;
  list.appendChild(row);
  
  window.Modulo1.__state.wash.products.push({
    desc: '',
    qty: 1,
    price: 0
  });
  
  // Event listeners
  document.getElementById(`m1_prod_name_${idx}`).addEventListener('change', (e) => {
    const price = parseFloat(e.target.selectedOptions[0].dataset.price || 0);
    window.Modulo1.__state.wash.products[idx].desc = e.target.value;
    window.Modulo1.__state.wash.products[idx].price = price;
    window.Modulo1.__calculateWashTotal();
  });
  
  document.getElementById(`m1_prod_qty_${idx}`).addEventListener('change', (e) => {
    window.Modulo1.__state.wash.products[idx].qty = parseInt(e.target.value || 1);
    window.Modulo1.__calculateWashTotal();
  });

  document.querySelector(`[data-idx="${idx}"].m1_remove_prod_btn`).addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    window.Modulo1.__removeProduct(idx);
  }, true);
};

// ===== HELPER: Rimuovi Accessorio =====
window.Modulo1.__removeAccessory = function(idx) {
  const row = document.getElementById(`m1_acc_${idx}`);
  if (row) row.remove();
  window.Modulo1.__state.wash.accessories.splice(idx, 1);
  window.Modulo1.__calculateWashTotal();
};

// ===== HELPER: Rimuovi Prodotto =====
window.Modulo1.__removeProduct = function(idx) {
  const row = document.getElementById(`m1_prod_${idx}`);
  if (row) row.remove();
  window.Modulo1.__state.wash.products.splice(idx, 1);
  window.Modulo1.__calculateWashTotal();
};

// ===== HELPER: Aggiorna prezzo lavaggio =====
window.Modulo1.__updateWashPrice = async function() {
  const costanti = window.Modulo1.__costanti || {};
  const type = window.Modulo1.__state.wash.type;
  
  let price = 0;
  for (let i = 1; i <= 5; i++) {
    if (costanti[`TestoL${i}`] === type) {
      price = costanti[`PrezzoL${i}`] || 0;
      break;
    }
  }
  
  window.Modulo1.__state.wash.price = price;
  document.getElementById('m1_wash_price').value = '€ ' + price.toFixed(2);
  window.Modulo1.__calculateWashTotal();
};

// ===== HELPER: Calcola totale lavaggio =====
window.Modulo1.__calculateWashTotal = function() {
  const wash = window.Modulo1.__state.wash;
  let total = wash.price;
  
  wash.accessories.forEach(acc => {
    if (acc.desc && acc.qty > 0) {
      total += acc.qty * acc.price;
    }
  });
  
  wash.products.forEach(prod => {
    if (prod.desc && prod.qty > 0) {
      total += prod.qty * prod.price;
    }
  });
  
  wash.total = total;
  document.getElementById('m1_wash_total').textContent = total.toFixed(2).replace('.', ',');
};

// ===== HELPER: Aggiorna prezzo ricarica =====
window.Modulo1.__updateRechargePrice = function() {
  const costanti = window.Modulo1.__costanti || {};
  const type = window.Modulo1.__state.recharge.type;
  
  let price = 0;
  for (let i = 1; i <= 5; i++) {
    if (costanti[`TestoE${i}`] === type) {
      price = costanti[`PrezzoE${i}`] || 0;
      break;
    }
  }
  
  window.Modulo1.__state.recharge.price = price;
  document.getElementById('m1_recharge_price').value = '€ ' + price.toFixed(2);
  window.Modulo1.__calculateRechargeTotal();
};

// ===== HELPER: Calcola totale ricarica =====
window.Modulo1.__calculateRechargeTotal = function() {
  const recharge = window.Modulo1.__state.recharge;
  recharge.total = recharge.price * recharge.qty;
  document.getElementById('m1_recharge_total').textContent = recharge.total.toFixed(2).replace('.', ',');
};

// ===== HELPER: Aumenta quantità ricarica =====
window.Modulo1.__increaseQty = function() {
  const input = document.getElementById('m1_recharge_qty');
  window.Modulo1.__state.recharge.qty = parseFloat(input.value || 0) + 1;
  input.value = window.Modulo1.__state.recharge.qty;
  window.Modulo1.__calculateRechargeTotal();
};

// ===== HELPER: Diminuisci quantità ricarica =====
window.Modulo1.__decreaseQty = function() {
  const input = document.getElementById('m1_recharge_qty');
  const qty = parseFloat(input.value || 0) - 1;
  window.Modulo1.__state.recharge.qty = Math.max(0, qty);
  input.value = window.Modulo1.__state.recharge.qty;
  window.Modulo1.__calculateRechargeTotal();
};

// ===== HELPER: MOSTRA MODAL CONFERMA =====
window.Modulo1.__showConfirmModal = function(title, message, onConfirm) {
  const backdrop = document.getElementById('m1_modal_backdrop');
  const modal = document.getElementById('m1_modal_confirm');
  
  document.getElementById('m1_modal_title').textContent = title;
  document.getElementById('m1_modal_message').textContent = message;
  
  backdrop.style.display = 'block';
  modal.style.display = 'block';
  
  // Pulsante Conferma
  const confirmBtn = document.getElementById('m1_modal_confirm_btn');
  confirmBtn.onclick = () => {
    backdrop.style.display = 'none';
    modal.style.display = 'none';
    confirmBtn.onclick = null;
    document.getElementById('m1_modal_cancel').onclick = null;
    onConfirm();
  };
  
  // Pulsante Annulla
  const cancelBtn = document.getElementById('m1_modal_cancel');
  cancelBtn.onclick = () => {
    backdrop.style.display = 'none';
    modal.style.display = 'none';
    confirmBtn.onclick = null;
    cancelBtn.onclick = null;
  };
};

// ===== HELPER: SALVA LAVAGGIO =====
window.Modulo1.__saveWash = async function() {
  const primaryBarcode = window.Modulo1.__state.primaryBarcode;
  const plateNumber = window.Modulo1.__state.plateNumber;
  
  // ✅ VALIDAZIONE TICKET
  if (!primaryBarcode || primaryBarcode.trim() === '') {
    alert('⚠️ Nessun ticket associato! Inserisci un ticket prima di salvare.');
    return;
  }
  
  if (!plateNumber) {
    alert('⚠️ Targa mancante');
    return;
  }

  try {
    const washData = window.Modulo1.__state.wash;
    
    if (!washData.type) {
      alert('⚠️ Seleziona un tipo di lavaggio prima di salvare');
      return;
    }

    // Calcola totali
    let totale_accessori = 0;
    washData.accessories.forEach(acc => {
      if (acc.desc && acc.qty > 0) {
        totale_accessori += acc.qty * acc.price;
      }
    });

    let totale_prodotti = 0;
    washData.products.forEach(prod => {
      if (prod.desc && prod.qty > 0) {
        totale_prodotti += prod.qty * prod.price;
      }
    });

    const payload = {
      plate_id: window.Modulo1.__state.plateId,
      plate_number: plateNumber,
      primary_barcode: primaryBarcode,
      tipo_lavaggio: washData.type,
      prezzo_lavaggio: washData.price,
      totale_accessori: totale_accessori,
      totale_prodotti: totale_prodotti,
      totale_lavaggio: washData.total,
      accessori: washData.accessories.filter(a => a.desc),
      prodotti: washData.products.filter(p => p.desc),
      id_turno: window.CURRENT_TURNO_ID || null,
      id_lavaggio: washData.id_lavaggio  // ✅ Per UPDATE
    };

    const res = await fetch(`${API_BASE}/modulo1_wash_save.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    const json = await res.json();

    if (!json.success) {
      alert('❌ Errore: ' + json.message);
      return;
    }

    window.Modulo1.__state.wash.id_lavaggio = json.data.id_lavaggio;
    window.Modulo1.__state.wash.saved = true;

    // ✅ Disabilita pulsante Salva
    const saveBtn = document.getElementById('m1_wash_save_btn');
    if (saveBtn) {
      saveBtn.disabled = true;
      saveBtn.style.background = '#cccccc';
      saveBtn.style.cursor = 'not-allowed';
    }
    
    alert('✅ Lavaggio salvato');

  } catch (e) {
    alert('❌ Errore: ' + e.message);
    console.error('Errore salvataggio lavaggio:', e);
  }
};

// ===== HELPER: CANCELLA LAVAGGIO =====
window.Modulo1.__cancelWash = async function() {
  console.log('🗑️ Cancella lavaggio premuto');
  
  // ✅ Mostra modal al posto di confirm()
  window.Modulo1.__showConfirmModal(
    '🗑️ Cancella Lavaggio',
    'Sei sicuro di voler cancellare il lavaggio?',
    async () => {
      console.log('✅ Utente ha confermato cancellazione lavaggio');
      
      try {
        const id_lavaggio = window.Modulo1.__state.wash.id_lavaggio;
        console.log('ID Lavaggio da cancellare:', id_lavaggio);

        if (id_lavaggio) {
          console.log('📤 Invio richiesta cancellazione al server...');
          
          const res = await fetch(`${API_BASE}/modulo1_wash_cancel.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_lavaggio: id_lavaggio })
          });

          console.log('📥 Risposta dal server:', res.status);
          const json = await res.json();
          console.log('JSON risposta:', json);
          
          if (!json.success) {
            console.warn('⚠️ Avviso cancellazione:', json.message);
            alert('⚠️ ' + json.message);
            return;
          } else {
            console.log('✅ Cancellazione DB completata');
          }
        }

        // ✅ Resetta lo stato
        window.Modulo1.__state.wash = {
          type: '',
          price: 0,
          accessories: [],
          products: [],
          total: 0,
          id_lavaggio: null,
          saved: false,
          stop: 0
        };

        // ✅ Pulisci UI
        document.getElementById('m1_wash_type').value = '';
        document.getElementById('m1_wash_price').value = '';
        document.getElementById('m1_accessories_list').innerHTML = '';
        document.getElementById('m1_products_list').innerHTML = '';
        document.getElementById('m1_wash_total').textContent = '0,00';

        // ✅ Riabilita pulsante Salva
        const saveBtn = document.getElementById('m1_wash_save_btn');
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.style.background = '#28a745';
          saveBtn.style.cursor = 'pointer';
        }

        console.log('✅ Cancellazione lavaggio completata');
        alert('✅ Lavaggio cancellato');

      } catch (e) {
        console.error('❌ ERRORE CANCELLAZIONE LAVAGGIO:', e);
        alert('❌ Errore: ' + e.message);
      }
    }
  );
};

// ===== HELPER: SALVA RICARICA =====
window.Modulo1.__saveRecharge = async function() {
  const primaryBarcode = window.Modulo1.__state.primaryBarcode;
  const plateNumber = window.Modulo1.__state.plateNumber;
  
  // ✅ VALIDAZIONE TICKET
  if (!primaryBarcode || primaryBarcode.trim() === '') {
    alert('⚠️ Nessun ticket associato! Inserisci un ticket prima di salvare.');
    return;
  }
  
  if (!plateNumber) {
    alert('⚠️ Targa mancante');
    return;
  }

  try {
    const rechargeData = window.Modulo1.__state.recharge;
    
    if (!rechargeData.type) {
      alert('⚠️ Seleziona un tipo di ricarica prima di salvare');
      return;
    }

    const payload = {
      plate_id: window.Modulo1.__state.plateId,
      plate_number: plateNumber,
      primary_barcode: primaryBarcode,
      tipo_ricarica: rechargeData.type,
      prezzo_ricarica: rechargeData.price,
      quantita_ore: rechargeData.qty,
      totale_ricarica: rechargeData.total,
      id_turno: window.CURRENT_TURNO_ID || null,
      id_ricarica: rechargeData.id_ricarica  // ✅ Per UPDATE
    };

    const res = await fetch(`${API_BASE}/modulo1_recharge_save.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    const json = await res.json();

    if (!json.success) {
      alert('❌ Errore: ' + json.message);
      return;
    }

    window.Modulo1.__state.recharge.id_ricarica = json.data.id_ricarica;
    window.Modulo1.__state.recharge.saved = true;

    // ✅ Disabilita pulsante Salva
    const saveBtn = document.getElementById('m1_recharge_save_btn');
    if (saveBtn) {
      saveBtn.disabled = true;
      saveBtn.style.background = '#cccccc';
      saveBtn.style.cursor = 'not-allowed';
    }
    
    alert('✅ Ricarica salvata');

  } catch (e) {
    alert('❌ Errore: ' + e.message);
    console.error('Errore salvataggio ricarica:', e);
  }
};

// ===== HELPER: CANCELLA RICARICA =====
window.Modulo1.__cancelRecharge = async function() {
  console.log('🗑️ Cancella ricarica premuto');
  
  // ✅ Mostra modal al posto di confirm()
  window.Modulo1.__showConfirmModal(
    '🗑️ Cancella Ricarica',
    'Sei sicuro di voler cancellare la ricarica?',
    async () => {
      console.log('✅ Utente ha confermato cancellazione ricarica');
      
      try {
        const id_ricarica = window.Modulo1.__state.recharge.id_ricarica;
        console.log('ID Ricarica da cancellare:', id_ricarica);

        if (id_ricarica) {
          console.log('📤 Invio richiesta cancellazione al server...');
          
          const res = await fetch(`${API_BASE}/modulo1_recharge_cancel.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_ricarica: id_ricarica })
          });

          console.log('📥 Risposta dal server:', res.status);
          const json = await res.json();
          console.log('JSON risposta:', json);
          
          if (!json.success) {
            console.warn('⚠️ Avviso cancellazione:', json.message);
            alert('⚠️ ' + json.message);
            return;
          } else {
            console.log('✅ Cancellazione DB completata');
          }
        }

        // ✅ Resetta lo stato
        window.Modulo1.__state.recharge = {
          type: '',
          price: 0,
          qty: 0,
          total: 0,
          id_ricarica: null,
          saved: false,
          stop: 0
        };

        // ✅ Pulisci UI
        document.getElementById('m1_recharge_type').value = '';
        document.getElementById('m1_recharge_price').value = '';
        document.getElementById('m1_recharge_qty').value = '0';
        document.getElementById('m1_recharge_total').textContent = '0,00';

        // ✅ Riabilita pulsante Salva
        const saveBtn = document.getElementById('m1_recharge_save_btn');
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.style.background = '#28a745';
          saveBtn.style.cursor = 'pointer';
        }

        console.log('✅ Cancellazione ricarica completata');
        alert('✅ Ricarica cancellata');

      } catch (e) {
        console.error('❌ ERRORE CANCELLAZIONE RICARICA:', e);
        alert('❌ Errore: ' + e.message);
      }
    }
  );
};

// ===== EXPORT: Recupera dati =====
window.Modulo1.getData = function() {
  return window.Modulo1.__state || {
    wash: { type: '', accessories: [], products: [], total: 0 },
    recharge: { type: '', qty: 0, total: 0 }
  };
};
