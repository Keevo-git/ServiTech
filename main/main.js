

/* ==============================
   LANDING PAGE – SERVICE SCROLL
   ============================== */

function scrollToSection(id) {
  const section = document.getElementById(id);
  if (section) {
    section.scrollIntoView({
      behavior: 'smooth'
    });
  }
}



/* LANDING PAGE – DOCUMENT PRINTING MODAL */

function openModal(id) {
  document.getElementById(id).style.display = 'flex';
}

function closeModal(id) {
  document.getElementById(id).style.display = 'none';
}

document.querySelectorAll('.modal-overlay').forEach(modal => {
  modal.addEventListener('click', e => {
    if (e.target === modal) {
      modal.style.display = 'none';
    }
  });
});



// CUSTOMER DASHBOARD DEMO ONLY - guarded so it won't throw when elements are absent
document.addEventListener("DOMContentLoaded", () => {
  const customerNameEl = document.getElementById("customerName");
  const queueNoEl = document.getElementById("queueNo");
  const queueStatusEl = document.getElementById("queueStatus");
  const queueServiceEl = document.getElementById("queueService");
  const queueDetailsEl = document.getElementById("queueDetails");
  const ongoingCountEl = document.getElementById("ongoingCount");

  if (customerNameEl) customerNameEl.textContent = "Trisha Mae";
  if (queueNoEl) queueNoEl.textContent = "#P-001";
  if (queueStatusEl) queueStatusEl.textContent = "PENDING";
  if (queueServiceEl) queueServiceEl.textContent = "Service: Document Printing";
  if (queueDetailsEl) queueDetailsEl.textContent = "Short Bond Paper - Black & White";
  if (ongoingCountEl) ongoingCountEl.textContent = "04";
});


const quickCard = document.querySelector(".quick-card");
if (quickCard) {
  quickCard.addEventListener("click", () => {
    window.location.href = "queue.html";
  });
}



/* custo2_docu_printing.html */ 

document.addEventListener("DOMContentLoaded", () => {
  // Check if we're on the document printing page
  const qtyInput = document.getElementById("qtyInput");
  if (!qtyInput) return;

  const paperSizeSelect = document.getElementById("paperSizeSelect");
  const colorRadios = document.querySelectorAll('input[name="color"]');
  
  const summaryPaperSize = document.getElementById("summaryPaperSize");
  const summaryQty = document.getElementById("summaryQty");
  const summaryTotal = document.getElementById("summaryTotal");

  // Default price per page (used when no special mapping applies)
  const defaultPrice = 5; // fallback
  // If this page is the Xerox service, use a per-paper-size price map
  const isXerox = document.body && document.body.dataset && document.body.dataset.service === 'xerox';
  const xeroxPriceMap = {
    'Long Bond (8.5 x 13)': 5,
    'Short Bond (8.5 x 11)': 3,
    'A4': 3,
    'A3': 5
  };

  function updateSummary() {
    // Update paper size
    const paperSize = paperSizeSelect.value;
    summaryPaperSize.textContent = paperSize && paperSize !== "Select paper size" ? paperSize : "Not Selected";

    // (color option intentionally not shown in summary)

    // Update quantity
    const qty = parseInt(qtyInput.value) || 1;
    summaryQty.textContent = qty;

    // Determine price per page (supports Xerox per-size pricing)
    let pricePerPage = defaultPrice;
    if (isXerox && paperSizeSelect) {
      const selectedSize = paperSizeSelect.value;
      if (selectedSize && xeroxPriceMap[selectedSize] !== undefined) {
        pricePerPage = xeroxPriceMap[selectedSize];
      }
    } else {
      // fallback: if body provides a data-price-per-page, use it
      const bodyPrice = document.body && document.body.dataset && document.body.dataset.pricePerPage;
      pricePerPage = bodyPrice ? parseFloat(bodyPrice) : defaultPrice;
    }

    // Update total with chosen price
    summaryTotal.textContent = `₱${(qty * pricePerPage).toFixed(2)}`;
  }

  // Add event listeners
  paperSizeSelect.addEventListener("change", updateSummary);
  qtyInput.addEventListener("input", updateSummary);
  colorRadios.forEach(radio => radio.addEventListener("change", updateSummary));

  // Initialize on load
  updateSummary();
});


// Join Queue modal handling (used on custo2_docu_printing.html)
document.addEventListener('DOMContentLoaded', () => {
  const joinBtn = document.getElementById('joinQueueBtn');
  const queueModal = document.getElementById('queueModal');
  const goHomeBtn = document.getElementById('goHomeBtn');
  const viewQueueBtn = document.getElementById('viewQueueBtn');
  const modalQueueNo = document.getElementById('modalQueueNo');

  if (!joinBtn || !queueModal) return;

  // Show modal when Join Queue is clicked
  joinBtn.addEventListener('click', (e) => {
    e.preventDefault();

    // Validate required fields before showing modal
    const paperSizeSelect = document.getElementById('paperSizeSelect');
    const qtyInput = document.getElementById('qtyInput');
    let valid = true;

    if (paperSizeSelect) {
      // if the select has a disabled placeholder as first option, ensure a real option is chosen
      const val = paperSizeSelect.value;
      if (!val || val === 'Select paper size') {
        paperSizeSelect.style.border = '2px solid #e74c3c';
        paperSizeSelect.focus();
        valid = false;
      } else {
        paperSizeSelect.style.border = '';
      }
    }

    if (qtyInput) {
      const qty = parseInt(qtyInput.value, 10) || 0;
      if (qty < 1) {
        qtyInput.style.border = '2px solid #e74c3c';
        qtyInput.focus();
        valid = false;
      } else {
        qtyInput.style.border = '';
      }
    }

    if (!valid) return;

    // Generate a short queue number (demo): P- + random 3 digits
    const generated = 'P-' + Math.floor(100 + Math.random() * 900);
    if (modalQueueNo) modalQueueNo.textContent = generated;

    queueModal.style.display = 'flex';
  });

  // Go Home button -- redirect to landing.html
  if (goHomeBtn) {
    goHomeBtn.addEventListener('click', () => {
      window.location.href = 'landing.html';
    });
  }

  // View Queue Status -- redirect to queue.html
  if (viewQueueBtn) {
    viewQueueBtn.addEventListener('click', () => {
      window.location.href = 'queue.html';
    });
  }

  // Close modal when clicking outside modal content
  queueModal.addEventListener('click', (ev) => {
    if (ev.target === queueModal) queueModal.style.display = 'none';
  });
});



