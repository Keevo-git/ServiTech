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
      hour12: true
    });

    const updateClock = () => {
      nowEl.textContent = formatter.format(new Date());
    };

    updateClock();
    setInterval(updateClock, 30000);
  }

  const counters = Array.from(document.querySelectorAll(".value[data-count]"));
  counters.forEach((el, idx) => {
    const target = Number(el.getAttribute("data-count") || 0);
    if (!Number.isFinite(target) || target <= 0) {
      el.textContent = "0";
      return;
    }

    const duration = 600 + idx * 80;
    const start = performance.now();

    const step = (now) => {
      const progress = Math.min(1, (now - start) / duration);
      const eased = 1 - Math.pow(1 - progress, 3);
      const current = Math.round(target * eased);
      el.textContent = String(current);
      if (progress < 1) requestAnimationFrame(step);
    };

    requestAnimationFrame(step);
  });
});

// 🔥 REAL-TIME DASHBOARD UPDATE

function escapeHtml(value) {
  return String(value ?? "").replace(/[&<>"']/g, (char) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#039;",
  }[char]));
}

function renderMostRequested(items) {
  const list = document.getElementById("mostRequestedList");
  if (!list) return;

  if (!Array.isArray(items) || items.length === 0) {
    list.innerHTML = '<p class="analytics-empty">No queue requests yet.</p>';
    return;
  }

  const totals = items.map((item) => Number(item.total || 0)).filter(Number.isFinite);
  const max = Math.max(1, ...totals);
  list.innerHTML = items.map((item, index) => {
    const total = Number(item.total || 0);
    const safeTotal = Number.isFinite(total) ? total : 0;
    const width = Math.max(8, Math.round((safeTotal / max) * 100));
    return `
      <div class="analytics-row">
        <span class="analytics-rank">${index + 1}</span>
        <span class="analytics-row__body">
          <span class="analytics-label">${escapeHtml(item.label || "Service")}</span>
          <span class="analytics-mini-track"><span style="width: ${width}%"></span></span>
        </span>
        <strong>${safeTotal}</strong>
      </div>
    `;
  }).join("");
}

function renderServiceMix(items) {
  const list = document.getElementById("serviceMixBars");
  if (!list) return;

  if (!Array.isArray(items) || items.length === 0) {
    list.innerHTML = '<p class="analytics-empty">No service data yet.</p>';
    return;
  }

  const totals = items.map((item) => Number(item.total || 0)).filter(Number.isFinite);
  const max = Math.max(1, ...totals);
  list.innerHTML = items.map((item) => {
    const total = Number(item.total || 0);
    const safeTotal = Number.isFinite(total) ? total : 0;
    const width = Math.max(6, Math.round((safeTotal / max) * 100));
    return `
      <div class="analytics-bar">
        <div class="analytics-bar__meta">
          <span>${escapeHtml(item.label || "Service")}</span>
          <strong>${safeTotal}</strong>
        </div>
        <div class="analytics-bar__track"><span style="width: ${width}%"></span></div>
      </div>
    `;
  }).join("");
}

function updateAnalytics(analytics) {
  const data = analytics || {};
  renderMostRequested(data.mostRequested);
  renderServiceMix(data.serviceMix);

  const today = data.today || {};
  const todayQueuesEl = document.getElementById("todayQueuesCount");
  const todayCompletedEl = document.getElementById("todayCompletedCount");
  const todayCancelledEl = document.getElementById("todayCancelledCount");

  if (todayQueuesEl) todayQueuesEl.textContent = Number(today.queues || 0);
  if (todayCompletedEl) todayCompletedEl.textContent = Number(today.completed || 0);
  if (todayCancelledEl) todayCancelledEl.textContent = Number(today.cancelled || 0);
}

async function fetchStats() {
  try {
    const res = await fetch("/pages/admin/get_dashboard_stats.php");
    const data = await res.json();

    const customersEl = document.getElementById("customersCount");
    const ordersEl = document.getElementById("ordersCount");
    const queueEl = document.getElementById("queueCount");

    if (customersEl) customersEl.textContent = data.customers;
    if (ordersEl) ordersEl.textContent = data.onlineOrders;
    if (queueEl) queueEl.textContent = data.activeQueue;
    updateAnalytics(data.analytics);

  } catch (err) {
    console.error("Failed to fetch stats:", err);
  }
}

// run immediately
fetchStats();

// refresh every 5 seconds
setInterval(fetchStats, 5000);
