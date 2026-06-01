document.addEventListener("DOMContentLoaded", function () {
  const overlay = document.getElementById("queueDetailsOverlay");
  const modal = document.getElementById("queueDetailsModal");
  const titleEl = document.getElementById("queueDetailsTitle");
  const summaryEl = document.getElementById("queueDetailsSummary");
  const detailsEl = document.getElementById("queueDetailsList");
  const actionsEl = document.getElementById("queueDetailsActions");

  function esc(value) {
    return String(value ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function statusClass(status) {
    const key = String(status || "PENDING").trim().toLowerCase().replace(/[\s_]+/g, "-");
    if (key === "approved") return "status-approved";
    if (key === "ongoing") return "status-ongoing";
    if (key === "for-pick-up" || key === "for-pickup") return "status-pickup";
    if (key === "done") return "status-done";
    if (key === "cancelled" || key === "canceled") return "status-cancelled";
    return "status-pending";
  }

  function detailRow(label, value) {
    const cleanValue = String(value ?? "").trim();
    if (!cleanValue) return "";
    return `<div class="queue-detail-row"><span>${esc(label)}</span><strong>${esc(cleanValue)}</strong></div>`;
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
    window.servitechAdminModalStack?.close(overlay);
    overlay.classList.remove("active");
    modal.classList.remove("active");
    modal.setAttribute("aria-hidden", "true");
  }

  function actionTone(button) {
    if (button.classList.contains("btn-delete")) return "danger";
    if (button.classList.contains("btn-message")) return "message";
    if (button.classList.contains("btn-cancel")) return "muted";
    if (button.classList.contains("btn-pickup")) return "secondary";
    if (button.classList.contains("btn-done")) return "success";
    return "primary";
  }

  function renderActions(row) {
    if (!actionsEl) return;
    actionsEl.innerHTML = "";
    const sourceButtons = row.querySelectorAll(".queue-inline-actions button");

    sourceButtons.forEach((sourceButton) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "queue-details-action queue-details-action--" + actionTone(sourceButton);
      button.textContent = sourceButton.textContent.trim();
      button.addEventListener("click", () => {
        if (sourceButton.classList.contains("btn-message")) {
          closeDetails();
        }
        sourceButton.click();
      });
      actionsEl.appendChild(button);
    });
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
      detailRow("Payment Status", queue.paymentStatus),
      detailRow("Submitted Date", queue.submitted),
    ].join("");
    renderActions(row);

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
  document.getElementById("queueDetailsClose")?.addEventListener("click", closeDetails);
  overlay?.addEventListener("click", closeDetails);
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
        .sort((left, right) => (Date.parse(right.dataset.queueSubmittedAt || "") || 0) - (Date.parse(left.dataset.queueSubmittedAt || "") || 0))
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
