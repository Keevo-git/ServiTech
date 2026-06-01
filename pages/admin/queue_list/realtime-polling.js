/**
 * Real-time Queue/Order Management Auto-Update
 * Polls every 5 seconds for status, payment, and notification updates
 */

(function() {
  "use strict";

  const POLL_INTERVAL = 5000; // 5 seconds
  let pollTimeout = null;
  let isPolling = false;
  let lastUpdateTime = null;

  /**
   * Fetch queue/order data from server
   */
  async function fetchTableData() {
    try {
      const url = new URL(window.location.href);
      const view = url.searchParams.get("view") || "online";
      const endpoint = window.location.pathname.includes("order_management") 
        ? "/api/admin_orders_data.php"
        : "/api/admin_queues_data.php";

      const res = await fetch(servitech_admin_url(endpoint) + "?view=" + encodeURIComponent(view), {
        method: "GET",
        headers: {
          "Accept": "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        credentials: "same-origin"
      });

      if (!res.ok) return null;

      const data = await res.json();
      if (!data.ok || !data.rows) return null;

      return data;
    } catch (err) {
      console.error("fetchTableData error:", err);
      return null;
    }
  }

  /**
   * Update a table row with new data
   */
  function updateTableRow(rowId, newData) {
    const row = document.querySelector(`tr[data-queue-id="${rowId}"]`);
    if (!row) return;

    // Update status
    if (newData.status) {
      const statusCell = row.querySelector(".status-cell");
      if (statusCell) {
        statusCell.innerHTML = createStatusBadge(newData.status);
      }
    }

    // Update payment info
    if (newData.payment_method !== undefined) {
      const paymentCell = row.querySelector(".payment-cell");
      if (paymentCell) {
        paymentCell.innerHTML = createPaymentInfo(newData);
      }
    }

    // Update actions
    if (newData.id) {
      const actionsCell = row.querySelector(".actions-cell");
      if (actionsCell) {
        actionsCell.innerHTML = createActionButtons(newData);
        attachEventListeners(actionsCell);
      }
    }
  }

  /**
   * Create status badge HTML
   */
  function createStatusBadge(status) {
    const statusKey = status.toLowerCase().replace(/[\s_]+/g, "-");
    const statusMap = {
      "pending": "PENDING",
      "approved": "APPROVED",
      "ongoing": "ONGOING",
      "for-pick-up": "FOR PICK-UP",
      "done": "DONE",
      "cancelled": "CANCELLED"
    };

    const displayStatus = statusMap[statusKey] || status;
    const classMap = {
      "pending": "status-pending",
      "approved": "status-approved",
      "ongoing": "status-ongoing",
      "for-pick-up": "status-pickup",
      "done": "status-done",
      "cancelled": "status-cancelled"
    };

    const className = classMap[statusKey] || "status-pending";
    return `<span class="status-badge ${className}">${escapeHtml(displayStatus)}</span>`;
  }

  /**
   * Create payment info HTML
   */
  function createPaymentInfo(data) {
    let html = '<span class="submitted-stack"><strong>';

    if (data.payment_method === "cash") {
      html += "Cash";
      if (data.amount) {
        html += " ₱" + parseFloat(data.amount).toFixed(2);
      }
    } else if (data.payment_method === "gcash") {
      html += "GCash";
      if (data.amount) {
        html += " ₱" + parseFloat(data.amount).toFixed(2);
      }
    } else {
      html += "-";
    }

    html += "</strong>";

    if (data.reference_number) {
      html += '<small>Ref: ' + escapeHtml(data.reference_number) + '</small>';
    }

    const statusLabel = getPaymentStatusLabel(data.payment_method, data.status);
    html += '<small>Status: ' + statusLabel + '</small>';

    html += '</span>';
    return html;
  }

  /**
   * Get human-readable payment status label
   */
  function getPaymentStatusLabel(method, queueStatus) {
    const normalizedMethod = String(method || "").trim().toLowerCase();
    const normalizedStatus = String(queueStatus || "PENDING").trim().toUpperCase();
    if (["CANCELLED", "CANCELED"].includes(normalizedStatus)) return "Cancelled";

    if (normalizedMethod === "gcash") {
      return normalizedStatus === "PENDING" ? "Payment Submitted" : "Accepted";
    }

    if (normalizedMethod === "cash") {
      return ["ONGOING", "FOR PICK-UP", "DONE"].includes(normalizedStatus) ? "Paid" : "Pay at Store";
    }

    return "-";
  }

  /**
   * Create action buttons HTML
   */
  function createActionButtons(data) {
    let html = '<div class="actions-group">';

    // Update Status button
    html += `<button class="btn-update-status" data-id="${data.id}" data-code="${escapeHtml(data.queue_code || "")}">Update Status</button>`;

    // Message button
    html += `<button class="btn-message" data-id="${data.id}" data-queue-code="${escapeHtml(data.queue_code || "")}" data-customer="${escapeHtml(data.fullname || "")}">Message</button>`;

    html += '</div>';
    return html;
  }

  /**
   * Escape HTML to prevent XSS
   */
  function escapeHtml(text) {
    const map = {
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;"
    };
    return String(text || "").replace(/[&<>"']/g, m => map[m]);
  }

  /**
   * Get admin URL helper
   */
  function servitech_admin_url(path) {
    const base = document.querySelector('a[href*="/pages/admin/"]');
    if (base) {
      const href = base.getAttribute("href");
      const adminBase = href.split("/pages/admin/")[0];
      return adminBase + path;
    }
    return path;
  }

  /**
   * Update notification badge count
   */
  async function updateNotificationCount() {
    try {
      const res = await fetch(servitech_admin_url("/api/admin_notification_count.php"), {
        method: "GET",
        headers: { "Accept": "application/json" },
        credentials: "same-origin"
      });

      if (!res.ok) return;

      const data = await res.json();
      if (data.count !== undefined) {
        const badge = document.querySelector(".admin-notification-badge");
        if (badge) {
          badge.textContent = data.count;
          if (data.count > 0) {
            badge.parentElement.style.display = "inline-block";
          }
        }
      }
    } catch (err) {
      console.error("updateNotificationCount error:", err);
    }
  }

  /**
   * Full update cycle
   */
  async function updateAllData() {
    const data = await fetchTableData();
    if (!data || !data.rows) {
      console.log("No data update available");
      return;
    }

    // Update each row
    data.rows.forEach(row => {
      updateTableRow(row.id, row);
    });

    // Update notification count
    await updateNotificationCount();

    lastUpdateTime = Date.now();
  }

  /**
   * Start polling
   */
  function startPolling() {
    if (isPolling) return;

    isPolling = true;
    console.log("Real-time polling started");

    // First update immediately
    updateAllData();

    // Then poll every 5 seconds
    pollTimeout = setInterval(() => {
      updateAllData();
    }, POLL_INTERVAL);
  }

  /**
   * Stop polling
   */
  function stopPolling() {
    if (pollTimeout) {
      clearInterval(pollTimeout);
      pollTimeout = null;
    }
    isPolling = false;
    console.log("Real-time polling stopped");
  }

  /**
   * Attach event listeners to action buttons
   */
  function attachEventListeners(container) {
    container.querySelectorAll(".btn-update-status").forEach(btn => {
      btn.addEventListener("click", handleUpdateStatusClick);
    });

    container.querySelectorAll(".btn-message").forEach(btn => {
      btn.addEventListener("click", handleMessageClick);
    });
  }

  /**
   * Handle Update Status button click
   */
  function handleUpdateStatusClick(evt) {
    const id = evt.currentTarget.dataset.id;
    const code = evt.currentTarget.dataset.code;

    // Trigger existing modal/handler
    if (window.openStatusModal) {
      window.openStatusModal(id, code);
    }
  }

  /**
   * Handle Message button click
   */
  function handleMessageClick(evt) {
    // Trigger existing message modal handler
    if (window.openMessageModal) {
      const id = evt.currentTarget.dataset.id;
      const code = evt.currentTarget.dataset.queueCode;
      const customer = evt.currentTarget.dataset.customer;
      window.openMessageModal(id, code, customer);
    }
  }

  /**
   * Initialize when page loads
   */
  function init() {
    // Only run on admin pages
    if (!document.body.classList.contains("admin-dashboard")) {
      return;
    }

    // Only run on queue/order management pages
    if (!window.location.pathname.includes("queue_list") && !window.location.pathname.includes("order_management")) {
      return;
    }

    console.log("Real-time polling initializing...");

    // Mark table rows with data-queue-id for easy lookup
    document.querySelectorAll("table tbody tr").forEach(row => {
      const firstCell = row.querySelector("td");
      if (firstCell && !row.dataset.queueId) {
        const queueCode = firstCell.textContent.trim();
        row.dataset.queueId = queueCode; // Using queue code as ID
      }
    });

    // Attach event listeners to existing buttons
    document.querySelectorAll(".actions-group").forEach(group => {
      attachEventListeners(group);
    });

    // Start polling
    startPolling();

    // Stop polling on page unload
    window.addEventListener("beforeunload", stopPolling);
  }

  // Initialize on DOM ready
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }

  // Export for external use
  window.realtimeQueueAdmin = {
    startPolling,
    stopPolling,
    updateAllData,
    updateTableRow
  };
})();
