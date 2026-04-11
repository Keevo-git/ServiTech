<?php
require_once __DIR__ . "/../../components/auth_guard.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Service Status</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="/assets/css/style.css?v=20260326a6">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260326a6">
  <style>
    body.customer-layout.customer-page--status .status-page {
      padding-inline: clamp(16px, 4vw, 32px);
    }

    body.customer-layout.customer-page--status .status-shell {
      margin-top: 0;
    }

    body.customer-layout.customer-page--status .status-page-header {
      display: grid;
      grid-template-columns: auto minmax(0, 1fr);
      align-items: start;
      gap: clamp(12px, 2vw, 16px);
      padding: clamp(14px, 2.5vw, 18px) clamp(16px, 3vw, 24px);
    }

    body.customer-layout.customer-page--status .status-page-back {
      width: clamp(44px, 7vw, 52px);
      height: clamp(44px, 7vw, 52px);
      min-height: 44px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      justify-self: start;
      align-self: start;
      padding: 0;
      border-radius: 999px;
      border: 1px solid rgba(255, 255, 255, 0.42);
      background: rgba(255, 255, 255, 0.16);
      text-decoration: none;
      cursor: pointer;
      overflow: hidden;
      box-sizing: border-box;
      flex-shrink: 0;
      transition: background-color 0.2s ease, opacity 0.2s ease, transform 0.2s ease;
    }

    body.customer-layout.customer-page--status .status-page-back:hover {
      background: rgba(255, 255, 255, 0.28);
      opacity: 0.96;
    }

    body.customer-layout.customer-page--status .status-page-back:active {
      background: rgba(255, 255, 255, 0.34);
      transform: scale(0.98);
    }

    body.customer-layout.customer-page--status .status-page-back:focus-visible {
      outline: 2px solid rgba(255, 255, 255, 0.92);
      outline-offset: 2px;
    }

    body.customer-layout.customer-page--status .status-page-back img {
      width: clamp(24px, 4vw, 30px);
      max-width: 100%;
      height: auto;
      display: block;
      object-fit: contain;
      pointer-events: none;
    }

    body.customer-layout.customer-page--status .status-page-header strong {
      min-width: 0;
      align-self: center;
      line-height: 1.2;
    }

    @media (max-width: 640px) {
      body.customer-layout.customer-page--status .status-page {
        padding-inline: 14px;
      }

      body.customer-layout.customer-page--status .status-page-header {
        gap: 12px;
        padding: 14px 16px;
      }
    }

    @media (min-width: 1025px) {
      body.customer-layout.customer-page--status .status-page-back {
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.16);
      }
    }
  </style>
</head>
<body class="customer-layout customer-page--status">

<?php include __DIR__ . "/../../components/header.php"; ?>

<main class="form-page status-page">
  <section class="status-shell">
    <div class="status-page-header">
      <a href="/pages/customer/customer_dash.php" class="status-page-back" aria-label="Back to dashboard">
        <img src="/assets/images/arrow.png" alt="" aria-hidden="true">
      </a>
      <strong>Service Status</strong>
    </div>

    <div class="status-panel">
      <h3 class="status-section-title">YOUR QUEUES</h3>
      <div id="queueList" class="queue-list"></div>
    </div>

    <div id="detailModal" class="modal-overlay" style="display:none;">
      <div class="modal status-modal" role="dialog" aria-modal="true" aria-labelledby="detailModalTitle" tabindex="-1">
        <button id="closeDetail" class="modal-close" type="button" aria-label="Close details">&times;</button>

        <h3 id="detailModalTitle" class="modal-title">
          Queue: <span id="modalQueue"></span>
        </h3>

        <div class="modal-divider"></div>

        <div class="modal-body">
          <div class="status-detail-row">
            <span class="status-detail-label">Category</span>
            <span id="modalType" class="status-detail-value"></span>
          </div>

          <div class="status-detail-row">
            <span class="status-detail-label">Service</span>
            <span id="modalService" class="status-detail-value"></span>
          </div>

          <div id="modalExtra"></div>

          <div class="status-detail-row status-detail-row--files">
            <span id="modalFileLabel" class="status-detail-label">Attached File</span>
            <div id="modalFile" class="status-detail-value file-list"></div>
          </div>

          <div class="status-detail-row modal-price">
            <span class="status-detail-label">Price</span>
            <span id="modalPrice" class="status-detail-value">To be assessed</span>
          </div>

          <div class="status-notes">
            <label for="modalNotes">Notes</label>
            <textarea id="modalNotes" readonly></textarea>
          </div>

          <div class="modal-status">
            <span class="status-detail-label">Current Status</span>
            <span id="modalStatus"></span>
          </div>
        </div>

        <button id="modalCloseBtn" class="modal-back" type="button">Back</button>
      </div>
    </div>
  </section>
