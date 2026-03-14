<?php
require_once __DIR__ . "/../../components/auth_guard.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Service Status</title>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>

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
      <div class="modal status-modal" style="position:relative; text-align:left;">
        <button id="closeDetail" class="modal-close" type="button" aria-label="Close details">&times;</button>

        <h3 class="modal-title">
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

  const closeDetail = document.getElementById("closeDetail");
  const modalCloseBtn = document.getElementById("modalCloseBtn");

  function esc(s){
    return (s ?? "").toString().replace(/[&<>"']/g, c => ({
      "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"
    }[c]));
  }

  function buildCard(q){
    const div = document.createElement("div");
    div.className = "card queue-card";
    div.tabIndex = 0;

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
    div.dataset.created = q.created_at || "";

    div.innerHTML = `
      <div class="queue-card__head">
        <div class="queue-card__code">${esc(q.queue_code)}</div>
        <div class="queue-card__badge">${esc(q.status || "PENDING")}</div>
      </div>
      <hr class="queue-card__divider">
      <p class="queue-card__meta">
        <strong>${esc(q.service_label || "Service")}</strong>
        <small>${esc(q.category || "")}</small>
      </p>
    `;

    return div;
  }

  function openDetail(card){
    document.getElementById("modalQueue").textContent = card.dataset.queue || "";
    document.getElementById("modalType").textContent = card.dataset.type || "";
    document.getElementById("modalService").textContent = card.dataset.service || "";
    document.getElementById("modalNotes").value = card.dataset.notes || "";
    document.getElementById("modalStatus").textContent = card.dataset.status || "PENDING";
    document.getElementById("modalFile").textContent = card.dataset.file || "-";

    const extra = document.getElementById("modalExtra");
    extra.innerHTML = "";

    if (card.dataset.paper) extra.innerHTML += `<div><strong>Paper Size:</strong> ${esc(card.dataset.paper)}</div>`;
    if (card.dataset.qty) extra.innerHTML += `<div><strong>Quantity:</strong> ${esc(card.dataset.qty)}</div>`;
    if (card.dataset.color) extra.innerHTML += `<div><strong>Color:</strong> ${esc(card.dataset.color)}</div>`;
    if (card.dataset.pkg) extra.innerHTML += `<div><strong>Package:</strong> ${esc(card.dataset.pkg)}</div>`;
    if (card.dataset.lam) extra.innerHTML += `<div><strong>Lamination:</strong> ${esc(card.dataset.lam)}</div>`;
    if (card.dataset.device) extra.innerHTML += `<div><strong>Device:</strong> ${esc(card.dataset.device)}</div>`;

    detailModal.style.display = "flex";
  }

  async function loadQueues(){
    listEl.innerHTML = `<p class="muted">Loading...</p>`;

    const res = await fetch("/api/queue_list.php", {
      credentials: "same-origin",
      headers: {
        "Accept": "application/json",
        "X-Requested-With": "XMLHttpRequest"
      }
    });

    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (e) {
      console.error("RAW response:", text);
      listEl.innerHTML = `<p class="muted">Server returned non-JSON. Check console (RAW).</p>`;
      return;
    }

    listEl.innerHTML = "";

    if (!data.ok) {
      listEl.innerHTML = `<p class="muted">Error: ${esc(data.error || "Unknown error")}</p>`;
      return;
    }

    if (!data.queues || data.queues.length === 0) {
      listEl.innerHTML = `<p class="muted">No queues yet.</p>`;
      return;
    }

    data.queues.forEach(q => {
      const card = buildCard(q);
      listEl.appendChild(card);

      card.addEventListener("click", () => openDetail(card));
      card.addEventListener("keydown", (e) => {
        if (e.key === "Enter" || e.key === " ") openDetail(card);
      });
    });
  }

  [closeDetail, modalCloseBtn].forEach(btn => {
    if (btn) btn.addEventListener("click", () => detailModal.style.display = "none");
  });

  if (detailModal) {
    detailModal.addEventListener("click", (e) => {
      if (e.target === detailModal) detailModal.style.display = "none";
    });
  }

  await loadQueues();
})();
</script>

</body>
</html>
