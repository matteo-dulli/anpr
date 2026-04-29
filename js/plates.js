console.log('📋 plates.js caricato');

// ✅ RIMOSSO: let allPlates, selectedPlateId, selectPlateLock, justCreatedManualPlateId
// Sono già dichiarati in app.js

// ✅ RIMOSSO: const API_BASE (è già in app.js)

// ✅ RIMOSSO: function loadPlates() - È già in app.js versione moderna e async!

// ===== RICERCA TICKET CODE =====
async function searchTicketCode(query) {
    try {
        showToast('⏳ Ricerca in corso...', 'info', 1000);

        const response = await fetch(`${API_BASE}/search_plate.php?q=${encodeURIComponent(query)}&t=${Date.now()}`);
        
        if (!response.ok) {
            showToast(`❌ Errore HTTP ${response.status}`, 'error');
            return;
        }

        const text = await response.text();
        if (!text || text.trim() === '') {
            showToast('❌ Risposta vuota dal server', 'error');
            return;
        }

        const data = JSON.parse(text);
        console.log('🔍 searchTicketCode response:', data);

        if (!data.success || !data.data || data.data.length === 0) {
            showToast('⚠️ Nessun risultato trovato', 'error', 2000);
            return;
        }

        const firstResult = data.data[0];
        console.log('🎯 Risultato trovato:', firstResult);

        // ===== APRI DIRETTAMENTE SENZA CERCARE NELLA LISTA =====
        if (firstResult.is_passage === 1) {
            // È un passaggio o ticket standalone
            const passageId = firstResult.passage_id || -firstResult.id;
            console.log('🚶 Passaggio trovato, ID:', passageId);
            selectPassage(passageId);
            showToast(`✅ ${firstResult.plate_number}`, 'success', 2000);
        } else {
            // È una targa - AGGIUNGI A allPlates SE NON ESISTE
            console.log('📸 Targa trovata, ID:', firstResult.id);
            
            if (!allPlates.find(p => p.id === firstResult.id)) {
                allPlates.unshift(firstResult);
                console.log('✅ Targa aggiunta a allPlates');
            }
            
            selectPlate(firstResult.id);
            showToast(`✅ ${firstResult.plate_number}`, 'success', 2000);
        }

    } catch (err) {
        console.error('❌ Errore:', err);
        showToast('❌ Errore: ' + err.message, 'error');
    }
}

// ===== FILTRO GIORNI =====
function filterByDays(days) {
    document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
    loadPlates(days);
}