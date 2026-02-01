/* ==============================
   LANDING PAGE – SERVICE SCROLL
   ============================== */

function scrollToSection(id) {
  const section = document.getElementById(id);
  if (section) {
    section.scrollIntoView({ behavior: 'smooth' });
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
    if (e.target === modal) modal.style.display = 'none';
  });
});

/* ==============================
   CUSTOMER DASHBOARD (QUEUE DEMO ONLY)
   NOTE: Name is now set by PHP from database
   ============================== */

document.addEventListener("DOMContentLoaded", () => {
  const queueNoEl = document.getElementById("queueNo");
  const queueStatusEl = document.getElementById("queueStatus");
  const queueServiceEl = document.getElementById("queueService");
  const queueDetailsEl = document.getElementById("queueDetails");
  const ongoingCountEl = document.getElementById("ongoingCount");

  if (queueNoEl && queueStatusEl && queueServiceEl && queueDetailsEl) {
    let queues = [];
    try {
      queues = JSON.parse(localStorage.getItem("servitech_queues")) || [];
    } catch {
      queues = [];
    }

    if (queues.length === 0) {
      queueNoEl.textContent = "#---";
      queueStatusEl.textContent = "NONE";
      queueServiceEl.textContent = "Service: ---";
      queueDetailsEl.textContent = "Details: ---";

      const noQueueMsg = document.getElementById("noQueueMsg");
      if (noQueueMsg) noQueueMsg.style.display = "block";
    } else {
      const activeQueue = queues[0];
      queueNoEl.textContent = `#${activeQueue.id}`;
      queueStatusEl.textContent = activeQueue.status;
      queueServiceEl.textContent = `Service: ${activeQueue.service}`;
      queueDetailsEl.textContent = activeQueue.meta?.notes
        ? `Details: ${activeQueue.meta.notes}`
        : "Details: ---";
    }

    if (ongoingCountEl) {
      ongoingCountEl.textContent = queues.length.toString().padStart(2, "0");
    }
  }
});

const quickCard = document.querySelector(".quick-card");
if (quickCard) {
  quickCard.addEventListener("click", () => {
    window.location.href = "queue.html";
  });
}

/* custo2_docu_printing.html */

document.addEventListener("DOMContentLoaded", () => {
  const qtyInput = document.getElementById("qtyInput");
  if (!qtyInput) return;

  const paperSizeSelect = document.getElementById("paperSizeSelect");
  const lamTypeSelect = document.getElementById("lamTypeSelect");
  const colorRadios = document.querySelectorAll('input[name="color"]');

  const summaryPaperSize = document.getElementById("summaryPaperSize");
  const summaryQty = document.getElementById("summaryQty");
  const summaryTotal = document.getElementById("summaryTotal");

  const defaultPrice = 5;
  const isXerox = document.body && document.body.dataset && document.body.dataset.service === 'xerox';
  const xeroxPriceMap = {
    'Long Bond (8.5 x 13)': 5,
    'Short Bond (8.5 x 11)': 3,
    'A4': 3,
    'A3': 5
  };

  function updateSummary() {
    if (paperSizeSelect && summaryPaperSize) {
      const paperSize = paperSizeSelect.value;
      summaryPaperSize.textContent = paperSize && paperSize !== "Select paper size" ? paperSize : "Not Selected";
    }

    const qty = parseInt(qtyInput.value) || 1;
    summaryQty.textContent = qty;

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

    summaryTotal.textContent = `₱${(qty * pricePerPage).toFixed(2)}`;
  }

  if (paperSizeSelect) paperSizeSelect.addEventListener("change", updateSummary);
  if (lamTypeSelect) lamTypeSelect.addEventListener("change", updateSummary);
  qtyInput.addEventListener("input", updateSummary);
  colorRadios.forEach(radio => radio.addEventListener("change", updateSummary));

  updateSummary();
});

