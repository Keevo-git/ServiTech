document.addEventListener("DOMContentLoaded", function () {
  const overlay = document.getElementById("queueDetailsOverlay");
  const modal = document.getElementById("queueDetailsModal");
  const titleEl = document.getElementById("queueDetailsTitle");
  const summaryEl = document.getElementById("queueDetailsSummary");
  const detailsEl = document.getElementById("queueDetailsList");
  const currentStatusEl = document.getElementById("queueDetailsCurrentStatus");
  const statusEl = document.getElementById("queueDetailsStatus");
  const statusHelpEl = document.getElementById("queueDetailsStatusHelp");
  const updateBtn = document.getElementById("queueDetailsUpdate");
  const messageBtn = document.getElementById("queueDetailsMessage");
  const paymentSection = document.querySelector(".queue-payment-section");
  const priceEl = document.getElementById("queueDetailsPrice");
  const paidAmountEl = document.getElementById("queueDetailsPaidAmount");
  const paidPendingEl = document.getElementById("queueDetailsPaidPending");
  const paymentHelpEl = document.getElementById("queueDetailsPaymentHelp");
  const sendBackBtn = document.getElementById("queueDetailsSendBack");
  const sendBackOverlay = document.getElementById("queueSendBackOverlay");
  const sendBackModal = document.getElementById("queueSendBackModal");
  const sendBackMessageEl = document.getElementById("queueSendBackMessage");
  const sendBackErrorEl = document.getElementById("queueSendBackError");
  const sendBackSubmitBtn = document.getElementById("queueSendBackSubmit");
  let currentStatus = "PENDING";
  let currentQueue = null;
  let initialPayment = { price: "0.00", paidAmount: "0.00" };
  let transitionButtons = new Map();
  let updateInProgress = false;
  let sendBackInProgress = false;
  const actionStatuses = {
    approved: "APPROVED",
    ongoing: "ONGOING",
    pickup: "FOR PICK-UP",
    done: "DONE",
    cancel: "CANCELLED",
  };
  const statusActions = {
    APPROVED: "approved",
    ONGOING: "ongoing",
    "FOR PICK-UP": "pickup",
    DONE: "done",
    CANCELLED: "cancel",
  };
  const actionMessages = {
    approved: "Status updated to Approved.",
    ongoing: "Status updated to Ongoing.",
    pickup: "Status updated to For Pick-up.",
    done: "Status updated to Done.",
    cancel: "Order cancelled successfully.",
  };
  const statusLabels = {
    PENDING: "Pending",
    APPROVED: "Approved",
    ONGOING: "Ongoing",
    "FOR PICK-UP": "For Pick-up",
    DONE: "Done",
    CANCELLED: "Cancelled",
  };

  function esc(value) {
    return String(value ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function normalizeStatus(status) {
    const value = String(status || "PENDING").trim().toUpperCase().replace(/[\s_]+/g, " ");
    if (value === "FOR PICK UP" || value === "FOR PICKUP") return "FOR PICK-UP";
    if (value === "CANCELED") return "CANCELLED";
    return value || "PENDING";
  }

  function statusClass(status) {
    const key = normalizeStatus(status).toLowerCase().replace(/[\s_]+/g, "-");
    if (key === "approved") return "status-approved";
    if (key === "ongoing") return "status-ongoing";
    if (key === "for-pick-up" || key === "for-pickup") return "status-pickup";
    if (key === "done") return "status-done";
    if (key === "cancelled" || key === "canceled") return "status-cancelled";
    return "status-pending";
  }

  function syncStatusUpdateButton() {
    if (!statusEl || !updateBtn) return;
    updateBtn.disabled = updateInProgress || (statusEl.value === currentStatus && !paymentChanged());
  }

  function renderStatusState(status, allowedStatuses = []) {
    if (!statusEl) return;

    currentStatus = normalizeStatus(status);
    const allowed = Array.isArray(allowedStatuses)
      ? allowedStatuses.map(normalizeStatus).filter((item) => statusLabels[item])
      : [];
    const selectableStatuses = [currentStatus, ...allowed]
      .filter((item, index, statuses) => statusLabels[item] && statuses.indexOf(item) === index);

    statusEl.innerHTML = selectableStatuses
      .map((item) => `<option value="${esc(item)}">${esc(statusLabels[item])}</option>`)
      .join("");
    if (!selectableStatuses.length) {
      statusEl.innerHTML = '<option value="PENDING">Pending</option>';
    }
    statusEl.value = currentStatus;
    statusEl.disabled = allowed.length === 0;

    if (currentStatusEl) {
      currentStatusEl.className = `status-badge ${statusClass(currentStatus)}`;
      currentStatusEl.textContent = statusLabels[currentStatus] || currentStatus;
    }
    if (statusHelpEl) {
      statusHelpEl.textContent = allowed.length
        ? "Select the next valid status, then click Update."
        : "This queue has no further status updates.";
    }

    const summaryStatus = summaryEl?.querySelector(".queue-details-status");
    if (summaryStatus) {
      summaryStatus.className = `queue-details-status ${statusClass(currentStatus)}`;
      const valueEl = summaryStatus.querySelector("strong");
      if (valueEl) valueEl.textContent = statusLabels[currentStatus] || currentStatus;
    }

    syncStatusUpdateButton();
  }

  function csrf() {
    return window.servitechCsrfToken ? window.servitechCsrfToken() : "";
  }

  function amount(value) {
    const number = Number(value);
    return Number.isFinite(number) && number >= 0 ? number : 0;
  }

  function money(value) {
    return `PHP ${amount(value).toFixed(2)}`;
  }

  function paymentChanged() {
    return priceEl?.value !== initialPayment.price || paidAmountEl?.value !== initialPayment.paidAmount;
  }

  function showPaymentError(message = "") {
  }

  function showStatusError(message = "", notify = true) {
    if (message && notify) window.servitechAdminToast?.error(message);
  }

  function clearErrors() {
    showPaymentError("");
    showStatusError("", false);
  }

  function syncPaymentPreview(status = statusEl?.value || currentStatus) {
    if (!priceEl || !paidAmountEl || !paidPendingEl) return true;
    const normalizedStatus = normalizeStatus(status);
    const price = amount(priceEl.value);
    let paidAmount = amount(paidAmountEl.value);

    paidAmountEl.disabled = normalizedStatus === "DONE" || normalizedStatus === "CANCELLED";
    if (normalizedStatus === "DONE") {
      paidAmount = price;
      paidAmountEl.value = price.toFixed(2);
      if (paymentHelpEl) paymentHelpEl.textContent = "Done queues are automatically treated as fully paid.";
    } else if (normalizedStatus === "CANCELLED") {
      paidAmount = 0;
      paidAmountEl.value = "0.00";
      if (paymentHelpEl) paymentHelpEl.textContent = "Cancelled queues automatically keep the paid amount at zero.";
    } else if (paymentHelpEl) {
      paymentHelpEl.textContent = "Paid Pending is calculated from Price minus Paid Amount.";
    }

    const isValid = paidAmount <= price;
    showPaymentError(isValid ? "" : "Paid amount cannot exceed the price.");
    paidPendingEl.textContent = money(normalizedStatus === "CANCELLED" ? 0 : Math.max(0, price - paidAmount));
    syncStatusUpdateButton();
    return isValid;
  }

  function populatePayment(queue) {
    if (!priceEl || !paidAmountEl) return;
    priceEl.value = amount(queue.price).toFixed(2);
    paidAmountEl.value = amount(queue.paidAmount).toFixed(2);
    initialPayment = { price: priceEl.value, paidAmount: paidAmountEl.value };
    syncPaymentPreview(queue.status);
  }

  function applyPaymentResult(out) {
    currentQueue.price = out.price;
    currentQueue.paidAmount = out.paid_amount;
    currentQueue.paidPending = out.paid_pending;
    populatePayment(currentQueue);
  }

  function applyStatusResult(out) {
    const newStatus = normalizeStatus(out.status || currentStatus);
    currentQueue.status = newStatus;
    renderStatusState(newStatus, Array.isArray(out.allowed_transitions) ? out.allowed_transitions : []);
    syncSendBackButton();
    if (out.payment && typeof out.payment === "object") {
      applyPaymentResult({
        price: out.payment.price,
        paid_amount: out.payment.paid_amount,
        paid_pending: out.payment.paid_pending,
      });
    } else {
      syncPaymentPreview(newStatus);
    }
  }

  async function savePayment() {
    if (!currentQueue?.id || !priceEl || !paidAmountEl || !paymentSection) {
      return { attempted: false, ok: true };
    }
    if (!syncPaymentPreview()) {
      return { attempted: true, ok: false, error: "Paid amount cannot exceed the price." };
    }
    if (!paymentChanged()) return { attempted: false, ok: true };

    const fd = new FormData();
    fd.append("id", currentQueue.id);
    fd.append("price", priceEl.value);
    fd.append("paid_amount", paidAmountEl.value);

    let out;
    try {
      const response = await fetch(paymentSection.dataset.paymentUpdateUrl || "", {
        method: "POST",
        body: fd,
        credentials: "same-origin",
        headers: { "X-CSRF-Token": csrf() },
      });
      out = await response.json();
    } catch (error) {
      return { attempted: true, ok: false, error: "Unable to update payment details." };
    }

    if (!out.ok) {
      return { attempted: true, ok: false, error: out.error || "Unable to update payment details." };
    }

    applyPaymentResult(out);
    return { attempted: true, ok: true, message: "Payment details saved successfully.", data: out };
  }

  async function postAction(id, action, notes = "") {
    const fd = new FormData();
    fd.append("id", id);
    fd.append("action", action);
    fd.append("notes", notes);

    const response = await fetch(modal?.dataset.actionUpdateUrl || "", {
      method: "POST",
      body: fd,
      credentials: "same-origin",
      headers: { "X-CSRF-Token": csrf() },
    });

    const text = await response.text();
    try {
      return JSON.parse(text);
    } catch (error) {
      return { ok: false, error: "Server returned an invalid response." };
    }
  }

  async function cancelCurrentQueue() {
    if (!currentQueue?.id || updateInProgress) return;

    if (typeof window.servitechRequestCancellationReason !== "function") {
      showStatusError("Cancellation dialog is unavailable. Refresh the page and try again.");
      return;
    }

    const notes = await window.servitechRequestCancellationReason();
    if (!notes) return;

    updateInProgress = true;
    syncStatusUpdateButton();
    clearErrors();

    let out;
    try {
      out = await postAction(currentQueue.id, "cancel", notes);
    } catch (error) {
      showStatusError("Unable to update the order status.");
      updateInProgress = false;
      syncStatusUpdateButton();
      return;
    }

    if (!out.ok) {
      showStatusError(out.error || "Unable to update the order status.");
      updateInProgress = false;
      syncStatusUpdateButton();
      return;
    }

    window.servitechAdminToast?.persist(actionMessages.cancel);
    location.reload();
  }

  async function saveStatus(action) {
    if (!currentQueue?.id || normalizeStatus(statusEl?.value || currentStatus) === currentStatus) {
      return { attempted: false, ok: true };
    }

    let out;
    try {
      out = await postAction(currentQueue.id, action);
    } catch (error) {
      return { attempted: true, ok: false, error: "Unable to update the order status." };
    }

    if (!out.ok) {
      return { attempted: true, ok: false, error: out.error || "Unable to update the order status." };
    }

    applyStatusResult(out);
    return { attempted: true, ok: true, message: actionMessages[action] || "Order status updated successfully.", data: out };
  }

  function showUpdateResultToasts(paymentResult, statusResult) {
    const paymentTried = Boolean(paymentResult?.attempted);
    const statusTried = Boolean(statusResult?.attempted);
    const paymentOk = !paymentTried || paymentResult.ok;
    const statusOk = !statusTried || statusResult.ok;

    if (paymentTried && statusTried && paymentOk && statusOk) {
      window.servitechAdminToast?.persist("Status and payment updated successfully.");
      location.reload();
      return true;
    }

    if (paymentTried && statusTried && !paymentOk && !statusOk) {
      window.servitechAdminToast?.error("Unable to update status and payment.");
      return false;
    }

    if ((statusTried || paymentTried) && statusOk && paymentOk) {
      const message = statusTried ? (statusResult.message || "Order status updated successfully.") : "Payment details saved successfully.";
      window.servitechAdminToast?.persist(message);
      location.reload();
      return true;
    }

    if (statusTried && !statusOk) window.servitechAdminToast?.error(statusResult.error || "Unable to update the order status.");
    if (statusTried && statusOk) window.servitechAdminToast?.success(statusResult.message || "Order status updated successfully.");
    if (paymentTried && !paymentOk) window.servitechAdminToast?.error(paymentResult.error || "Unable to update payment details.");
    if (paymentTried && paymentOk) window.servitechAdminToast?.success(paymentResult.message || "Payment details saved successfully.");

    return false;
  }

  function detailRow(label, value) {
    const cleanValue = String(value ?? "").trim();
    if (!cleanValue) return "";
    return `<div class="queue-detail-row"><span>${esc(label)}</span><strong>${esc(cleanValue)}</strong></div>`;
  }

  function commentsRow(value) {
    const comments = String(value || "").trim() || "No additional comments.";
    return `
      <label class="queue-detail-row queue-detail-row--comments">
        <span>Additional Comments</span>
        <textarea rows="4" readonly>${esc(comments)}</textarea>
      </label>
    `;
  }

  function fileRows(files) {
    if (!Array.isArray(files) || files.length === 0) {
      return detailRow("Attached File", "No file");
    }

    const items = files.map((file) => {
      const label = esc(file.label || "File");
      const url = String(file.url || "").trim();
      if (!url) {
        return `<span class="queue-detail-file"><span>${label}</span><em>File unavailable</em></span>`;
      }
      return `
        <span class="queue-detail-file">
          <span>${label}</span>
          <span class="queue-detail-file__actions">
            <a href="${esc(url)}" target="_blank" rel="noopener noreferrer">Open</a>
            <a href="${esc(url)}" download>Download</a>
          </span>
        </span>
      `;
    }).join("");

    return `<div class="queue-detail-row queue-detail-row--files"><span>Attached File</span><strong>${items}</strong></div>`;
  }

  function closeDetails() {
    if (!overlay || !modal) return;
    closeSendBackModal();
    window.servitechAdminModalStack?.close(overlay);
    overlay.classList.remove("active");
    modal.classList.remove("active");
    modal.setAttribute("aria-hidden", "true");
  }

  function showSendBackError(message = "") {
    if (sendBackErrorEl) sendBackErrorEl.textContent = message;
  }

  function canSendBack(queue) {
    const status = normalizeStatus(queue?.status || currentStatus);
    return Boolean(queue?.id) && (status === "PENDING" || status === "APPROVED");
  }

  function syncSendBackButton() {
    if (!sendBackBtn) return;
    const allowed = canSendBack(currentQueue);
    sendBackBtn.disabled = sendBackInProgress || !allowed;
    sendBackBtn.title = allowed ? "" : "Only Pending or Approved records can be sent back for customer editing.";
  }

  function openSendBackModal() {
    if (sendBackBtn) sendBackBtn.disabled = false;
    if (!sendBackOverlay || !sendBackModal || !currentQueue?.id || !canSendBack(currentQueue)) {
      showStatusError("Only Pending or Approved records can be sent back for customer editing.");
      return;
    }
    if (sendBackMessageEl) sendBackMessageEl.value = "";
    if (sendBackSubmitBtn) sendBackSubmitBtn.disabled = false;
    showSendBackError("");
    sendBackOverlay.removeAttribute("hidden");
    sendBackModal.removeAttribute("hidden");
    sendBackOverlay.classList.add("active");
    sendBackModal.classList.add("active");
    sendBackModal.setAttribute("aria-hidden", "false");
    window.servitechAdminModalStack?.open({
      overlay: sendBackOverlay,
      dialog: sendBackModal,
      focus: sendBackMessageEl,
      onEscape: closeSendBackModal,
    });
  }

  function closeSendBackModal() {
    if (!sendBackOverlay || !sendBackModal || !sendBackModal.classList.contains("active")) return;
    window.servitechAdminModalStack?.close(sendBackOverlay);
    sendBackOverlay.classList.remove("active");
    sendBackModal.classList.remove("active");
    sendBackModal.setAttribute("aria-hidden", "true");
    sendBackOverlay.setAttribute("hidden", "");
    sendBackModal.setAttribute("hidden", "");
    showSendBackError("");
  }

  async function submitSendBack() {
    if (!currentQueue?.id || sendBackInProgress) return;
    const message = String(sendBackMessageEl?.value || "").trim();
    if (!message) {
      showSendBackError("Message is required before sending this back.");
      sendBackMessageEl?.focus();
      return;
    }

    sendBackInProgress = true;
    syncSendBackButton();
    if (sendBackSubmitBtn) sendBackSubmitBtn.disabled = true;
    showSendBackError("");

    const fd = new FormData();
    fd.append("id", currentQueue.id);
    fd.append("message", message);

    let out;
    try {
      const response = await fetch(modal?.dataset.sendBackUrl || "", {
        method: "POST",
        body: fd,
        credentials: "same-origin",
        headers: { "X-CSRF-Token": csrf() },
      });
      out = await response.json();
    } catch (error) {
      out = { ok: false, error: "Unable to send this record back to the customer." };
    }

    if (!out.ok) {
      showSendBackError(out.error || "Unable to send this record back to the customer.");
      sendBackInProgress = false;
      if (sendBackSubmitBtn) sendBackSubmitBtn.disabled = false;
      syncSendBackButton();
      return;
    }

    closeSendBackModal();
    window.servitechAdminToast?.persist("Sent back to customer for editing.");
    location.reload();
  }

  function renderActions(row) {
    if (!messageBtn) return;
    const sourceButton = row.querySelector(".queue-inline-actions .btn-message");
    messageBtn.hidden = !sourceButton;
    messageBtn.dataset.id = sourceButton?.dataset.id || "";
    messageBtn.dataset.queueCode = sourceButton?.dataset.queueCode || "";
    messageBtn.dataset.customer = sourceButton?.dataset.customer || "";
    messageBtn.dataset.service = sourceButton?.dataset.service || "";
  }

  function renderStatusSection(row, queue) {
    if (!statusEl) return;

    currentStatus = normalizeStatus(queue.status);
    transitionButtons = new Map();
    row.querySelectorAll(".queue-inline-actions [data-action]").forEach((button) => {
      const status = actionStatuses[button.dataset.action];
      if (status) transitionButtons.set(status, button);
    });

    renderStatusState(currentStatus, Array.from(transitionButtons.keys()));
  }

  function openDetails(button) {
    if (!overlay || !modal || !summaryEl || !detailsEl) return;
    const row = button.closest(".queue-data-row");
    if (!row) return;

    let queue = {};
    try {
      queue = JSON.parse(button.dataset.queue || "{}");
    } catch (error) {
      queue = {};
    }
    currentQueue = queue;
    clearErrors();

    titleEl.textContent = queue.queueCode || "Queue Details";
    summaryEl.innerHTML = `
      <div>
        <span>Customer</span>
        <strong>${esc(queue.customer || "-")}</strong>
      </div>
      <div class="queue-details-status ${statusClass(queue.status)}">
        <span>Status</span>
        <strong>${esc(queue.status || "PENDING")}</strong>
      </div>
    `;
    detailsEl.innerHTML = [
      detailRow("Queue ID", queue.queueCode),
      detailRow("Customer Name", queue.customer),
      detailRow("Service", queue.service),
      ...(Array.isArray(queue.details) ? queue.details.map((item) => detailRow(item.label, item.value)) : []),
      fileRows(queue.files),
      detailRow("Payment", queue.payment),
      detailRow("Payment Reference", queue.paymentReference),
      detailRow("Submitted Date", queue.submitted),
      detailRow("Completed Date", queue.completed || "-"),
      commentsRow(queue.comments),
    ].join("");
    renderStatusSection(row, queue);
    populatePayment(queue);
    renderActions(row);
    syncSendBackButton();

    overlay.classList.add("active");
    modal.classList.add("active");
    modal.setAttribute("aria-hidden", "false");
    window.servitechAdminModalStack?.open({
      overlay,
      dialog: modal,
      focus: document.getElementById("queueDetailsClose"),
      onEscape: closeDetails,
    });
  }

  document.querySelectorAll(".queue-view-btn").forEach((button) => {
    button.addEventListener("click", () => openDetails(button));
  });

  function openNotificationQueueDetails() {
    const params = new URLSearchParams(window.location.search);
    if (params.get("open") !== "notification") return;

    const queueId = params.get("queue_id") || "";
    if (!queueId) return;

    const button = Array.from(document.querySelectorAll(".queue-view-btn")).find((candidate) => {
      const row = candidate.closest(".queue-data-row");
      return String(row?.dataset.queueRecordId || candidate.dataset.id || "") === String(queueId);
    });

    if (button) {
      window.setTimeout(() => openDetails(button), 80);
    }
  }

  openNotificationQueueDetails();

  document.getElementById("queueDetailsClose")?.addEventListener("click", closeDetails);
  document.getElementById("queueDetailsCancel")?.addEventListener("click", closeDetails);
  document.getElementById("queueSendBackClose")?.addEventListener("click", closeSendBackModal);
  document.getElementById("queueSendBackCancel")?.addEventListener("click", closeSendBackModal);
  sendBackOverlay?.addEventListener("click", closeSendBackModal);
  sendBackSubmitBtn?.addEventListener("click", submitSendBack);
  sendBackMessageEl?.addEventListener("input", () => showSendBackError(""));
  document.addEventListener("click", (event) => {
    if (event.target?.closest?.("#queueDetailsSendBack")) {
      event.preventDefault();
      event.stopPropagation();
      openSendBackModal();
    }
  });
  messageBtn?.addEventListener("click", closeDetails);
  overlay?.addEventListener("click", closeDetails);
  statusEl?.addEventListener("change", () => {
    if (statusEl.value === "CANCELLED") {
      statusEl.value = currentStatus;
      syncStatusUpdateButton();
      cancelCurrentQueue();
      return;
    }
    showStatusError("", false);
    syncPaymentPreview();
    syncStatusUpdateButton();
  });
  priceEl?.addEventListener("input", () => {
    clearErrors();
    syncPaymentPreview();
  });
  paidAmountEl?.addEventListener("input", () => {
    clearErrors();
    syncPaymentPreview();
  });
  updateBtn?.addEventListener("click", async () => {
    if (!currentQueue?.id || updateInProgress) return;

    updateInProgress = true;
    syncStatusUpdateButton();
    clearErrors();

    const selectedStatus = normalizeStatus(statusEl?.value || currentStatus);
    const action = statusActions[selectedStatus];
    if (selectedStatus !== currentStatus && !action) {
      showStatusError("Invalid status selected.");
      updateInProgress = false;
      syncStatusUpdateButton();
      return;
    }

    const paymentResult = await savePayment();
    const statusResult = await saveStatus(action);
    if (showUpdateResultToasts(paymentResult, statusResult)) return;

    updateInProgress = false;
    syncStatusUpdateButton();
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && modal?.classList.contains("active")) closeDetails();
  });

  function debounce(callback, delay = 350) {
    let timeoutId;
    return function (...args) {
      window.clearTimeout(timeoutId);
      timeoutId = window.setTimeout(() => callback.apply(this, args), delay);
    };
  }

  function initFilters(toolbar) {
    const table = document.getElementById(toolbar.dataset.tableId || "");
    const tbody = table?.querySelector("tbody");
    if (!table || !tbody) return;

    const rows = Array.from(tbody.querySelectorAll(".queue-data-row"));
    const search = toolbar.querySelector("[data-queue-filter-search]");
    const date = toolbar.querySelector("[data-queue-filter-date]");
    const statuses = Array.from(toolbar.querySelectorAll("[data-queue-filter-status]"));
    const statusLabel = toolbar.querySelector("[data-queue-filter-status-label]");
    const payment = toolbar.querySelector("[data-queue-filter-payment]");
    const clear = toolbar.querySelector("[data-queue-filter-clear]");
    const results = toolbar.querySelector("[data-queue-filter-results]");
    const statusMenu = toolbar.querySelector(".queue-status-filter");

    Array.from(tbody.children).forEach((row) => {
      if (!row.classList.contains("queue-data-row")) row.hidden = true;
    });

    const emptyRow = document.createElement("tr");
    emptyRow.className = "queue-no-results-row";
    emptyRow.hidden = true;
    emptyRow.innerHTML = `<td colspan="${table.querySelectorAll("thead th").length}">No results found</td>`;
    tbody.appendChild(emptyRow);

    function applyFilters() {
      const query = String(search?.value || "").trim().toLowerCase();
      const selectedDate = String(date?.value || "");
      const selectedStatuses = statuses.filter((input) => input.checked).map((input) => input.value);
      const selectedPayment = String(payment?.value || "").toLowerCase();
      let visible = 0;

      rows
        .slice()
        .sort((left, right) => {
          const leftTime = Date.parse(left.dataset.queueSubmittedAt || "") || 0;
          const rightTime = Date.parse(right.dataset.queueSubmittedAt || "") || 0;
          if (leftTime !== rightTime) return leftTime - rightTime;
          return Number(left.dataset.queueRecordId || 0) - Number(right.dataset.queueRecordId || 0);
        })
        .forEach((row) => tbody.insertBefore(row, emptyRow));

      rows.forEach((row) => {
        const matchesSearch = !query
          || String(row.dataset.queueSearchId || "").includes(query)
          || String(row.dataset.queueCustomer || "").includes(query);
        const matchesDate = !selectedDate || row.dataset.queueDate === selectedDate;
        const matchesStatus = !selectedStatuses.length || selectedStatuses.includes(row.dataset.queueStatus || "");
        const matchesPayment = !selectedPayment || row.dataset.queuePayment === selectedPayment;
        const matches = matchesSearch && matchesDate && matchesStatus && matchesPayment;
        row.hidden = !matches;
        if (matches) visible += 1;
      });

      emptyRow.hidden = visible !== 0;
      if (results) results.textContent = `${visible} ${visible === 1 ? "result" : "results"} found`;
      if (statusLabel) statusLabel.textContent = selectedStatuses.length ? `${selectedStatuses.length} selected` : "All statuses";
    }

    search?.addEventListener("input", debounce(applyFilters));
    date?.addEventListener("change", applyFilters);
    payment?.addEventListener("change", applyFilters);
    statuses.forEach((input) => input.addEventListener("change", applyFilters));
    clear?.addEventListener("click", () => {
      if (search) search.value = "";
      if (date) date.value = "";
      if (payment) payment.value = "";
      statuses.forEach((input) => { input.checked = false; });
      if (statusMenu) statusMenu.open = false;
      applyFilters();
    });
    applyFilters();
  }

  document.querySelectorAll("[data-queue-filter-toolbar]").forEach(initFilters);
});
