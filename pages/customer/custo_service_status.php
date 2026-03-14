<?php
require_once __DIR__ . "/../../components/auth_guard.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Service Status</title>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260315h2">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260315h2">
</head>
<body class="customer-layout customer-page--status">

<?php include __DIR__ . "/../../components/header.php"; ?>

<main class="form-page status-page">
  <section class="status-shell">
    <div class="status-page-header">
      <a href="/pages/customer/customer_dash.php" class="status-page-back" aria-label="Back to dashboard">&larr;</a>
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
          <p><strong>Category:</strong> <span id="modalType"></span></p>
          <p><strong>Service:</strong> <span id="modalService"></span></p>
          <div id="modalExtra"></div>

          <p class="file-row">
            <strong>Attached File:</strong>
            <span id="modalFile"></span>
          </p>

          <div>
            <label for="modalNotes">Notes</label>
            <textarea id="modalNotes" readonly></textarea>
          </div>

          <div class="modal-status">
            <strong>Status:</strong>
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

  function esc(s){
    return (s ?? "").toString().replace(/[&<>"']/g, c => ({
      "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"
    }[c]));
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

    div.innerHTML = `
      <div class="queue-card__head">
        <div class="queue-card__code">${esc(q.queue_code)}</div>
        <div class="queue-card__badge queue-card__badge--${tone}">${esc(q.status || "PENDING")}</div>
      </div>
      <hr class="queue-card__divider">
      <p class="queue-card__meta">
        <strong>${esc(q.service_label || "Service")}</strong>
        <small>${esc(q.category || "")}</small>
      </p>
    `;

    return div;
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

  function openDetail(card){
    document.getElementById("modalQueue").textContent = card.dataset.queue || "";
    document.getElementById("modalType").textContent = card.dataset.type || "";
    document.getElementById("modalService").textContent = card.dataset.service || "";
    document.getElementById("modalNotes").value = card.dataset.notes || "";
    document.getElementById("modalFile").textContent = card.dataset.file || "-";

    const statusEl = document.getElementById("modalStatus");
    const status = (card.dataset.status || "PENDING").toUpperCase();
    const tone = badgeTone(status);
    statusEl.textContent = status;
    statusEl.className = "modal-status-pill modal-status-pill--" + tone;

    const extra = document.getElementById("modalExtra");
    extra.innerHTML = "";

    if (card.dataset.paper) extra.innerHTML += `<div><strong>Paper Size:</strong> ${esc(card.dataset.paper)}</div>`;
    if (card.dataset.qty) extra.innerHTML += `<div><strong>Quantity:</strong> ${esc(card.dataset.qty)}</div>`;
    if (card.dataset.color) extra.innerHTML += `<div><strong>Color:</strong> ${esc(card.dataset.color)}</div>`;
    if (card.dataset.pkg) extra.innerHTML += `<div><strong>Package:</strong> ${esc(card.dataset.pkg)}</div>`;
    if (card.dataset.lam) extra.innerHTML += `<div><strong>Lamination:</strong> ${esc(card.dataset.lam)}</div>`;
    if (card.dataset.device) extra.innerHTML += `<div><strong>Device:</strong> ${esc(card.dataset.device)}</div>`;

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
      res = await fetch("/api/queue_list.php", {
        credentials: "same-origin",
        headers: {
          "Accept": "application/json",
          "X-Requested-With": "XMLHttpRequest"
        }
      });
    } catch (e) {
      renderState("Could not connect to the server.", '<button id="retryQueuesBtn" type="button" class="btn-next">Retry</button>');
      return;
    }

    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (e) {
      console.error("RAW response:", text);
      renderState("Server returned an invalid response.", '<button id="retryQueuesBtn" type="button" class="btn-next">Retry</button>');
      return;
    }

    listEl.innerHTML = "";

    if (!data.ok) {
      renderState(data.error || "Unable to load your queue list.", '<button id="retryQueuesBtn" type="button" class="btn-next">Retry</button>');
      return;
    }

    if (!data.queues || data.queues.length === 0) {
      renderState("No queues yet.", '<a href="/pages/customer/custo_place_queueing.php" class="btn-next">Join Queue</a>');
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