</main>

<?php include __DIR__ . "/../../components/footer.php"; ?>

<script>
(async function(){
  const listEl = document.getElementById("queueList");
  const detailModal = document.getElementById("detailModal");
  const statusModal = detailModal?.querySelector(".status-modal");
  const closeDetail = document.getElementById("closeDetail");
  const modalCloseBtn = document.getElementById("modalCloseBtn");

  let lastFocused = null;

  function servitechBasePath(){
    const pathname = window.location.pathname || "";
    if (pathname === "/ServiTech" || pathname.startsWith("/ServiTech/")) return "/ServiTech";
    return "";
  }

  function servitechUrl(path){
    const cleanPath = path.startsWith("/") ? path : `/${path}`;
    return `${servitechBasePath()}${cleanPath}`;
  }

  function esc(s){
    return (s ?? "").toString().replace(/[&<>"']/g, c => ({
      "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"
    }[c]));
  }

  function toNumber(value){
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
  }

  function toPeso(value){
    const n = toNumber(value);
    return `\u20B1${(n ?? 0).toFixed(2)}`;
  }

  function resolveFileHref(path){
    const raw = (path || "").toString().trim();
    if (!raw) return "";
    if (/^https?:\/\//i.test(raw)) return raw;
    if (raw.startsWith("/")) return servitechUrl(raw);
    return "";
  }

  function formatLabel(value){
    return (value || "")
      .toString()
      .trim()
      .replace(/[_-]+/g, " ")
      .toLowerCase()
      .replace(/(^|\s)\S/g, (match) => match.toUpperCase());
  }

  function badgeTone(status){
    const s = (status || "PENDING").toUpperCase();
    if (s.includes("ONGOING")) return "ongoing";
    if (s.includes("FOR PICK-UP") || s.includes("READY")) return "ready";
    if (s.includes("CANCEL")) return "cancel";
    return "pending";
  }

  function renderState(message, actionHtml){
    listEl.innerHTML = `
      <div class="status-empty-state">
        <p class="muted">${esc(message)}</p>
        ${actionHtml || ""}
      </div>
    `;
  }

  function buildCard(q){
    const div = document.createElement("div");
    const tone = badgeTone(q.status);
    div.className = "card queue-card";
    div.tabIndex = 0;
    div.setAttribute("role", "button");
    div.setAttribute("aria-label", `Open details for queue ${q.queue_code || ""}`);

    div.dataset.queue = q.queue_code || "";
    div.dataset.type = q.category || "";
    div.dataset.service = q.service_label || "";
    div.dataset.paper = q.paper_size || "";
    div.dataset.qty = q.quantity || "";
    div.dataset.color = q.color_option || "";
    div.dataset.pkg = q.package_label || "";
    div.dataset.lam = q.lamination_type || "";
    div.dataset.device = q.device_type || "";
    div.dataset.notes = q.notes || "";
    div.dataset.file = q.file_name || "";
    div.dataset.status = q.status || "";
    div.queueData = q;

    div.innerHTML = `
      <div class="queue-card__head">
        <div class="queue-card__code">${esc(q.queue_code)}</div>
        <div class="queue-card__badge queue-card__badge--${tone}">${esc(q.status || "PENDING")}</div>
      </div>
      <hr class="queue-card__divider">
      <p class="queue-card__meta">
        <strong>${esc(q.service_label || "Service")}</strong>
        <small>${esc(formatLabel(q.category || ""))}</small>
      </p>
    `;

    return div;
  }

  function getInstallationPriceLabel(serviceLabel){
    const normalized = (serviceLabel || "").toString().trim().toLowerCase();
    if (!normalized) return "";

    const ranges = [
      ["reprogram service", [1000, 4000]],
      ["hang logo fix service", [1000, 3500]],
      ["boot loop fix service", [1000, 5000]],
      ["openline samsung & iphone", [3500, 6000]],
      ["bypass google account", [500, 2000]],
      ["bypass password", [1000, 3000]],
    ];

    const match = ranges.find(([label]) => normalized.includes(label));
    if (!match) return "";

    return `${toPeso(match[1][0])} - ${toPeso(match[1][1])}`;
  }

  function getQueuePriceLabel(queueData){
    const details = queueData && typeof queueData.details === "object" && queueData.details
      ? queueData.details
      : {};

    const directEstimate = toNumber(queueData.estimated_total ?? details.estimated_total);
    if (directEstimate !== null && directEstimate > 0) {
      return toPeso(directEstimate);
    }

    let totalPages = toNumber(queueData.total_pages ?? details.total_pages);
    const pricePerPage = toNumber(queueData.price_per_page ?? details.price_per_page);
    const quantity = Math.max(1, toNumber(queueData.quantity ?? details.quantity) ?? 1);
    const fileAnalysis = Array.isArray(queueData.file_analysis)
      ? queueData.file_analysis
      : (Array.isArray(details.file_analysis) ? details.file_analysis : []);

    if (totalPages === null && fileAnalysis.length) {
      totalPages = fileAnalysis.reduce((sum, file) => {
        const pages = toNumber(file.page_count ?? file.slide_count) ?? 0;
        return sum + pages;
      }, 0);
    }

    if (totalPages !== null && pricePerPage !== null && pricePerPage > 0) {
      return toPeso(totalPages * pricePerPage * quantity);
    }

    const serviceLabel = (queueData.service_label || details.service_label || "").toString();
    const serviceLower = serviceLabel.toLowerCase();
    const packageLabel = (queueData.package_label || details.package_label || "").toString();
    const paperSize = (queueData.paper_size || details.paper_size || "").toString();
    const laminationType = (queueData.lamination_type || details.lamination_type || "").toString().toLowerCase();
    const xeroxPriceMap = {
      "Long Bond (8.5 x 13)": 5,
      "Short Bond (8.5 x 11)": 3,
      "A4": 3,
      "A3": 5,
    };

    if (serviceLower.includes("xerox") && xeroxPriceMap[paperSize]) {
      return toPeso(xeroxPriceMap[paperSize] * quantity);
    }

    if (serviceLower.includes("laminating")) {
      const laminationPrice = laminationType === "thin" ? 20 : laminationType === "thick" ? 30 : null;
      if (laminationPrice !== null) {
        return toPeso(laminationPrice * quantity);
      }
    }

    if (serviceLower.includes("rush id") || packageLabel) {
      const match = packageLabel.match(/(?:\u20B1|PHP\s*)([0-9]+(?:\.[0-9]{1,2})?)/i);
      if (match) {
        return toPeso(Number(match[1]) * quantity);
      }
    }

    const installationRange = getInstallationPriceLabel(serviceLabel);
    if (installationRange) {
      return installationRange;
    }

    return "To be assessed";
  }

  function trapModalFocus(e){
    if (!statusModal || e.key !== "Tab") return;
    const focusables = statusModal.querySelectorAll('button, [href], textarea, input, select, [tabindex]:not([tabindex="-1"])');
    if (!focusables.length) return;

    const first = focusables[0];
    const last = focusables[focusables.length - 1];

    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
      return;
    }

    if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  }

  function closeDetailModal(){
    if (!detailModal) return;
    detailModal.style.display = "none";
    document.body.classList.remove("modal-open");
    document.removeEventListener("keydown", onModalKeydown);
    if (lastFocused && typeof lastFocused.focus === "function") {
      lastFocused.focus();
    }
  }

  function onModalKeydown(e){
    if (e.key === "Escape") {
      e.preventDefault();
      closeDetailModal();
      return;
    }
    trapModalFocus(e);
  }

  function renderAttachedFiles(queueData){
    const fileEl = document.getElementById("modalFile");
    const fileLabelEl = document.getElementById("modalFileLabel");
    const uploadedFiles = Array.isArray(queueData.uploaded_files) ? queueData.uploaded_files : [];
    const fileNames = Array.isArray(queueData.file_names)
      ? queueData.file_names
      : (Array.isArray(queueData.details?.file_names) ? queueData.details.file_names : []);
    const fileAnalysis = Array.isArray(queueData.file_analysis)
      ? queueData.file_analysis
      : (Array.isArray(queueData.details?.file_analysis) ? queueData.details.file_analysis : []);
    const derivedNames = fileAnalysis
      .map((file) => (file && file.file_name ? String(file.file_name).trim() : ""))
      .filter(Boolean);

    fileEl.innerHTML = "";

    function appendEntry(label, href){
      if (!label) return;

      if (href) {
        const link = document.createElement("a");
        link.href = href;
        link.target = "_blank";
        link.rel = "noopener noreferrer";
        link.textContent = label;
        link.className = "file-entry";
        fileEl.appendChild(link);
        return;
      }

      const textNode = document.createElement("span");
      textNode.textContent = label;
      textNode.className = "file-entry";
      fileEl.appendChild(textNode);
    }

    if (uploadedFiles.length) {
      uploadedFiles.forEach((file, index) => {
        const href = resolveFileHref(file.saved_path || file.file_path || "");
        const label = file.original_name || fileNames[index] || derivedNames[index] || file.saved_path || `File ${index + 1}`;
        appendEntry(label, href);
      });
      if (fileLabelEl) fileLabelEl.textContent = uploadedFiles.length > 1 ? "Attached Files" : "Attached File";
      return;
    }

    if (fileNames.length) {
      fileNames.forEach((name) => appendEntry(name, resolveFileHref(name)));
      if (fileLabelEl) fileLabelEl.textContent = fileNames.length > 1 ? "Attached Files" : "Attached File";
      return;
    }

    if (derivedNames.length) {
      derivedNames.forEach((name) => appendEntry(name, resolveFileHref(name)));
      if (fileLabelEl) fileLabelEl.textContent = derivedNames.length > 1 ? "Attached Files" : "Attached File";
      return;
    }

    const fallbackHref = resolveFileHref(queueData.saved_path || queueData.file_path || queueData.file_name || "");
    if (queueData.file_name) {
      appendEntry(queueData.file_name, fallbackHref || "");
      if (fileLabelEl) fileLabelEl.textContent = "Attached File";
      return;
    }

    if (fileLabelEl) fileLabelEl.textContent = "Attached File";
    fileEl.textContent = "-";
  }

  function buildDetailRow(label, value){
    if (!value) return "";
    return `
      <div class="status-detail-row">
        <span class="status-detail-label">${esc(label)}</span>
        <span class="status-detail-value">${esc(value)}</span>
      </div>
    `;
  }

  function openDetail(card){
    const queueData = card.queueData || {};

    document.getElementById("modalQueue").textContent = card.dataset.queue || "";
    document.getElementById("modalType").textContent = formatLabel(card.dataset.type || "");
    document.getElementById("modalService").textContent = card.dataset.service || "";
    document.getElementById("modalNotes").value = card.dataset.notes || "";
    document.getElementById("modalPrice").textContent = getQueuePriceLabel(queueData);
    renderAttachedFiles(queueData);

    const statusEl = document.getElementById("modalStatus");
    const status = (card.dataset.status || "PENDING").toUpperCase();
    const tone = badgeTone(status);
    statusEl.textContent = status;
    statusEl.className = "modal-status-pill modal-status-pill--" + tone;

    const extra = document.getElementById("modalExtra");
    extra.innerHTML = [
      buildDetailRow("Paper Size", card.dataset.paper),
      buildDetailRow("Quantity", card.dataset.qty),
      buildDetailRow("Color", card.dataset.color),
      buildDetailRow("Package", card.dataset.pkg),
      buildDetailRow("Lamination", card.dataset.lam),
      buildDetailRow("Device", card.dataset.device)
    ].join("");
    lastFocused = document.activeElement;
    detailModal.style.display = "flex";
    document.body.classList.add("modal-open");
    document.addEventListener("keydown", onModalKeydown);
    closeDetail?.focus();
  }

  async function loadQueues(){
    renderState("Loading queue list...");

    let res;
    try {
      res = await fetch(servitechUrl("/api/queue_list.php"), {
        credentials: "same-origin",
        headers: {
          "Accept": "application/json",
          "X-Requested-With": "XMLHttpRequest"
        }
      });
    } catch (e) {
      renderState("Could not connect to the server.", '<button id="retryQueuesBtn" type="button" class="btn-primary">Retry</button>');
      return;
    }

    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (e) {
      console.error("RAW response:", text);
      renderState("Server returned an invalid response.", '<button id="retryQueuesBtn" type="button" class="btn-primary">Retry</button>');
      return;
    }

    listEl.innerHTML = "";

    if (!data.ok) {
      renderState(data.error || "Unable to load your queue list.", '<button id="retryQueuesBtn" type="button" class="btn-primary">Retry</button>');
      return;
    }

    if (!data.queues || data.queues.length === 0) {
      renderState("No queues yet.", '<a href="/pages/customer/custo_place_queueing.php" class="btn-primary">Join Queue</a>');
      return;
    }

    data.queues.forEach(q => {
      const card = buildCard(q);
      listEl.appendChild(card);

      card.addEventListener("click", () => openDetail(card));
      card.addEventListener("keydown", (e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          openDetail(card);
        }
      });
    });
  }

  [closeDetail, modalCloseBtn].forEach(btn => {
    if (btn) btn.addEventListener("click", closeDetailModal);
  });

  if (detailModal) {
    detailModal.addEventListener("click", (e) => {
      if (e.target === detailModal) closeDetailModal();
    });
  }

  listEl?.addEventListener("click", (e) => {
    const t = e.target;
    if (t && t.id === "retryQueuesBtn") {
      loadQueues();
    }
  });

  await loadQueues();
})();
</script>

</body>
</html>

