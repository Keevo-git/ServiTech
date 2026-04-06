<?php
require_once __DIR__ . "/../components/auth_guard.php";
require_once __DIR__ . "/../config/db.php";

$user_id = (int)($_SESSION["user_id"] ?? 0);

// Fetch user display name.
$stmt = $pdo->prepare("SELECT fullname FROM users WHERE id = :id LIMIT 1");
$stmt->execute([":id" => $user_id]);
$user = $stmt->fetch();

$fullname = $user["fullname"] ?? "Customer";

function format_fullname($name) {
  $name = trim((string)$name);
  if ($name === "") return "Customer";
  $name = preg_replace('/\s+/', ' ', $name);
  return ucwords(strtolower($name));
}

// Active queue: latest queue that is still in progress for the logged-in customer.
$activeStatuses = ["PENDING", "ONGOING", "FOR PICK-UP"];
$in = implode(",", array_fill(0, count($activeStatuses), "?"));

$sqlActive = "
  SELECT queue_code, status, details, created_at
  FROM queues
  WHERE user_id = ?
    AND status IN ($in)
  ORDER BY created_at DESC
  LIMIT 1
";

$paramsActive = array_merge([$user_id], $activeStatuses);
$stmt = $pdo->prepare($sqlActive);
$stmt->execute($paramsActive);
$activeQueue = $stmt->fetch();

// Parse details (jsonb) safely.
$activeDetails = [];
if ($activeQueue && isset($activeQueue["details"])) {
  if (is_array($activeQueue["details"])) {
    $activeDetails = $activeQueue["details"];
  } elseif (is_string($activeQueue["details"]) && $activeQueue["details"] !== "") {
    $d = json_decode($activeQueue["details"], true);
    if (is_array($d)) $activeDetails = $d;
  }
}

function build_details_line($details) {
  $parts = [];
  if (!empty($details["paper_size"])) $parts[] = $details["paper_size"];
  if (!empty($details["quantity"])) $parts[] = "Qty: " . $details["quantity"];
  if (!empty($details["color_option"])) $parts[] = $details["color_option"];
  if (!empty($details["package_label"])) $parts[] = $details["package_label"];
  if (!empty($details["lamination_type"])) $parts[] = "Lam: " . $details["lamination_type"];
  if (!empty($details["device_type"])) $parts[] = $details["device_type"];
  return count($parts) ? implode(" | ", $parts) : "---";
}

function status_class($status) {
  $s = strtoupper(trim((string)$status));
  if ($s === "ONGOING") return "ongoing";
  if ($s === "FOR PICK-UP") return "ready";
  return "pending";
}

function format_status_label($status) {
  $s = strtoupper(trim((string)$status));
  if ($s === "FOR PICK-UP") return "For Pick-up";
  return ucwords(strtolower($s));
}

function queue_status_tone($status) {
  $s = strtoupper(trim((string)$status));
  if ($s === "ONGOING") return "ongoing";
  if ($s === "FOR PICK-UP") return "ready";
  if ($s === "DONE") return "done";
  if ($s === "CANCELLED") return "cancelled";
  return "pending";
}

function fetch_recent_queues_by_category(PDO $pdo, string $category, int $limit = 5): array {
  $limit = max(1, $limit);
  $stmt = $pdo->prepare("\n    SELECT queue_code, category, status, created_at\n    FROM queues\n    WHERE category = :category\n    ORDER BY created_at DESC\n    LIMIT {$limit}\n  ");
  $stmt->execute([":category" => $category]);

  $items = [];
  foreach ($stmt->fetchAll() as $row) {
    $createdAt = trim((string)($row["created_at"] ?? ""));
    $status = strtoupper(trim((string)($row["status"] ?? "PENDING")));
    $items[] = [
      "queue_code" => trim((string)($row["queue_code"] ?? "")),
      "category" => trim((string)($row["category"] ?? $category)),
      "status" => $status,
      "status_label" => format_status_label($status),
      "status_tone" => queue_status_tone($status),
      "created_at" => $createdAt,
      "created_at_label" => $createdAt !== "" ? date("M d, Y h:i A", strtotime($createdAt)) : "",
    ];
  }

  return $items;
}

$display_name = format_fullname($fullname);
$hasQueue = !empty($activeQueue);
$queueNo = $hasQueue ? ($activeQueue["queue_code"] ?? "#---") : "#---";
$queueStatus = $hasQueue ? strtoupper($activeQueue["status"] ?? "PENDING") : "PENDING";
$queueService = $hasQueue ? ($activeDetails["service_label"] ?? "---") : "---";
$queueDetails = $hasQueue ? build_details_line($activeDetails) : "---";

