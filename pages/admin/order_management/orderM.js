document.addEventListener("DOMContentLoaded", function () {
  const tabs = Array.from(document.querySelectorAll(".tab"));
  const path = window.location.pathname.replace(/\\/g, "/");
  const current = path.substring(path.lastIndexOf("/") + 1);

  tabs.forEach((tab) => {
    const href = tab.getAttribute("href") || "";
    const hfile = href.split("/").pop().split("?")[0];
    if (hfile === current && !document.querySelector(".tab.active")) {
      tab.classList.add("active");
    }
  });

  tabs.forEach((tab) => {
    tab.addEventListener("click", function () {
      tabs.forEach((item) => item.classList.remove("active"));
      this.classList.add("active");
    });
  });

  const overlay = document.getElementById("orderModalOverlay");
  const modal = document.getElementById("orderModal");
  const serviceEl = document.getElementById("orderModalService");
  const titleEl = document.getElementById("orderModalTitle");
  const summaryEl = document.getElementById("orderModalSummary");
  const detailsEl = document.getElementById("orderModalDetails");
  const statusEl = document.getElementById("omStatus");
  const commentsEl = document.getElementById("omComments");
  const errorEl = document.getElementById("omError");
  const messageBtn = document.getElementById("orderModalMessage");
  const actionUrl = document.body?.dataset.orderActionUrl || "";
  let currentOrder = null;

  if (!overlay || !modal || !summaryEl || !detailsEl || !statusEl) {
    return;
  }

  const csrf = () => (window.servitechCsrfToken ? window.servitechCsrfToken() : "");
  const actionMap = {
    PENDING: "pending",
    ONGOING: "ongoing",
    "FOR PICK-UP": "pickup",
    DONE: "done",
    CANCELLED: "cancel",
  };

  function esc(value) {
    return String(value ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function showError(message) {
    if (!errorEl) return;
    errorEl.textContent = message;
    errorEl.style.display = "block";
  }

  function clearError() {
    if (!errorEl) return;
    errorEl.textContent = "";
    errorEl.style.display = "none";
  }

  function detailRow(label, value) {
    const cleanValue = String(value ?? "").trim();
    if (!cleanValue) return "";
    return `
      <div class="order-detail-row">
        <span>${esc(label)}</span>
        <strong>${esc(cleanValue)}</strong>
      </div>
    `;
  }

  function fileRows(files) {
    if (!Array.isArray(files) || files.length === 0) {
      return detailRow("Attached File", "No file");
    }

    const fileHtml = files.map((file) => {
      const label = esc(file.label || "File");
      const url = String(file.url || "").trim();
      if (!url) {
        return `<span class="order-file-chip"><span>${label}</span><em>File unavailable</em></span>`;
      }

      return `
        <span class="order-file-chip">
          <span>${label}</span>
          <span class="order-file-actions">
            <a href="${esc(url)}" target="_blank" rel="noopener noreferrer">Open</a>
            <a href="${esc(url)}" download>Download</a>
          </span>
        </span>
      `;
    }).join("");

    return `
      <div class="order-detail-row order-detail-row--files">
        <span>Attached File</span>
        <strong>${fileHtml}</strong>
      </div>
    `;
  }

  function renderOrder(order) {
    const baseRows = [
      detailRow("Queue ID", order.queueCode),
      detailRow("Customer Name", order.customer),
      detailRow("Service Type", order.serviceType),
      detailRow("Service", order.serviceLabel),
      ...(Array.isArray(order.details) ? order.details.map((row) => detailRow(row.label, row.value)) : []),
      fileRows(order.files),
      detailRow("Payment Method", order.paymentMethod),
      detailRow("Payment Reference", order.paymentReference || "-"),
      detailRow("Payment Status", order.paymentStatus),
      detailRow("Price", order.price || "-"),
      detailRow("Submitted Date", order.submitted),
      detailRow("Completed Date", order.completed || "-"),
    ].join("");

    serviceEl.textContent = order.serviceType || "Order Details";
    titleEl.textContent = order.queueCode || "Order Details";
    summaryEl.innerHTML = `
      <div>
        <span>Customer</span>
        <strong>${esc(order.customer || "-")}</strong>
      </div>
      <div>
        <span>Status</span>
        <strong>${esc(order.status || "PENDING")}</strong>
      </div>
    `;
    detailsEl.innerHTML = baseRows;
    commentsEl.value = String(order.comments || "").trim() || "No additional comments.";

    const currentStatus = String(order.status || "PENDING").trim().toUpperCase();
    const exists = Array.from(statusEl.options).some((option) => option.value === currentStatus);
    statusEl.value = exists ? currentStatus : "PENDING";

    if (messageBtn) {
      const canMessage = Boolean(order.canMessage);
      messageBtn.hidden = !canMessage;
      messageBtn.dataset.id = order.id || "";
      messageBtn.dataset.queueCode = order.queueCode || "";
      messageBtn.dataset.customer = order.customer || "";
      messageBtn.dataset.service = order.serviceLabel || order.serviceType || "";
    }
  }

  function openModal(order) {
    currentOrder = order;
    clearError();
    renderOrder(order);
    overlay.classList.add("active");
    modal.classList.add("active");
    modal.setAttribute("aria-hidden", "false");
    statusEl.focus();
  }

  function closeModal() {
    overlay.classList.remove("active");
    modal.classList.remove("active");
    modal.setAttribute("aria-hidden", "true");
    clearError();
  }

  async function postAction(id, action) {
    const fd = new FormData();
    fd.append("id", id);
    fd.append("action", action);

    const response = await fetch(actionUrl, {
      method: "POST",
      body: fd,
      credentials: "same-origin",
      headers: { "X-CSRF-Token": csrf() },
    });

    const text = await response.text();
    try {
      return JSON.parse(text);
    } catch (error) {
      return { ok: false, error: "Server returned non-JSON: " + text };
    }
  }

  document.querySelectorAll(".view-order-btn").forEach((button) => {
    button.addEventListener("click", () => {
      try {
        const order = JSON.parse(button.dataset.order || "{}");
        order.id = order.id || button.dataset.id || "";
        openModal(order);
      } catch (error) {
        showError("Unable to open order details.");
      }
    });
  });

  document.getElementById("orderModalClose")?.addEventListener("click", closeModal);
  document.getElementById("omCancel")?.addEventListener("click", closeModal);
  overlay.addEventListener("click", closeModal);

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && modal.classList.contains("active")) {
      closeModal();
    }
  });

  document.getElementById("omSave")?.addEventListener("click", async () => {
    if (!currentOrder?.id) return;

    const action = actionMap[statusEl.value];
    if (!action) {
      showError("Invalid status selected.");
      return;
    }

    const out = await postAction(currentOrder.id, action);
    if (!out.ok) {
      showError(out.error || "Failed to update status.");
      return;
    }

    location.reload();
  });
});
