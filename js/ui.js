console.log('🎛 ui.js caricato');

// ===== ACCORDION =====
function toggleAccordion(header) {
    const section = header.parentElement;
    const content = header.nextElementSibling;
    const icon = header.querySelector('.accordion-icon');

    // Chiudi tutte le altre sezioni
    document.querySelectorAll('.accordion-section').forEach(sec => {
        const cont = sec.querySelector('.accordion-content');
        const icn  = sec.querySelector('.accordion-icon');
        if (sec !== section) {
            cont.style.display = 'none';
            sec.classList.remove('open');
            if (icn) icn.style.transform = 'rotate(0deg)';
        }
    });

    // Toggle sezione corrente
    if (content.style.display === 'none' || content.style.display === '') {
        content.style.display = 'block';
        section.classList.add('open');
        if (icon) icon.style.transform = 'rotate(90deg)';
    } else {
        content.style.display = 'none';
        section.classList.remove('open');
        if (icon) icon.style.transform = 'rotate(0deg)';
    }
}

// ===== MODAL IMMAGINE =====
function openImageModal(img, plate) {
    const modal = document.getElementById('imageModal');
    const modalImage = document.getElementById('modalImage');
    if (!modal || !modalImage) return;

    modalImage.src = img.src;
    modal.classList.add('show');
}

function closeImageModal() {
    const modal = document.getElementById('imageModal');
    if (modal) modal.classList.remove('show');
}

// chiusura con ESC
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeImageModal();
});

// Chiudi modale immagine con la X e clic fuori
document.addEventListener('DOMContentLoaded', () => {
    const imageModal = document.getElementById('imageModal');
    if (!imageModal) {
        console.warn('imageModal non trovato nel DOM');
        return;
    }

    const imageModalClose = imageModal.querySelector('.modal-close');
    console.log('ui.js: imageModalClose =', imageModalClose);

    if (imageModalClose) {
        imageModalClose.addEventListener('click', (e) => {
            e.stopPropagation();
            closeImageModal();
        });
    } else {
        console.warn('ui.js: .modal-close non trovato dentro #imageModal');
    }

    // click sullo sfondo scuro (fuori dal riquadro bianco)
    imageModal.addEventListener('click', (e) => {
        if (e.target === imageModal) {
            closeImageModal();
        }
    });
});

// ===== STATS =====
function updateStats(count) {
    const platesCount = document.getElementById('platesCount');
    if (platesCount) {
        platesCount.textContent = count + ' targhe';
    }
}

// ===== TOAST =====
function showToast(message, type = 'info', duration = 3000) {
    const container = document.getElementById('toastContainer');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `
        <span>${type === 'success' ? '✅' : type === 'error' ? '❌' : 'ℹ️'}</span>
        <span>${message}</span>
    `;
    container.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(20px)';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

// ✅ NOTA: updatePlatesList è SOLO in plate-management.js, NON qui!