// Latest queues viewer data.
$queues = [
  "printing" => fetch_recent_queues_by_category($pdo, "printing", 5),
  "installation" => fetch_recent_queues_by_category($pdo, "installation", 5),
  "repair" => fetch_recent_queues_by_category($pdo, "repair", 5),
  // Maps walk-in records to the optional online printing slide.
  "online_print" => fetch_recent_queues_by_category($pdo, "walkin", 5),
];

$queueCategoryMeta = [
  "printing" => "Printing",
  "installation" => "Installation",
  "repair" => "Repair",
  "online_print" => "Online Printing",
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Customer Dashboard</title>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260315h9">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260315h20">
  <style>
    .latest-queues-card {
      overflow: hidden;
    }

    .latest-queues-viewer {
      display: grid;
      gap: 14px;
      flex: 1;
      min-height: 0;
    }

    .latest-queues-topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }

    .latest-queues-nav {
      border: 0;
      background: #f7e6bf;
      color: #4A0505;
      width: 36px;
      height: 36px;
      border-radius: 50%;
      font-size: 16px;
      font-weight: 700;
      cursor: pointer;
      transition: transform 0.18s ease, background 0.18s ease;
    }

    .latest-queues-nav:hover {
      background: #FAB12F;
      transform: translateY(-1px);
    }

    .latest-queues-nav:focus-visible,
    .latest-queues-dot:focus-visible {
      outline: 2px solid #4A0505;
      outline-offset: 2px;
    }

    .latest-queues-title {
      color: #4A0505;
      text-align: center;
      font-size: 20px;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      flex: 1;
    }

    .latest-queues-dots {
      display: flex;
      justify-content: center;
      gap: 8px;
    }

    .latest-queues-dot {
      width: 9px;
      height: 9px;
      border-radius: 999px;
      background: #ead4a5;
      border: 0;
      padding: 0;
      cursor: pointer;
      transition: transform 0.18s ease, background 0.18s ease;
    }

    .latest-queues-dot.is-active {
      background: #FAB12F;
      transform: scale(1.15);
    }

    .latest-queues-list {
      display: grid;
      gap: 10px;
      min-height: 174px;
      opacity: 1;
      transition: opacity 0.2s ease;
      flex: 1;
      align-content: start;
    }

    .latest-queues-list.is-fading {
      opacity: 0.35;
    }

    .latest-queue-item {
      border: 1px solid #f1e2c2;
      border-radius: 12px;
      padding: 12px 14px;
      background: #fffaf0;
    }

    .latest-queue-main {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }

    .latest-queue-code {
      color: #13274a;
      font-size: 20px;
      font-weight: 700;
      white-space: nowrap;
    }

    .latest-queue-status {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 5px 12px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 700;
      line-height: 1.2;
      text-transform: uppercase;
      border: 1px solid transparent;
    }

    .latest-queue-status--pending {
      background: #fff4dc;
      color: #a86b06;
      border-color: #f3d18f;
    }

    .latest-queue-status--ongoing {
      background: #fef3c7;
      color: #b45309;
      border-color: #f4c46a;
    }

    .latest-queue-status--ready,
    .latest-queue-status--done {
      background: #e6fbef;
      color: #1f9d52;
      border-color: #b7e7c7;
    }

    .latest-queue-status--cancelled {
      background: #ffe7e7;
      color: #b10b0b;
      border-color: #f0b4b4;
    }

    .latest-queue-time {
      margin-top: 6px;
      color: #7a6b4f;
      font-size: 12px;
    }

    .latest-queues-empty {
      display: grid;
      place-items: center;
      min-height: 174px;
      color: #7a6b4f;
      border: 1px dashed #e0c991;
      border-radius: 12px;
      background: #fffaf0;
      text-align: center;
    }

    body.customer-layout.customer-page--dashboard .customer-dashboard {
      align-items: stretch;
    }

    body.customer-layout.customer-page--dashboard .customer-dashboard > .dashboard-card {
      height: 100%;
      display: flex;
      flex-direction: column;
    }

    @media (max-width: 1180px) {
      .latest-queues-title {
        font-size: 18px;
      }

      .latest-queues-list,
      .latest-queues-empty {
        min-height: 160px;
      }
    }

    @media (max-width: 900px) {
      body.customer-layout.customer-page--dashboard .customer-dashboard > .dashboard-card {
        height: auto;
      }

      .latest-queues-title {
        font-size: 17px;
      }

      .latest-queues-list,
      .latest-queues-empty {
        min-height: auto;
      }
    }

    @media (max-width: 640px) {
      .latest-queues-topbar {
        gap: 8px;
      }

      .latest-queues-nav {
        width: 32px;
        height: 32px;
        font-size: 14px;
      }

      .latest-queues-title {
        font-size: 16px;
        letter-spacing: 0.02em;
      }

      .latest-queue-main {
        flex-direction: column;
        align-items: flex-start;
      }

      .latest-queue-code {
        font-size: 18px;
        white-space: normal;
        overflow-wrap: anywhere;
      }

      .latest-queue-status {
        text-align: left;
      }

      .latest-queue-item {
        padding: 10px 12px;
      }
    }
  </style>
