/**
 * Keep admin queue tables synchronized without replacing the page or its UI state.
 * Order Management keeps its existing snapshot/reload behavior.
 */
(function () {
  "use strict";

  const POLL_INTERVAL = 5000;
  let pollTimer = null;
  let requestInFlight = false;
  let lastRecordSignature = "";
  let lastSnapshotSignature = "";
  let reloadPending = false;

  function isQueueScope(scope) {
    return String(scope || "").startsWith("queue_");
  }

  function hasActiveAdminModal() {
    return Boolean(document.querySelector(
      ".queue-details-modal.active, .queue-sendback-modal.active, " +
      "#queueMessageModal[aria-hidden=\"false\"], .order-modal.active, " +
      ".order-sendback-modal.active, .order-confirm-overlay.is-open"
    ));
  }

  function reloadOrDefer() {
    if (hasActiveAdminModal()) {
      reloadPending = true;
      return;
    }
    window.location.reload();
  }

  function adminUrl(path) {
    const adminLink = document.querySelector('a[href*="/pages/admin/"]');
    if (!adminLink) return path;

    const href = adminLink.getAttribute("href") || "";
    return href.split("/pages/admin/")[0] + path;
  }

  function renderedRecords() {
    const queueRows = Array.from(document.querySelectorAll(".queue-data-row")).map(function (row) {
      return {
        id: Number(row.dataset.queueRecordId || 0),
        status: String(row.dataset.queueStatus || "PENDING").trim().toUpperCase(),
        customer: String(row.dataset.queueCustomer || ""),
        customer_email: String(row.dataset.queueCustomerEmail || ""),
        customer_phone: String(row.dataset.queueCustomerPhone || "")
      };
    });
    if (queueRows.length) return queueRows;

    return Array.from(document.querySelectorAll(".order-data-row")).map(function (row) {
      const button = row.querySelector("[data-id]");
      return {
        id: Number(button ? button.dataset.id : 0),
        status: String(row.dataset.status || "PENDING").trim().toUpperCase(),
        customer: String(row.dataset.customer || ""),
        customer_email: String(row.dataset.customerEmail || ""),
        customer_phone: String(row.dataset.customerPhone || "")
      };
    });
  }

  function normalizeStatus(status) {
    const value = String(status || "PENDING").trim().toUpperCase().replace(/[\s_]+/g, " ");
    if (value === "FOR PICK UP" || value === "FOR PICKUP") return "FOR PICK-UP";
    if (value === "CANCELED") return "CANCELLED";
    return value || "PENDING";
  }

  function recordSignature(records) {
    return JSON.stringify((records || []).slice().sort(function (left, right) {
      return Number(left.id || 0) - Number(right.id || 0);
    }).map(function (record) {
      return {
        id: Number(record.id || 0),
        status: normalizeStatus(record.status),
        customer: String(record.customer || "").trim().toLowerCase(),
        customer_email: String(record.customer_email || "").trim().toLowerCase(),
        customer_phone: String(record.customer_phone || "").trim().toLowerCase()
      };
    }));
  }

  function rowId(row) {
    return String(row?.dataset.queueRecordId || "");
  }

  function checkboxKey(checkbox, index) {
    return checkbox.dataset.selectionId
      || checkbox.id
      || [checkbox.name || "checkbox", checkbox.value || index].join(":");
  }

  function captureRowState(row) {
    const checked = new Set();
    Array.from(row.querySelectorAll('input[type="checkbox"]')).forEach(function (checkbox, index) {
      if (checkbox.checked) checked.add(checkboxKey(checkbox, index));
    });
    return {
      checked,
      ariaSelected: row.getAttribute("aria-selected"),
      selected: row.classList.contains("selected"),
      isSelected: row.classList.contains("is-selected")
    };
  }

  function restoreRowState(row, state) {
    if (!state) return;
    Array.from(row.querySelectorAll('input[type="checkbox"]')).forEach(function (checkbox, index) {
      checkbox.checked = state.checked.has(checkboxKey(checkbox, index));
    });
    if (state.ariaSelected !== null) row.setAttribute("aria-selected", state.ariaSelected);
    row.classList.toggle("selected", state.selected);
    row.classList.toggle("is-selected", state.isSelected);
  }

  function comparableRowHtml(row) {
    const clone = row.cloneNode(true);
    clone.hidden = false;
    clone.classList.remove("selected", "is-selected");
    clone.removeAttribute("aria-selected");
    Array.from(clone.querySelectorAll('input[type="checkbox"]')).forEach(function (checkbox) {
      checkbox.checked = false;
      checkbox.removeAttribute("checked");
    });
    return clone.outerHTML;
  }

  function protectedQueueIds() {
    const ids = new Set();
    const activeRow = document.activeElement?.closest?.(".queue-data-row");
    if (activeRow && rowId(activeRow)) ids.add(rowId(activeRow));

    [
      document.getElementById("queueDetailsOverlay"),
      document.getElementById("queueMessageModal")
    ].forEach(function (modal) {
      const active = modal?.classList.contains("active") || modal?.getAttribute("aria-hidden") === "false";
      if (active && modal.dataset.queueId) ids.add(String(modal.dataset.queueId));
    });
    return ids;
  }

  function parseQueueRows(html) {
    const stagingBody = document.createElement("tbody");
    stagingBody.innerHTML = String(html || "").trim();
    return Array.from(stagingBody.querySelectorAll(".queue-data-row"));
  }

  function syncQueueTable(html) {
    const table = document.querySelector(".queue-table");
    const tbody = table?.querySelector("tbody");
    if (!table || !tbody || typeof html !== "string") return false;

    const incomingRows = parseQueueRows(html);
    const incomingById = new Map();
    incomingRows.forEach(function (row) {
      const id = rowId(row);
      if (id) incomingById.set(id, row);
    });

    const existingRows = Array.from(tbody.querySelectorAll(".queue-data-row"));
    const existingById = new Map(existingRows.map(function (row) { return [rowId(row), row]; }));
    const protectedIds = protectedQueueIds();
    let changed = false;

    tbody.querySelectorAll(".queue-server-empty-row").forEach(function (row) { row.remove(); });

    existingRows.forEach(function (row) {
      const id = rowId(row);
      if (!incomingById.has(id) && !protectedIds.has(id)) {
        row.remove();
        existingById.delete(id);
        changed = true;
      }
    });

    const anchor = tbody.querySelector(".queue-no-results-row");
    const orderedRows = [];
    Array.from(incomingById.values()).forEach(function (incomingRow) {
      const id = rowId(incomingRow);
      const existingRow = existingById.get(id);
      let liveRow = existingRow;

      if (!existingRow) {
        liveRow = incomingRow;
        changed = true;
      } else if (!protectedIds.has(id) && comparableRowHtml(existingRow) !== comparableRowHtml(incomingRow)) {
        const state = captureRowState(existingRow);
        restoreRowState(incomingRow, state);
        existingRow.replaceWith(incomingRow);
        liveRow = incomingRow;
        changed = true;
      }
      if (liveRow) orderedRows.push(liveRow);
    });

    existingRows.forEach(function (row) {
      const id = rowId(row);
      if (protectedIds.has(id) && !incomingById.has(id) && row.isConnected) orderedRows.push(row);
    });

    const currentOrder = Array.from(tbody.querySelectorAll(".queue-data-row"));
    const orderChanged = currentOrder.length !== orderedRows.length
      || currentOrder.some(function (row, index) { return row !== orderedRows[index]; });
    if (orderChanged) {
      orderedRows.forEach(function (row) { tbody.insertBefore(row, anchor); });
      changed = true;
    }

    if (changed) {
      table.dispatchEvent(new CustomEvent("servitech:queue-table-updated", {
        bubbles: false,
        detail: { changed: true, count: incomingById.size }
      }));
    }
    return changed;
  }

  function markRenderedSynced() {
    lastRecordSignature = recordSignature(renderedRecords());
    lastSnapshotSignature = "";
    reloadPending = false;
  }

  async function refreshSnapshot() {
    const scope = document.body.dataset.adminRealtimeScope || "";
    if (!scope || requestInFlight || document.hidden) return;

    requestInFlight = true;
    try {
      const endpoint = adminUrl("/pages/admin/_includes/admin_realtime_snapshot.php");
      const url = endpoint + "?scope=" + encodeURIComponent(scope);
      const response = await fetch(url, {
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          "Accept": "application/json",
          "X-Requested-With": "XMLHttpRequest"
        }
      });
      if (!response.ok) return;

      const data = await response.json();
      if (!data.ok || !Array.isArray(data.records)) return;

      const snapshotRecordSignature = recordSignature(data.records);
      if (isQueueScope(scope) && typeof data.table_html === "string") {
        syncQueueTable(data.table_html);
        lastRecordSignature = snapshotRecordSignature;
        lastSnapshotSignature = String(data.signature || snapshotRecordSignature);
        return;
      }

      const serverSignature = String(data.signature || snapshotRecordSignature);
      const changed = lastSnapshotSignature
        ? lastSnapshotSignature !== serverSignature
        : lastRecordSignature !== snapshotRecordSignature;
      lastRecordSignature = snapshotRecordSignature;
      lastSnapshotSignature = serverSignature;
      if (changed) reloadOrDefer();
    } catch (error) {
      // A transient poll failure is retried on the next interval without disturbing the UI.
    } finally {
      requestInFlight = false;
    }
  }

  function startPolling() {
    if (pollTimer || !document.body.dataset.adminRealtimeScope) return;
    markRenderedSynced();
    refreshSnapshot();
    pollTimer = window.setInterval(refreshSnapshot, POLL_INTERVAL);
  }

  function stopPolling() {
    if (!pollTimer) return;
    window.clearInterval(pollTimer);
    pollTimer = null;
  }

  document.addEventListener("visibilitychange", function () {
    if (!document.hidden) refreshSnapshot();
  });
  document.addEventListener("servitech:admin-modal-closed", function () {
    window.setTimeout(function () {
      const scope = document.body.dataset.adminRealtimeScope || "";
      if (isQueueScope(scope)) {
        refreshSnapshot();
        return;
      }
      if (!reloadPending || hasActiveAdminModal()) return;
      reloadPending = false;
      window.location.reload();
    }, 50);
  });
  window.addEventListener("pagehide", stopPolling);
  window.addEventListener("pageshow", function () {
    startPolling();
    refreshSnapshot();
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", startPolling);
  } else {
    startPolling();
  }

  window.realtimeQueueAdmin = {
    startPolling,
    stopPolling,
    refreshSnapshot,
    markRenderedSynced,
    syncQueueTable
  };
})();
