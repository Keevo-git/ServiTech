document.addEventListener("DOMContentLoaded", () => {
  const nowEl = document.getElementById("adminNow");
  if (nowEl) {
    const formatter = new Intl.DateTimeFormat("en-US", {
      timeZone: "Asia/Manila",
      year: "numeric",
      month: "short",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
      hour12: true,
    });
    const updateClock = () => { nowEl.textContent = formatter.format(new Date()); };
    updateClock();
    setInterval(updateClock, 1000);
  }

  if (document.body?.dataset.analyticsAvailable !== "true") return;

  document.querySelectorAll(".value[data-count]").forEach((element, index) => {
    const target = Number(element.dataset.count || 0);
    if (!Number.isFinite(target) || target <= 0) {
      element.textContent = "0";
      return;
    }
    const duration = 600 + index * 80;
    const start = performance.now();
    const step = (now) => {
      const progress = Math.min(1, (now - start) / duration);
      element.textContent = String(Math.round(target * (1 - Math.pow(1 - progress, 3))));
      if (progress < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  });
});

function escapeHtml(value) {
  return String(value ?? "").replace(/[&<>"']/g, (character) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#039;",
  }[character]));
}

function safeCount(value) {
  const count = Number(value);
  return Number.isFinite(count) && count >= 0 ? Math.trunc(count) : 0;
}

function setCount(id, value) {
  const element = document.getElementById(id);
  if (element) element.textContent = String(safeCount(value));
}

function renderTopServices(items) {
  const list = document.getElementById("topServicesList");
  if (!list) return;
  if (!Array.isArray(items) || items.length === 0) {
    list.innerHTML = '<p class="analytics-empty">No visible requests in this period.</p>';
    return;
  }

  const max = Math.max(1, ...items.map((item) => safeCount(item.total)));
  list.innerHTML = items.map((item, index) => {
    const total = safeCount(item.total);
    const width = Math.max(8, Math.round((total / max) * 100));
    return `
      <div class="analytics-row">
        <span class="analytics-rank">${index + 1}</span>
        <span class="analytics-row__body">
          <span class="analytics-label">${escapeHtml(item.label || "Service")}</span>
          <span class="analytics-mini-track"><span style="width: ${width}%"></span></span>
        </span>
        <strong>${total}</strong>
      </div>
    `;
  }).join("");
}

function renderCategoryMix(items) {
  const list = document.getElementById("categoryMixBars");
  if (!list) return;
  if (!Array.isArray(items) || items.length === 0) {
    list.innerHTML = '<p class="analytics-empty">No active requests.</p>';
    return;
  }

  const max = Math.max(1, ...items.map((item) => safeCount(item.total)));
  list.innerHTML = items.map((item) => {
    const total = safeCount(item.total);
    const width = total > 0 ? Math.max(6, Math.round((total / max) * 100)) : 0;
    return `
      <div class="analytics-bar">
        <div class="analytics-bar__meta">
          <span>${escapeHtml(item.label || "Other")}</span>
          <strong>${total}</strong>
        </div>
        <div class="analytics-bar__track"><span style="width: ${width}%"></span></div>
      </div>
    `;
  }).join("");
}

function showAnalyticsUnavailable(message) {
  const warning = document.getElementById("analyticsWarning");
  if (warning) {
    warning.textContent = message || "Analytics are temporarily unavailable.";
    warning.hidden = false;
  }
  [
    "activeRequestsCount", "queueCount", "ordersCount", "statusPendingCount",
    "statusApprovedCount", "statusOngoingCount", "statusForPickupCount",
    "todayNewRequestsCount", "todayCompletedCount", "todayCancelledCount",
  ].forEach((id) => {
    const element = document.getElementById(id);
    if (element) element.textContent = "—";
  });
}

function updateDashboard(data) {
  if (!data || data.available !== true) {
    showAnalyticsUnavailable(data?.error);
    return;
  }

  const warning = document.getElementById("analyticsWarning");
  if (warning) warning.hidden = true;

  setCount("activeRequestsCount", data.activeRequests);
  setCount("queueCount", data.activeQueue);
  setCount("ordersCount", data.visibleOrders);

  const analytics = data.analytics || {};
  const status = analytics.status || {};
  setCount("statusPendingCount", status.pending);
  setCount("statusApprovedCount", status.approved);
  setCount("statusOngoingCount", status.ongoing);
  setCount("statusForPickupCount", status.forPickup);

  const today = analytics.today || {};
  setCount("todayNewRequestsCount", today.newRequests);
  setCount("todayCompletedCount", today.completed);
  setCount("todayCancelledCount", today.cancelled);
  renderTopServices(analytics.topServices);
  renderCategoryMix(analytics.categoryMix);
}

const dashboardRefreshStorageKey = "servitech:admin-dashboard-refresh";
let dashboardStatsRequestInFlight = false;

async function fetchStats() {
  if (dashboardStatsRequestInFlight) return;
  const endpoint = document.body?.dataset.dashboardStatsUrl;
  if (!endpoint) return;

  dashboardStatsRequestInFlight = true;
  try {
    const response = await fetch(endpoint, {
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Accept": "application/json" },
    });
    if (!response.ok) throw new Error(`Dashboard stats request failed (${response.status})`);
    updateDashboard(await response.json());
  } catch (error) {
    console.error("Failed to fetch dashboard analytics:", error);
  } finally {
    dashboardStatsRequestInFlight = false;
  }
}

fetchStats();
setInterval(fetchStats, 5000);

window.addEventListener("storage", (event) => {
  if (event.key === dashboardRefreshStorageKey) fetchStats();
});
window.addEventListener("servitech:admin-dashboard-refresh", fetchStats);
document.addEventListener("visibilitychange", () => {
  if (!document.hidden) fetchStats();
});