// Join Queue modal handling
document.addEventListener('DOMContentLoaded', () => {
  const joinBtn = document.getElementById('joinQueueBtn');
  const queueModal = document.getElementById('queueModal');
  const goHomeBtn = document.getElementById('goHomeBtn');
  const viewQueueBtn = document.getElementById('viewQueueBtn');
  const modalQueueNo = document.getElementById('modalQueueNo');

  if (!joinBtn || !queueModal) return;

  joinBtn.addEventListener('click', (e) => {
    e.preventDefault();

    const paperSizeSelect = document.getElementById('paperSizeSelect');
    const lamTypeSelect = document.getElementById('lamTypeSelect');
    const packageSelect = document.getElementById('packageSelect');
    const qtyInput = document.getElementById('qtyInput');
    let valid = true;

    if (paperSizeSelect) {
      const val = paperSizeSelect.value;
      if (!val || val === 'Select paper size') {
        paperSizeSelect.style.border = '2px solid #e74c3c';
        paperSizeSelect.focus();
        valid = false;
      } else paperSizeSelect.style.border = '';
    }

    if (lamTypeSelect) {
      const val = lamTypeSelect.value;
      if (!val || val === 'Select a Package' || val === 'Select lamination type') {
        lamTypeSelect.style.border = '2px solid #e74c3c';
        lamTypeSelect.focus();
        valid = false;
      } else lamTypeSelect.style.border = '';
    }

    if (packageSelect) {
      const val = packageSelect.value;
      if (!val || val === 'Select a Package') {
        packageSelect.style.border = '2px solid #e74c3c';
        packageSelect.focus();
        valid = false;
      } else packageSelect.style.border = '';
    }

    if (qtyInput) {
      const qty = parseInt(qtyInput.value, 10) || 0;
      if (qty < 1) {
        qtyInput.style.border = '2px solid #e74c3c';
        qtyInput.focus();
        valid = false;
      } else qtyInput.style.border = '';
    }

    if (!valid) return;

    const svc = document.body && document.body.dataset && document.body.dataset.service ? document.body.dataset.service : '';
    let prefix = 'P';
    if (svc === 'repair') prefix = 'R';
    else if (svc === 'installation') prefix = 'I';

    const generated = prefix + '-' + Math.floor(100 + Math.random() * 900);
    if (modalQueueNo) modalQueueNo.textContent = generated;

    queueModal.style.display = 'flex';

    try {
      const notesEl = document.getElementById('installationNotes') || document.getElementById('repairNotes') || document.getElementById('notes') || null;
      const entry = {
        id: generated,
        category: svc || 'general',
        service: document.title || '',
        meta: { notes: notesEl ? notesEl.value : '' },
        createdAt: Date.now(),
        status: 'PENDING'
      };

      const key = 'servitech_queues';
      const existing = JSON.parse(localStorage.getItem(key) || '[]');
      existing.unshift(entry);
      localStorage.setItem(key, JSON.stringify(existing.slice(0, 200)));
    } catch (err) {
      console.warn('Could not save queue entry', err);
    }
  });

  if (goHomeBtn) {
    goHomeBtn.addEventListener('click', () => {
      window.location.href = 'landing.html';
    });
  }

  if (viewQueueBtn) {
    viewQueueBtn.addEventListener('click', () => {
      window.location.href = 'custo_service_status.html';
    });
  }

  queueModal.addEventListener('click', (ev) => {
    if (ev.target === queueModal) queueModal.style.display = 'none';
  });
});

// Rush ID page summary
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

    if (summaryPackage) summaryPackage.textContent = pkgLabel && pkgLabel !== 'Select a Package' ? pkgLabel : 'Not Selected';
    if (summaryQty) summaryQty.textContent = qty;
    summaryTotal.textContent = `₱${(price * qty).toFixed(2)}`;
  }

  packageSelect.addEventListener('change', updateRushSummary);
  qtyInput.addEventListener('input', updateRushSummary);
  updateRushSummary();
});

/* REGISTER FORM SUBMISSION (let PHP handle it) */
document.addEventListener("DOMContentLoaded", () => {
  const registerForm = document.getElementById("registerForm");
  if (!registerForm) return;
  registerForm.addEventListener("submit", () => {});
});
