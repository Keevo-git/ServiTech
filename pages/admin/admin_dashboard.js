document.addEventListener("DOMContentLoaded", () => {
  const nowEl = document.getElementById("adminNow");
  if (nowEl) {
    const formatter = new Intl.DateTimeFormat("en-US", {
      year: "numeric",
      month: "short",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit"
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

  } catch (err) {
    console.error("Failed to fetch stats:", err);
  }
}

// run immediately
fetchStats();

// refresh every 5 seconds
setInterval(fetchStats, 5000);