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
  const currentStatusEl = document.getElementById("omCurrentStatus");
  const statusEl = document.getElementById("omStatus");
  const statusHelpEl = document.getElementById("omStatusHelp");
  const errorEl = document.getElementById("omError");
  const messageBtn = document.getElementById("orderModalMessage");
  const saveBtn = document.getElementById("omSave");
  const actionUrl = document.body?.dataset.orderActionUrl || "";
  let currentOrder = null;
  let previousStatusSelection = "";
  let cancellationInProgress = false;

  const csrf = () => (window.servitechCsrfToken ? window.servitechCsrfToken() : "");
  const actionMap = {
    PENDING: "pending",
    APPROVED: "approved",
    ONGOING: "ongoing",
    "FOR PICK-UP": "pickup",
    DONE: "done",
    CANCELLED: "cancel",
  };
  const statusLabels = {
    PENDING: "Pending",
    APPROVED: "Approved",
    ONGOING: "Ongoing",
    "FOR PICK-UP": "For Pick-up",
    DONE: "Done",
    CANCELLED: "Cancelled",
  };

  function normalizeStatus(status) {
    const value = String(status || "PENDING").trim().toUpperCase().replace(/[\s_]+/g, " ");
    if (value === "FOR PICK UP" || value === "FOR PICKUP") return "FOR PICK-UP";
    if (value === "CANCELED") return "CANCELLED";
    return value || "PENDING";
  }

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

  function commentsRow(value) {
    const comments = String(value || "").trim() || "No additional comments.";
    return `
      <label class="order-detail-row order-detail-row--comments">
        <span>Additional Comments</span>
        <textarea rows="4" readonly>${esc(comments)}</textarea>
      </label>
    `;
  }

  function statusClass(status) {
    const key = normalizeStatus(status).toLowerCase().replace(/[\s_]+/g, "-");
    if (key === "approved") return "status-approved";
    if (key === "ongoing" || key === "inprogress") return "status-ongoing";
    if (key === "for-pick-up" || key === "for-pickup") return "status-pickup";
    if (key === "done" || key === "complete") return "status-done";
    if (key === "cancelled" || key === "canceled" || key === "onhold") return "status-cancelled";
    return "status-pending";
  }

  function updateSaveButton() {
    if (!saveBtn || !statusEl) return;
    const currentStatus = normalizeStatus(currentOrder?.status);
    saveBtn.disabled = statusEl.disabled || statusEl.value === currentStatus;
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
      commentsRow(order.comments),
    ].join("");

    if (serviceEl) serviceEl.textContent = order.serviceType || "Order Details";
    if (titleEl) titleEl.textContent = order.queueCode || "Order Details";
    summaryEl.innerHTML = `
      <div>
        <span>Customer</span>
        <strong>${esc(order.customer || "-")}</strong>
      </div>
      <div class="order-modal-summary-status ${statusClass(order.status)}">
        <span>Status</span>
        <strong>${esc(order.status || "PENDING")}</strong>
      </div>
    `;
    detailsEl.innerHTML = baseRows;
    const currentStatus = normalizeStatus(order.status);
    const allowedStatuses = Array.isArray(order.allowedStatuses)
      ? order.allowedStatuses.map(normalizeStatus).filter((status) => statusLabels[status])
      : [];
    const selectableStatuses = [currentStatus, ...allowedStatuses]
      .filter((status, index, statuses) => statusLabels[status] && statuses.indexOf(status) === index);
    statusEl.innerHTML = selectableStatuses
      .map((value) => `<option value="${esc(value)}">${esc(statusLabels[value])}</option>`)
      .join("");
    if (!selectableStatuses.length) {
      statusEl.innerHTML = '<option value="PENDING">Pending</option>';
    }
    statusEl.value = currentStatus;
    statusEl.disabled = allowedStatuses.length === 0;
    previousStatusSelection = currentStatus;
    if (currentStatusEl) {
      currentStatusEl.className = `status-badge ${statusClass(currentStatus)}`;
      currentStatusEl.textContent = statusLabels[currentStatus] || currentStatus;
    }
    if (statusHelpEl) {
      statusHelpEl.textContent = allowedStatuses.length
        ? "Select the next valid status, then click Update."
        : "This order has no further status updates.";
    }
    updateSaveButton();

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
    if (!overlay || !modal || !summaryEl || !detailsEl || !statusEl) {
      return;
    }

    currentOrder = order;
    clearError();
    renderOrder(order);
    overlay.classList.add("active");
    modal.classList.add("active");
    modal.setAttribute("aria-hidden", "false");
    window.servitechAdminModalStack?.open({
      overlay,
      dialog: modal,
      focus: statusEl,
      onEscape: closeModal,
    });
  }

  function closeModal() {
    if (!overlay || !modal) return;
    window.servitechAdminModalStack?.close(overlay);
    overlay.classList.remove("active");
    modal.classList.remove("active");
    modal.setAttribute("aria-hidden", "true");
    clearError();
  }

  async function postAction(id, action, notes = "") {
    const fd = new FormData();
    fd.append("id", id);
    fd.append("action", action);
    fd.append("notes", notes);

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

  async function cancelCurrentOrder() {
    if (!currentOrder?.id || cancellationInProgress) return;
    cancellationInProgress = true;
    clearError();

    if (typeof window.servitechRequestCancellationReason !== "function") {
      showError("Cancellation dialog is unavailable. Refresh the page and try again.");
      cancellationInProgress = false;
      return;
    }

    const notes = await window.servitechRequestCancellationReason();
    if (!notes) {
      statusEl.value = previousStatusSelection;
      updateSaveButton();
      cancellationInProgress = false;
      return;
    }

    const out = await postAction(currentOrder.id, "cancel", notes);
    if (!out.ok) {
      statusEl.value = previousStatusSelection;
      updateSaveButton();
      showError(out.error || "Failed to cancel order.");
      cancellationInProgress = false;
      return;
    }

    location.reload();
  }

  function openFromButton(button) {
    let order = {};
    try {
      order = JSON.parse(button.dataset.order || "{}");
    } catch (error) {
      order = {};
    }

    order.id = order.id || button.dataset.id || "";
    order.queueCode = order.queueCode || button.dataset.id || "Order Details";
    order.customer = order.customer || "-";
    order.status = order.status || "PENDING";
    order.serviceType = order.serviceType || "Order Details";
    order.serviceLabel = order.serviceLabel || "Service";
    openModal(order);
  }

  document.querySelectorAll(".view-order-btn").forEach((button) => {
    button.disabled = false;
    button.addEventListener("click", function (event) {
      event.preventDefault();
      openFromButton(this);
    });
  });

  document.getElementById("orderModalClose")?.addEventListener("click", closeModal);
  document.getElementById("omCancel")?.addEventListener("click", closeModal);
  overlay?.addEventListener("click", closeModal);
  statusEl?.addEventListener("change", () => {
    if (statusEl.value === "CANCELLED") {
      statusEl.value = previousStatusSelection;
      updateSaveButton();
      cancelCurrentOrder();
      return;
    }
    previousStatusSelection = statusEl.value;
    updateSaveButton();
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && modal?.classList.contains("active")) {
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

    let notes = "";
    if (action === "cancel") {
      statusEl.value = previousStatusSelection;
      updateSaveButton();
      await cancelCurrentOrder();
      return;
    }

    const out = await postAction(currentOrder.id, action, notes);
    if (!out.ok) {
      showError(out.error || "Failed to update status.");
      return;
    }

    location.reload();
  });

  function debounce(callback, delay = 350) {
    let timeoutId;
    return function (...args) {
      window.clearTimeout(timeoutId);
      timeoutId = window.setTimeout(() => callback.apply(this, args), delay);
    };
  }

  function initOrderTableFilters(toolbar) {
    const table = document.getElementById(toolbar.dataset.tableId || "");
    const tbody = table?.querySelector("tbody");
    if (!table || !tbody) return;

    const rows = Array.from(tbody.querySelectorAll(".order-data-row"));
    const searchInput = toolbar.querySelector("[data-order-filter-search]");
    const dateInput = toolbar.querySelector("[data-order-filter-date]");
    const statusInputs = Array.from(toolbar.querySelectorAll("[data-order-filter-status]"));
    const statusLabel = toolbar.querySelector("[data-order-filter-status-label]");
    const paymentInput = toolbar.querySelector("[data-order-filter-payment]");
    const clearButton = toolbar.querySelector("[data-order-filter-clear]");
    const resultsEl = toolbar.querySelector("[data-order-filter-results]");
    const statusDetails = toolbar.querySelector(".order-status-filter");

    Array.from(tbody.children).forEach((row) => {
      if (!row.classList.contains("order-data-row")) {
        row.hidden = true;
      }
    });

    const noResultsRow = document.createElement("tr");
    noResultsRow.className = "order-no-results-row";
    noResultsRow.hidden = true;
    noResultsRow.innerHTML = '<td colspan="6">No results found</td>';
    tbody.appendChild(noResultsRow);

    function sortLatestFirst() {
      rows
        .slice()
        .sort((left, right) => {
          const rightTime = Date.parse(right.dataset.submittedAt || "") || 0;
          const leftTime = Date.parse(left.dataset.submittedAt || "") || 0;
          return rightTime - leftTime;
        })
        .forEach((row) => tbody.insertBefore(row, noResultsRow));
    }

    function selectedStatuses() {
      return statusInputs.filter((input) => input.checked).map((input) => input.value);
    }

    function updateStatusLabel(statuses) {
      if (!statusLabel) return;
      statusLabel.textContent = statuses.length ? `${statuses.length} selected` : "All statuses";
    }

    function updateResults() {
      const query = String(searchInput?.value || "").trim().toLowerCase();
      const submittedDate = String(dateInput?.value || "").trim();
      const statuses = selectedStatuses();
      const paymentMethod = String(paymentInput?.value || "").trim().toLowerCase();
      let visibleCount = 0;

      sortLatestFirst();

      rows.forEach((row) => {
        const orderId = String(row.dataset.orderId || "").toLowerCase();
        const customer = String(row.dataset.customer || "").toLowerCase();
        const rowStatus = String(row.dataset.status || "").toUpperCase();
        const rowPaymentMethod = String(row.dataset.paymentMethod || "").toLowerCase();
        const rowSubmittedDate = String(row.dataset.submittedDate || "");

        const matchesSearch = !query || orderId.includes(query) || customer.includes(query);
        const matchesDate = !submittedDate || rowSubmittedDate === submittedDate;
        const matchesStatus = !statuses.length || statuses.includes(rowStatus);
        const matchesPayment = !paymentMethod || rowPaymentMethod === paymentMethod;
        const matches = matchesSearch && matchesDate && matchesStatus && matchesPayment;

        row.hidden = !matches;
        if (matches) visibleCount += 1;
      });

      noResultsRow.hidden = visibleCount !== 0;
      if (resultsEl) {
        resultsEl.textContent = `${visibleCount} ${visibleCount === 1 ? "result" : "results"} found`;
      }
      updateStatusLabel(statuses);
    }

    searchInput?.addEventListener("input", debounce(updateResults));
    dateInput?.addEventListener("change", updateResults);
    paymentInput?.addEventListener("change", updateResults);
    statusInputs.forEach((input) => input.addEventListener("change", updateResults));

    clearButton?.addEventListener("click", () => {
      if (searchInput) searchInput.value = "";
      if (dateInput) dateInput.value = "";
      if (paymentInput) paymentInput.value = "";
      statusInputs.forEach((input) => {
        input.checked = false;
      });
      if (statusDetails) statusDetails.open = false;
      updateResults();
    });

    updateResults();
  }

  document.querySelectorAll("[data-order-filter-toolbar]").forEach(initOrderTableFilters);
});
