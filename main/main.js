

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
  const lamTypeSelect = document.getElementById("lamTypeSelect");
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
    // Update paper size (if present)
    if (paperSizeSelect && summaryPaperSize) {
      const paperSize = paperSizeSelect.value;
      summaryPaperSize.textContent = paperSize && paperSize !== "Select paper size" ? paperSize : "Not Selected";
    }

    // (color option intentionally not shown in summary)

    // Update quantity
    const qty = parseInt(qtyInput.value) || 1;
    summaryQty.textContent = qty;

    // Determine price per item/page.
    // Priority: lamination option data-price (for laminating), then xerox per-size map, then body data-price-per-page, then default.
    let pricePerPage = defaultPrice;
    if (lamTypeSelect) {
      const opt = lamTypeSelect.options[lamTypeSelect.selectedIndex];
      const optPrice = opt && opt.dataset && opt.dataset.price ? parseFloat(opt.dataset.price) : null;
      if (optPrice !== null) {
        pricePerPage = optPrice;
      } else {
        const bodyPrice = document.body && document.body.dataset && document.body.dataset.pricePerPage;
        pricePerPage = bodyPrice ? parseFloat(bodyPrice) : defaultPrice;
      }
    } else if (isXerox && paperSizeSelect) {
      const selectedSize = paperSizeSelect.value;
      if (selectedSize && xeroxPriceMap[selectedSize] !== undefined) {
        pricePerPage = xeroxPriceMap[selectedSize];
      }
    } else {
      const bodyPrice = document.body && document.body.dataset && document.body.dataset.pricePerPage;
      pricePerPage = bodyPrice ? parseFloat(bodyPrice) : defaultPrice;
    }

    // Update total with chosen price
    summaryTotal.textContent = `₱${(qty * pricePerPage).toFixed(2)}`;
  }

  // Add event listeners (guard elements)
  if (paperSizeSelect) paperSizeSelect.addEventListener("change", updateSummary);
  if (lamTypeSelect) lamTypeSelect.addEventListener("change", updateSummary);
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
    const lamTypeSelect = document.getElementById('lamTypeSelect');
    const packageSelect = document.getElementById('packageSelect');
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

    if (lamTypeSelect) {
      const val = lamTypeSelect.value;
      if (!val || val === 'Select a Package' || val === 'Select lamination type') {
        lamTypeSelect.style.border = '2px solid #e74c3c';
        lamTypeSelect.focus();
        valid = false;
      } else {
        lamTypeSelect.style.border = '';
      }
    }

    if (packageSelect) {
      const val = packageSelect.value;
      if (!val || val === 'Select a Package') {
        packageSelect.style.border = '2px solid #e74c3c';
        packageSelect.focus();
        valid = false;
      } else {
        packageSelect.style.border = '';
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

    // Generate a short queue number (demo): prefix + random 3 digits
    // Use 'R' prefix for repair pages, otherwise default to 'P'
    const svc = document.body && document.body.dataset && document.body.dataset.service ? document.body.dataset.service : '';
    // Prefixes: Repair -> R, Installation -> I, default -> P
    let prefix = 'P';
    if (svc === 'repair') prefix = 'R';
    else if (svc === 'installation') prefix = 'I';
    const generated = prefix + '-' + Math.floor(100 + Math.random() * 900);
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


// Rush ID page: update summary based on selected package and quantity
document.addEventListener('DOMContentLoaded', () => {
  const packageSelect = document.getElementById('packageSelect');
  const qtyInput = document.getElementById('qtyInput');
  const summaryPackage = document.getElementById('summaryPackage');
  const summaryQty = document.getElementById('summaryQty');
  const summaryTotal = document.getElementById('summaryTotal');

  if (!packageSelect || !qtyInput || !summaryTotal) return;

  function updateRushSummary() {
    const pkgOption = packageSelect.options[packageSelect.selectedIndex];
    const pkgLabel = pkgOption && pkgOption.textContent ? pkgOption.textContent : '';
    const price = pkgOption && pkgOption.dataset && pkgOption.dataset.price ? parseFloat(pkgOption.dataset.price) : 0;
    const qty = parseInt(qtyInput.value, 10) || 1;

    summaryPackage.textContent = pkgLabel && pkgLabel !== 'Select a Package' ? pkgLabel : 'Not Selected';
    summaryQty.textContent = qty;
    summaryTotal.textContent = `₱${(price * qty).toFixed(2)}`;
  }

  packageSelect.addEventListener('change', updateRushSummary);
  qtyInput.addEventListener('input', updateRushSummary);

  // initialize
  updateRushSummary();
});