</head>
<body class="customer-layout customer-page--dashboard">

<?php include __DIR__ . "/../components/header.php"; ?>

<section class="customer-hero">
  <h2>Welcome, <span id="customerName"><?php echo htmlspecialchars($display_name); ?></span>!</h2>
  <p>Manage your queue, request status and print orders.</p>
</section>

<section class="customer-dashboard">
  <div class="dashboard-card wide">
    <h3>ACTIVE QUEUE</h3>
    <div class="divider"></div>

    <div class="queue-header">
      <span class="queue-number" id="queueNo"><?php echo htmlspecialchars($queueNo); ?></span>
      <span class="status <?php echo htmlspecialchars(status_class($queueStatus)); ?>" id="queueStatus">
        <?php echo htmlspecialchars($queueStatus); ?>
      </span>
    </div>

    <p id="queueService">Service: <?php echo htmlspecialchars($queueService); ?></p>
    <p id="queueDetails">Details: <?php echo htmlspecialchars($queueDetails); ?></p>

    <p id="noQueueMsg" class="queue-empty-message" style="<?php echo $hasQueue ? "display:none;" : "display:block;"; ?>">
      You have no active queue.
    </p>
  </div>

  <div class="dashboard-card latest-queues-card">
    <h3>LATEST QUEUES</h3>
    <div class="divider"></div>

    <div class="latest-queues-viewer">
      <div class="latest-queues-topbar">
        <button type="button" class="latest-queues-nav" id="latestQueuesPrev" aria-label="Previous queue category">&#9664;</button>
        <div class="latest-queues-title" id="latestQueuesTitle">PRINTING</div>
        <button type="button" class="latest-queues-nav" id="latestQueuesNext" aria-label="Next queue category">&#9654;</button>
      </div>

      <div class="latest-queues-dots" id="latestQueuesDots" aria-label="Queue category indicators"></div>
      <div class="latest-queues-list" id="latestQueuesList"></div>
    </div>
  </div>
</section>

<section class="quick-access">
  <h3>Quick Access</h3>
  <div class="divider"></div>

  <div class="quick-grid">
    <a href="/main/custo_place_queueing.php" class="quick-card-link">
      <div class="quick-card">
        <div class="quick-icon-box">
          <img src="/assets/images/LANDING_QUEUEING.png" alt="Join Queue" class="quick-icon">
        </div>
        <h4>Join Queue</h4>
        <p>Join the line to place your request.</p>
      </div>
    </a>

    <a href="/main/custo_service_status.php" class="quick-card-link">
      <div class="quick-card">
        <div class="quick-icon-box">
          <img src="/assets/images/LANDING_SERVICE-STAT.png" alt="Service Status" class="quick-icon">
        </div>
        <h4>Service Status</h4>
        <p>Check your requested service status or your queue status.</p>
      </div>
    </a>

    <a href="/main/custo_print_order.php" class="quick-card-link">
      <div class="quick-card">
        <div class="quick-icon-box">
          <img src="/assets/images/LANDING_PRINT-ORD.png" alt="Print Order" class="quick-icon">
        </div>
        <h4>Print Order</h4>
        <p>Place an order to print your document.</p>
      </div>
    </a>

    <a href="/main/custo_edit_profile.php" class="quick-card-link">
      <div class="quick-card">
        <div class="quick-icon-box">
          <img src="/assets/images/ICON_EDIT_PROF.png" alt="Edit Profile" class="quick-icon">
        </div>
        <h4>Edit Profile</h4>
        <p>Edit your personal information.</p>
      </div>
    </a>
  </div>
</section>

<?php include __DIR__ . "/../components/footer.php"; ?>

