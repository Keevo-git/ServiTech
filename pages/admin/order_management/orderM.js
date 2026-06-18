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
  const updateFeedbackEl = document.getElementById("omUpdateFeedback");
  const messageBtn = document.getElementById("orderModalMessage");
  const saveBtn = document.getElementById("omSave");
  const actionUrl = document.body?.dataset.orderActionUrl || "";
  const paymentSection = document.querySelector(".order-payment-section");
  const priceEl = document.getElementById("omPrice");
  const paidAmountEl = document.getElementById("omPaidAmount");
  const paidPendingEl = document.getElementById("omPaidPending");
  const paymentHelpEl = document.getElementById("omPaymentHelp");
  const sendBackBtn = document.getElementById("omSendBack");
  const sendBackOverlay = document.getElementById("orderSendBackOverlay");
  const sendBackModal = document.getElementById("orderSendBackModal");
  const sendBackMessageEl = document.getElementById("orderSendBackMessage");
  const sendBackErrorEl = document.getElementById("orderSendBackError");
  const sendBackSubmitBtn = document.getElementById("orderSendBackSubmit");
  let currentOrder = null;
  let currentOrderRow = null;
  let initialPayment = { price: "0.00", paidAmount: "0.00" };
  let previousStatusSelection = "";
  let cancellationInProgress = false;
  let updateInProgress = false;
  let sendBackInProgress = false;
  let modalScrollY = 0;

  const csrf = () => (window.servitechCsrfToken ? window.servitechCsrfToken() : "");
  const actionMap = {
    PENDING: "pending",
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

  function showError(message, notify = true) {
    if (notify) window.servitechAdminToast?.error(message);
    showUpdateFeedback(message, "error");
  }

  function clearError() {
    showUpdateFeedback("");
  }

  function showUpdateFeedback(message = "", type = "success") {
    if (!updateFeedbackEl) return;
    updateFeedbackEl.textContent = String(message || "");
    updateFeedbackEl.className = `order-update-feedback${message ? " is-visible" : ""} order-update-feedback--${type}`;
  }

  function amount(value) {
    const number = Number(value);
    return Number.isFinite(number) && number >= 0 ? number : 0;
  }

  function money(value) {
    return `PHP ${amount(value).toFixed(2)}`;
  }

  function paymentSummary(order) {
    const method = String(order?.paymentMethod || "").trim();
    const price = amount(order?.price);
    const total = price > 0 ? money(price) : "";
    if (method && total) return `${method}: ${total}`;
    return method || total;
  }

  function paymentChanged() {
    return priceEl?.value !== initialPayment.price || paidAmountEl?.value !== initialPayment.paidAmount;
  }

  function syncPaymentPreview(status = statusEl?.value || currentOrder?.status) {
    if (!priceEl || !paidAmountEl || !paidPendingEl) return true;
    const normalizedStatus = normalizeStatus(status);
    const price = amount(priceEl.value);
    let paidAmount = amount(paidAmountEl.value);

    paidAmountEl.disabled = normalizedStatus === "DONE" || normalizedStatus === "CANCELLED";
    if (normalizedStatus === "DONE") {
      paidAmount = price;
      paidAmountEl.value = price.toFixed(2);
      if (paymentHelpEl) paymentHelpEl.textContent = "Done orders are automatically treated as fully paid.";
    } else if (normalizedStatus === "CANCELLED") {
      paidAmount = 0;
      paidAmountEl.value = "0.00";
      if (paymentHelpEl) paymentHelpEl.textContent = "Cancelled orders automatically keep the paid amount at zero.";
    } else if (paymentHelpEl) {
      paymentHelpEl.textContent = "Paid Pending is calculated from Price minus Paid Amount.";
    }

    const isValid = paidAmount <= price;
    if (!isValid) showError("Paid amount cannot exceed the price.", false);
    paidPendingEl.textContent = money(normalizedStatus === "CANCELLED" ? 0 : Math.max(0, price - paidAmount));
    return isValid;
  }

  function populatePayment(order) {
    if (!priceEl || !paidAmountEl) return;
    priceEl.value = amount(order.price).toFixed(2);
    paidAmountEl.value = amount(order.paidAmount).toFixed(2);
    initialPayment = { price: priceEl.value, paidAmount: paidAmountEl.value };
    syncPaymentPreview(order.status);
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
    saveBtn.disabled = updateInProgress || (statusEl.value === currentStatus && !paymentChanged());
  }

  function canSendBack(order) {
    const status = normalizeStatus(order?.status || "PENDING");
    return Boolean(order?.id) && !order?.customerEditRequired && (status === "PENDING" || status === "APPROVED");
  }

  function syncSendBackButton() {
    if (!sendBackBtn) return;
    const allowed = canSendBack(currentOrder);
    sendBackBtn.disabled = sendBackInProgress || !allowed;
    sendBackBtn.title = allowed
      ? ""
      : (currentOrder?.customerEditRequired
        ? "This order is already waiting for customer edits."
        : "Only Pending or Approved records can be sent back for customer editing.");
  }

  function lockPageScroll() {
    if (document.body.classList.contains("modal-open")) return;
    modalScrollY = window.scrollY || document.documentElement.scrollTop || 0;
    document.documentElement.classList.add("modal-open");
    document.documentElement.classList.add("order-modal-scroll-locked");
    document.body.classList.add("modal-open");
    document.body.classList.add("order-modal-scroll-locked");
    document.body.style.position = "fixed";
    document.body.style.top = `-${modalScrollY}px`;
    document.body.style.left = "0";
    document.body.style.right = "0";
    document.body.style.width = "100%";
  }

  function unlockPageScroll() {
    if (!document.body.classList.contains("modal-open")) return;
    document.documentElement.classList.remove("modal-open");
    document.documentElement.classList.remove("order-modal-scroll-locked");
    document.body.classList.remove("modal-open");
    document.body.classList.remove("order-modal-scroll-locked");
    document.body.style.position = "";
    document.body.style.top = "";
    document.body.style.left = "";
    document.body.style.right = "";
    document.body.style.width = "";
    window.scrollTo(0, modalScrollY);
  }

  function renderOrderStatusState(status, allowedStatuses = []) {
    if (!statusEl) return;
    const normalizedStatus = normalizeStatus(status);
    const allowed = Array.isArray(allowedStatuses)
      ? allowedStatuses.map(normalizeStatus).filter((item) => statusLabels[item])
      : [];
    const selectableStatuses = [normalizedStatus, ...allowed]
      .filter((item, index, statuses) => statusLabels[item] && statuses.indexOf(item) === index);

    statusEl.innerHTML = selectableStatuses
      .map((value) => `<option value="${esc(value)}">${esc(statusLabels[value])}</option>`)
      .join("");
    if (!selectableStatuses.length) {
      statusEl.innerHTML = '<option value="PENDING">Pending</option>';
    }
    statusEl.value = normalizedStatus;
    statusEl.disabled = allowed.length === 0;
    previousStatusSelection = normalizedStatus;

    if (currentStatusEl) {
      currentStatusEl.className = `status-badge ${statusClass(normalizedStatus)}`;
      currentStatusEl.textContent = statusLabels[normalizedStatus] || normalizedStatus;
    }
    if (statusHelpEl) {
      statusHelpEl.textContent = allowed.length
        ? "Select the next valid status, then click Update."
        : "This order has no further status updates.";
    }

    const summaryStatus = summaryEl?.querySelector(".order-modal-summary-status");
    if (summaryStatus) {
      summaryStatus.className = `order-modal-summary-status ${statusClass(normalizedStatus)}`;
      const valueEl = summaryStatus.querySelector("strong");
      if (valueEl) valueEl.textContent = statusLabels[normalizedStatus] || normalizedStatus;
    }
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
      detailRow("Email Address", order.customerEmail),
      detailRow("Phone Number", order.customerPhone),
      detailRow("Category", order.category),
      detailRow("Service", order.serviceLabel),
      ...(Array.isArray(order.details) ? order.details.map((row) => detailRow(row.label, row.value)) : []),
      fileRows(order.files),
      detailRow("Payment Method", order.paymentMethod),
      detailRow("Payment Reference", order.paymentReference || "-"),
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
    renderOrderStatusState(order.status, order.allowedStatuses);
    populatePayment(order);
    updateSaveButton();
    syncSendBackButton();

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
    lockPageScroll();
    window.servitechAdminModalStack?.open({
      overlay,
      dialog: modal,
      focus: statusEl,
      onEscape: closeModal,
    });
  }

  function closeModal() {
    if (!overlay || !modal) return;
    closeSendBackModal();
    window.servitechAdminModalStack?.close(overlay);
    overlay.classList.remove("active");
    modal.classList.remove("active");
    modal.setAttribute("aria-hidden", "true");
    unlockPageScroll();
    clearError();
  }

  function showSendBackError(message = "") {
    if (sendBackErrorEl) sendBackErrorEl.textContent = message;
  }

  function openSendBackModal() {
    if (!sendBackOverlay || !sendBackModal || !currentOrder?.id || !canSendBack(currentOrder)) {
      showError(currentOrder?.customerEditRequired
        ? "This order is already waiting for customer edits."
        : "Only Pending or Approved records can be sent back for customer editing.");
      syncSendBackButton();
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
    if (!currentOrder?.id || sendBackInProgress) return;
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
    fd.append("id", currentOrder.id);
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
      out = { ok: false, error: "Unable to send this order back to the customer." };
    }

    if (!out.ok) {
      showSendBackError(out.error || "Unable to send this order back to the customer.");
      sendBackInProgress = false;
      if (sendBackSubmitBtn) sendBackSubmitBtn.disabled = false;
      syncSendBackButton();
      return;
    }

    closeSendBackModal();
    showUpdateFeedback("Sent back to customer for editing.", "success");
    window.servitechAdminToast?.success("Sent back to customer for editing.");
    currentOrder.customerEditRequired = true;
    sendBackInProgress = false;
    syncSendBackButton();
    syncCurrentRow();
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

  function applyPaymentResult(out) {
    currentOrder.price = out.price;
    currentOrder.paidAmount = out.paid_amount;
    currentOrder.paidPending = out.paid_pending;
    priceEl.value = amount(out.price).toFixed(2);
    paidAmountEl.value = amount(out.paid_amount).toFixed(2);
    initialPayment = { price: priceEl.value, paidAmount: paidAmountEl.value };
    syncPaymentPreview();
  }

  function applyStatusResult(out) {
    const newStatus = normalizeStatus(out.status || currentOrder.status);
    currentOrder.status = newStatus;
    currentOrder.allowedStatuses = Array.isArray(out.allowed_transitions) ? out.allowed_transitions : [];
    renderOrderStatusState(newStatus, currentOrder.allowedStatuses);
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

  function syncCurrentRow() {
    if (!currentOrderRow || !currentOrder) return;

    const currentStatus = normalizeStatus(currentOrder.status);
    currentOrderRow.dataset.status = currentStatus;

    const badge = currentOrderRow.querySelector(".status-cell .status-badge");
    if (badge) {
      badge.className = `status-badge ${statusClass(currentStatus)}`;
      badge.textContent = statusLabels[currentStatus] || currentStatus;
    }

    const cells = currentOrderRow.querySelectorAll("td");
    if (cells[3]) {
      const summary = paymentSummary(currentOrder);
      if (summary) cells[3].textContent = summary;
    }

    const viewButton = currentOrderRow.querySelector(".view-order-btn");
    if (viewButton) {
      viewButton.dataset.order = JSON.stringify(currentOrder);
    }

    window.realtimeQueueAdmin?.markRenderedSynced?.();
  }

  async function savePayment() {
    if (!currentOrder?.id || !priceEl || !paidAmountEl || !paymentSection) {
      return { attempted: false, ok: true };
    }
    clearError();
    if (!syncPaymentPreview()) {
      return { attempted: true, ok: false, error: "Paid amount cannot exceed the price." };
    }
    if (!paymentChanged()) return { attempted: false, ok: true };

    const fd = new FormData();
    fd.append("id", currentOrder.id);
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

  async function saveStatus(action, notes = "") {
    if (!currentOrder?.id || statusEl.value === normalizeStatus(currentOrder.status)) {
      return { attempted: false, ok: true };
    }

    let out;
    try {
      out = await postAction(currentOrder.id, action, notes);
    } catch (error) {
      return { attempted: true, ok: false, error: "Unable to update the order status." };
    }
    if (!out.ok) {
      return { attempted: true, ok: false, error: out.error || "Failed to update status." };
    }

    applyStatusResult(out);
    return { attempted: true, ok: true, message: actionMessages[action] || "Order status updated successfully.", data: out };
  }

  function showUpdateResultToasts(paymentResult, statusResult) {
    const paymentTried = Boolean(paymentResult?.attempted);
    const statusTried = Boolean(statusResult?.attempted);
    const paymentOk = !paymentTried || paymentResult.ok;
    const statusOk = !statusTried || statusResult.ok;

    if (!paymentTried && !statusTried) {
      showUpdateFeedback("No changes to update.", "info");
      return false;
    }

    if (paymentTried && statusTried && paymentOk && statusOk) {
      showUpdateFeedback("Status and payment updated successfully.", "success");
      window.servitechAdminToast?.success("Status and payment updated successfully.");
      syncCurrentRow();
      return false;
    }

    if (paymentTried && statusTried && !paymentOk && !statusOk) {
      window.servitechAdminToast?.error("Unable to update status and payment.");
      return false;
    }

    if ((statusTried || paymentTried) && statusOk && paymentOk) {
      const message = statusTried ? (statusResult.message || "Order status updated successfully.") : "Payment details saved successfully.";
      showUpdateFeedback(message, "success");
      window.servitechAdminToast?.success(message);
      syncCurrentRow();
      return false;
    }

    if (statusTried && !statusOk) window.servitechAdminToast?.error(statusResult.error || "Unable to update the order status.");
    if (statusTried && statusOk) window.servitechAdminToast?.success(statusResult.message || "Order status updated successfully.");
    if (paymentTried && !paymentOk) window.servitechAdminToast?.error(paymentResult.error || "Unable to update payment details.");
    if (paymentTried && paymentOk) window.servitechAdminToast?.success(paymentResult.message || "Payment details saved successfully.");

    if ((statusTried && statusOk) || (paymentTried && paymentOk)) syncCurrentRow();
    return false;
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

    let out;
    try {
      out = await postAction(currentOrder.id, "cancel", notes);
    } catch (error) {
      statusEl.value = previousStatusSelection;
      updateSaveButton();
      showError("Unable to cancel the order.");
      cancellationInProgress = false;
      return;
    }
    if (!out.ok) {
      statusEl.value = previousStatusSelection;
      updateSaveButton();
      showError(out.error || "Failed to cancel order.");
      cancellationInProgress = false;
      return;
    }

    showUpdateFeedback(actionMessages.cancel, "success");
    window.servitechAdminToast?.success(actionMessages.cancel);
    applyStatusResult(out);
    syncCurrentRow();
    cancellationInProgress = false;
    updateSaveButton();
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
    currentOrderRow = button.closest(".order-data-row");
    openModal(order);
  }

  document.querySelectorAll(".view-order-btn").forEach((button) => {
    button.disabled = false;
    button.addEventListener("click", function (event) {
      event.preventDefault();
      openFromButton(this);
    });
  });

  function openNotificationOrderDetails() {
    const params = new URLSearchParams(window.location.search);
    if (params.get("open") !== "notification") return;

    const queueId = params.get("queue_id") || "";
    if (!queueId) return;

    const button = Array.from(document.querySelectorAll(".view-order-btn")).find((candidate) => {
      return String(candidate.dataset.recordId || candidate.dataset.queueId || candidate.dataset.id || "") === String(queueId);
    });

    if (button) {
      window.setTimeout(() => openFromButton(button), 80);
    }
  }

  openNotificationOrderDetails();

  document.getElementById("orderModalClose")?.addEventListener("click", closeModal);
  document.getElementById("omCancel")?.addEventListener("click", closeModal);
  modal?.addEventListener("submit", (event) => {
    event.preventDefault();
    event.stopPropagation();
  });
  document.getElementById("orderSendBackClose")?.addEventListener("click", closeSendBackModal);
  document.getElementById("orderSendBackCancel")?.addEventListener("click", closeSendBackModal);
  sendBackOverlay?.addEventListener("click", closeSendBackModal);
  sendBackSubmitBtn?.addEventListener("click", submitSendBack);
  sendBackMessageEl?.addEventListener("input", () => showSendBackError(""));
  document.addEventListener("click", (event) => {
    if (event.target?.closest?.("#omSendBack")) {
      event.preventDefault();
      event.stopPropagation();
      openSendBackModal();
    }
  });
  overlay?.addEventListener("click", closeModal);
  statusEl?.addEventListener("change", () => {
    if (statusEl.value === "CANCELLED") {
      statusEl.value = previousStatusSelection;
      updateSaveButton();
      cancelCurrentOrder();
      return;
    }
    previousStatusSelection = statusEl.value;
    clearError();
    syncPaymentPreview();
    updateSaveButton();
  });
  priceEl?.addEventListener("input", () => {
    clearError();
    syncPaymentPreview();
    updateSaveButton();
  });
  paidAmountEl?.addEventListener("input", () => {
    clearError();
    syncPaymentPreview();
    updateSaveButton();
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && modal?.classList.contains("active")) {
      closeModal();
    }
  });

  document.getElementById("omSave")?.addEventListener("click", async (event) => {
    event.preventDefault();
    event.stopPropagation();
    if (!currentOrder?.id || updateInProgress) return;

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

    updateInProgress = true;
    updateSaveButton();
    clearError();
    window.realtimeQueueAdmin?.stopPolling?.();

    try {
      const paymentResult = await savePayment();
      const statusResult = await saveStatus(action, notes);
      showUpdateResultToasts(paymentResult, statusResult);
      window.realtimeQueueAdmin?.markRenderedSynced?.();
    } finally {
      window.realtimeQueueAdmin?.startPolling?.();
      updateInProgress = false;
      updateSaveButton();
    }
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

    function isTerminalStatus(status) {
      const normalized = normalizeStatus(status);
      return normalized === "DONE" || normalized === "CANCELLED" || normalized === "CANCEL";
    }

    function sortFifoWithTerminalLast() {
      rows
        .slice()
        .sort((left, right) => {
          const leftTime = Date.parse(left.dataset.submittedAt || "") || 0;
          const rightTime = Date.parse(right.dataset.submittedAt || "") || 0;
          const leftTerminal = isTerminalStatus(left.dataset.status) ? 1 : 0;
          const rightTerminal = isTerminalStatus(right.dataset.status) ? 1 : 0;
          if (leftTerminal !== rightTerminal) return leftTerminal - rightTerminal;
          if (leftTime !== rightTime) return leftTime - rightTime;
          return String(left.dataset.orderId || "").localeCompare(String(right.dataset.orderId || ""));
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

      sortFifoWithTerminalLast();

      rows.forEach((row) => {
        const orderId = String(row.dataset.orderId || "").toLowerCase();
        const customer = String(row.dataset.customer || "").toLowerCase();
        const customerEmail = String(row.dataset.customerEmail || "").toLowerCase();
        const customerPhone = String(row.dataset.customerPhone || "").toLowerCase();
        const rowStatus = String(row.dataset.status || "").toUpperCase();
        const rowPaymentMethod = String(row.dataset.paymentMethod || "").toLowerCase();
        const rowSubmittedDate = String(row.dataset.submittedDate || "");

        const matchesSearch = !query
          || orderId.includes(query)
          || customer.includes(query)
          || customerEmail.includes(query)
          || customerPhone.includes(query);
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
