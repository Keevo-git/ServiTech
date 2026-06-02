/**
 * Refresh server-rendered admin queue and order tables when their data changes.
 */
(function () {
  "use strict";

  const POLL_INTERVAL = 5000;
  let pollTimer = null;
  let requestInFlight = false;
  let lastSignature = "";

  function adminUrl(path) {
    const adminLink = document.querySelector('a[href*="/pages/admin/"]');
    if (!adminLink) return path;

    const href = adminLink.getAttribute("href") || "";
    return href.split("/pages/admin/")[0] + path;
  }

  function syncNotificationBadge(count) {
    const link = document.querySelector(".admin-notification-btn");
    if (!link) return;

    const safeCount = Math.max(0, Number(count) || 0);
    let badge = link.querySelector(".admin-notification-badge");

    link.setAttribute("aria-label", "Queue notifications: " + safeCount);
    if (safeCount <= 0) {
      if (badge) badge.remove();
      return;
    }

    if (!badge) {
      badge = document.createElement("span");
      badge.className = "admin-notification-badge";
      link.appendChild(badge);
    }
    badge.textContent = String(safeCount);
  }

  function renderedRecords() {
    const queueRows = Array.from(document.querySelectorAll(".queue-data-row")).map(function (row) {
      return {
        id: Number(row.dataset.queueRecordId || 0),
        status: String(row.dataset.queueStatus || "PENDING").trim().toUpperCase()
      };
    });
    if (queueRows.length) return queueRows;

    return Array.from(document.querySelectorAll(".order-data-row")).map(function (row) {
      const button = row.querySelector("[data-id]");
      return {
        id: Number(button ? button.dataset.id : 0),
        status: String(row.dataset.status || "PENDING").trim().toUpperCase()
      };
    });
  }

  function recordSignature(records) {
    return JSON.stringify((records || []).slice().sort(function (left, right) {
      return Number(left.id || 0) - Number(right.id || 0);
    }));
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
        headers: {
          "Accept": "application/json",
          "X-Requested-With": "XMLHttpRequest"
        }
      });
      if (!response.ok) return;

      const data = await response.json();
      if (!data.ok || !data.signature) return;

      syncNotificationBadge(data.notification_count);
      if (!lastSignature && recordSignature(renderedRecords()) !== recordSignature(data.records)) {
        window.location.reload();
        return;
      }
      if (lastSignature && lastSignature !== data.signature) {
        window.location.reload();
        return;
      }
      lastSignature = data.signature;
    } catch (error) {
      console.error("Admin realtime refresh failed:", error);
    } finally {
      requestInFlight = false;
    }
  }

  function startPolling() {
    if (pollTimer || !document.body.dataset.adminRealtimeScope) return;

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
  window.addEventListener("beforeunload", stopPolling);

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", startPolling);
  } else {
    startPolling();
  }

  window.realtimeQueueAdmin = {
    startPolling,
    stopPolling,
    refreshSnapshot
  };
})();