<script>
  let queues = <?php echo json_encode($queues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  const queueCategoryMeta = <?php echo json_encode($queueCategoryMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  const queueCategories = ["printing", "installation", "repair", "online_print"];
  const queuePollIntervalMs = 5000;

  let currentQueueIndex = 0;

  const latestQueuesTitle = document.getElementById("latestQueuesTitle");
  const latestQueuesList = document.getElementById("latestQueuesList");
  const latestQueuesDots = document.getElementById("latestQueuesDots");
  const latestQueuesPrev = document.getElementById("latestQueuesPrev");
  const latestQueuesNext = document.getElementById("latestQueuesNext");

  function servitechBasePath() {
    const pathname = window.location.pathname || "";
    if (pathname === "/ServiTech" || pathname.startsWith("/ServiTech/")) return "/ServiTech";
    return "";
  }

  function servitechUrl(path) {
    const cleanPath = path.startsWith("/") ? path : `/${path}`;
    return `${servitechBasePath()}${cleanPath}`;
  }

  function escapeHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, (char) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;"
    }[char]));
  }

  function formatQueueCode(value) {
    const code = String(value ?? "").trim();
    if (!code) return "#---";
    return code.startsWith("#") ? code : `#${code}`;
  }

  function normalizeStatusTone(value) {
    const raw = String(value ?? "").trim().toLowerCase();
    if (["pending", "ongoing", "ready", "done", "cancelled"].includes(raw)) return raw;
    if (raw === "for pick-up") return "ready";
    if (raw === "cancel") return "cancelled";
    return "pending";
  }

  function renderQueueDots() {
    latestQueuesDots.innerHTML = queueCategories.map((category, index) => {
      const activeClass = index === currentQueueIndex ? " is-active" : "";
      const label = queueCategoryMeta[category] || category;
      return `
        <button
          type="button"
          class="latest-queues-dot${activeClass}"
          data-category-index="${index}"
          aria-label="Show ${escapeHtml(label)} queues"
        ></button>
      `;
    }).join("");
  }

  function renderLatestQueues() {
    const category = queueCategories[currentQueueIndex];
    const label = queueCategoryMeta[category] || category;
    const items = Array.isArray(queues[category]) ? queues[category] : [];

    latestQueuesTitle.textContent = label.toUpperCase();
    latestQueuesList.classList.add("is-fading");

    window.setTimeout(() => {
      if (!items.length) {
        latestQueuesList.innerHTML = '<div class="latest-queues-empty">No recent queues</div>';
      } else {
        latestQueuesList.innerHTML = items.map((item) => {
          const tone = normalizeStatusTone(item.status_tone || item.status);
          return `
            <div class="latest-queue-item">
              <div class="latest-queue-main">
                <span class="latest-queue-code">${escapeHtml(formatQueueCode(item.queue_code))}</span>
                <span class="latest-queue-status latest-queue-status--${escapeHtml(tone)}">${escapeHtml(item.status_label || "Pending")}</span>
              </div>
              ${item.created_at_label ? `<div class="latest-queue-time">${escapeHtml(item.created_at_label)}</div>` : ""}
            </div>
          `;
        }).join("");
      }

      renderQueueDots();
      latestQueuesList.classList.remove("is-fading");
    }, 90);
  }

  async function fetchQueues() {
    try {
      const response = await fetch(servitechUrl("/pages/customer/get_latest_queues.php"), {
        credentials: "same-origin",
        headers: {
          "Accept": "application/json",
          "X-Requested-With": "XMLHttpRequest"
        }
      });

      if (!response.ok) {
        throw new Error(`Queue refresh failed with status ${response.status}`);
      }

      const data = await response.json();
      queues = {
        printing: Array.isArray(data.printing) ? data.printing : [],
        installation: Array.isArray(data.installation) ? data.installation : [],
        repair: Array.isArray(data.repair) ? data.repair : [],
        online_print: Array.isArray(data.online_print) ? data.online_print : []
      };
      renderLatestQueues();
    } catch (error) {
      console.warn("Latest queues refresh failed:", error);
    }
  }

  latestQueuesPrev?.addEventListener("click", () => {
    currentQueueIndex = (currentQueueIndex - 1 + queueCategories.length) % queueCategories.length;
    renderLatestQueues();
  });

  latestQueuesNext?.addEventListener("click", () => {
    currentQueueIndex = (currentQueueIndex + 1) % queueCategories.length;
    renderLatestQueues();
  });

  latestQueuesDots?.addEventListener("click", (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    const nextIndex = Number(target.dataset.categoryIndex);
    if (!Number.isInteger(nextIndex)) return;

    currentQueueIndex = nextIndex;
    renderLatestQueues();
  });

  renderLatestQueues();
  window.setInterval(fetchQueues, queuePollIntervalMs);
</script>

</body>
</html>