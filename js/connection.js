console.log('🔌 connection.js caricato');

let isConnected = false;

// ===== STATUS BAR (orologio centro) =====
function updateStatusBar() {
    const now = new Date();
    const headerDateTimeEl = document.getElementById('headerDateTime');
    if (!headerDateTimeEl) return;

    const giorno   = String(now.getDate()).padStart(2, '0');
    const mese     = String(now.getMonth() + 1).padStart(2, '0');
    const anno     = now.getFullYear();
    const ore      = String(now.getHours()).padStart(2, '0');
    const minuti   = String(now.getMinutes()).padStart(2, '0');
    const secondi  = String(now.getSeconds()).padStart(2, '0');

    headerDateTimeEl.textContent = `${giorno}/${mese}/${anno} ${ore}:${minuti}:${secondi}`;
}

// ===== TEMPO DI CONNESSIONE (lato destro) =====
function updateConnectionTime() {
    const connectionTimeEl = document.getElementById('connectionTime');
    if (!connectionTimeEl) return;

    if (!connectionStartTime) {
        connectionTimeEl.textContent = '';
        return;
    }

    const now = new Date();
    const diff = now - connectionStartTime;

    const days    = Math.floor(diff / 86400000);
    const hours   = Math.floor((diff % 86400000) / 3600000);
    const minutes = Math.floor((diff % 3600000) / 60000);
    const seconds = Math.floor((diff % 60000) / 1000);

    let timeString = '';
    if (days > 0) {
        timeString = `${days}g ${hours}h ${minutes}m`;
    } else if (hours > 0) {
        timeString = `${hours}h ${minutes}m ${seconds}s`;
    } else {
        timeString = `${minutes}m ${seconds}s`;
    }

    const dateStr = connectionStartTime.toLocaleDateString('it-IT');
    const timeStr = connectionStartTime.toLocaleTimeString('it-IT');

    connectionTimeEl.textContent = `${dateStr} ${timeStr} (+${timeString})`;
}

// ===== CONNESSIONE DB =====
async function checkConnection() {
    try {
        const response = await fetch(API_BASE + '/check_db.php?t=' + Date.now());
        if (!response.ok) throw new Error('HTTP ' + response.status);

        const data = await response.json();
        const dbConnected = data.success === true || data.db_connected === true;

        if (dbConnected) {
            if (!isConnected) {
                isConnected = true;
                connectionStartTime = new Date();
                updateConnectionStatus(true);
                showToast('✅ Connessione al DB ripristinata', 'success', 2000);
                updateConnectionTime();
            }
        } else {
            throw new Error('DB non connesso');
        }
    } catch (error) {
        if (isConnected) {
            isConnected = false;
            connectionStartTime = null;
            updateConnectionStatus(false);
            showToast('⚠️ Connessione al DB persa', 'error', 2000);
        }
    }
}

function updateConnectionStatus(connected) {
    const dot = document.getElementById('statusDot');
    const statusSpan = document.getElementById('connectionStatus');
    if (!dot || !statusSpan) return;

    if (connected) {
        dot.classList.remove('disconnected');
        dot.classList.add('connected');
        statusSpan.textContent = 'Connesso';
    } else {
        dot.classList.remove('connected');
        dot.classList.add('disconnected');
        statusSpan.textContent = 'Offline';
    }
}

// ===== AVVIO PERIODICO ORA / CONNESSIONE =====
document.addEventListener('DOMContentLoaded', () => {
    // orologio centro header
    updateStatusBar();
    setInterval(updateStatusBar, 1000);

    // timer connessione
    connectionStartTime = new Date();
    updateConnectionTime();
    setInterval(updateConnectionTime, 1000);

    // prima verifica connessione e poi periodica (anche app.js richiama checkConnection, ma è idempotente)
    checkConnection();
    setInterval(checkConnection, 15000);
});