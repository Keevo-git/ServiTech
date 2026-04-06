<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/db.php";

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$user_id = (int)($_SESSION["user_id"] ?? 0);

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

function parse_queue_details($details): array {
  if (is_array($details)) return $details;
  if (is_string($details) && trim($details) !== "") {
    $decoded = json_decode($details, true);
    if (is_array($decoded)) return $decoded;
  }
  return [];
}

function format_status_label($status) {
  $s = strtoupper(trim((string)$status));
  if ($s === "FOR PICK-UP") return "For Pick-up";
  return ucwords(strtolower($s));
}

function queue_status_tone($status) {
  $s = strtoupper(trim((string)$status));
  if ($s === "ONGOING") return "ongoing";
  if ($s === "FOR PICK-UP") return "pickup";
  if ($s === "DONE") return "done";
  if ($s === "CANCELLED") return "cancelled";
  return "pending";
}

function queue_category_meta(string $categoryKey): array {
  return match ($categoryKey) {
    "online_print" => [
      "label" => "Online Printing",
      "sql" => "q.category = :category_printing AND COALESCE(q.details->>'service_label', '') = :online_service_label",
      "params" => [
        ":category_printing" => "printing",
        ":online_service_label" => "Online Print Order",
      ],
    ],
    "printing" => [
      "label" => "Printing",
      "sql" => "q.category = :category_printing AND COALESCE(q.details->>'service_label', '') <> :online_service_label",
      "params" => [
        ":category_printing" => "printing",
        ":online_service_label" => "Online Print Order",
      ],
    ],
    "installation" => [
      "label" => "Installation",
      "sql" => "q.category = :category_installation",
      "params" => [":category_installation" => "installation"],
    ],
    default => [
      "label" => "Repair",
      "sql" => "q.category = :category_repair",
      "params" => [":category_repair" => "repair"],
    ],
  };
}

function normalize_service_label(string $serviceLabel, string $fallbackLabel): string {
  $serviceLabel = trim($serviceLabel);
  if ($serviceLabel === "") return $fallbackLabel;
  if (strcasecmp($serviceLabel, "Online Print Order") === 0) return "Online Printing";
  return $serviceLabel;
}

function build_short_details(array $details): string {
  $parts = [];

  if (!empty($details["paper_size"])) {
    $parts[] = trim((string)$details["paper_size"]);
  }

  if (!empty($details["quantity"])) {
    $qty = max(1, (int)$details["quantity"]);
    $parts[] = $qty . " " . ($qty === 1 ? "copy" : "copies");
  }

  if (!empty($details["color_option"])) {
    $parts[] = trim((string)$details["color_option"]);
  }

  if (!empty($details["package_label"])) {
    $parts[] = trim((string)$details["package_label"]);
  }

  if (!empty($details["device_type"])) {
    $parts[] = trim((string)$details["device_type"]);
  }

  if (!empty($details["lamination_type"])) {
    $parts[] = ucfirst(strtolower(trim((string)$details["lamination_type"]))) . " Lamination";
  }

  if (!count($parts) && !empty($details["notes"])) {
    $parts[] = trim((string)$details["notes"]);
  }

  if (!count($parts)) return "No extra details";

  $parts = array_slice($parts, 0, 3);
  return implode(" | ", $parts);
}

function fetch_user_queue_items(PDO $pdo, int $userId, string $categoryKey, int $limit, bool $activeOnly): array {
  $limit = max(1, $limit);
  $meta = queue_category_meta($categoryKey);
  $statusSql = $activeOnly ? "AND q.status NOT IN ('DONE', 'CANCELLED')" : "";

  $sql = "
    SELECT q.queue_code, q.status, q.details, q.created_at
    FROM queues q
    WHERE q.user_id = :user_id
      AND {$meta['sql']}
      {$statusSql}
    ORDER BY q.created_at DESC
    LIMIT {$limit}
  ";

  $params = array_merge([":user_id" => $userId], $meta["params"]);
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  $items = [];
  foreach ($stmt->fetchAll() as $row) {
    $details = parse_queue_details($row["details"] ?? null);
    $createdAt = trim((string)($row["created_at"] ?? ""));
    $status = strtoupper(trim((string)($row["status"] ?? "PENDING")));
    $serviceLabel = normalize_service_label((string)($details["service_label"] ?? ""), $meta["label"]);

    $items[] = [
      "queue_code" => trim((string)($row["queue_code"] ?? "")),
      "status" => $status,
      "status_label" => format_status_label($status),
      "status_tone" => queue_status_tone($status),
      "category_label" => $meta["label"],
      "service_label" => $serviceLabel,
      "details_label" => build_short_details($details),
      "created_at" => $createdAt,
      "created_at_label" => $createdAt !== "" ? date("M d, Y h:i A", strtotime($createdAt)) : "",
    ];
  }

  return $items;
}

$display_name = format_fullname($fullname);
$queueCategories = ["online_print", "printing", "installation", "repair"];
$queueCategoryMeta = [];
$activeQueues = [];
$recentQueues = [];

foreach ($queueCategories as $categoryKey) {
  $meta = queue_category_meta($categoryKey);
  $queueCategoryMeta[$categoryKey] = $meta["label"];
  $activeQueues[$categoryKey] = fetch_user_queue_items($pdo, $user_id, $categoryKey, 1, true);
  $recentQueues[$categoryKey] = fetch_user_queue_items($pdo, $user_id, $categoryKey, 1, false);
}

$dashboardQueues = [
  "active" => $activeQueues,
  "recent" => $recentQueues,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Customer Dashboard</title>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260406b2">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260406b2">
  <style>
    body.customer-layout.customer-page--dashboard .customer-dashboard {
      display: grid;
      grid-template-columns: 1fr 1fr !important;
      gap: 24px;
      align-items: stretch;
    }

    body.customer-layout.customer-page--dashboard .customer-dashboard > .dashboard-card {
      height: 100%;
      display: flex;
      flex-direction: column;
      min-width: 0;
    }

    .queue-carousel-card {
      overflow: hidden;
      height: 306px;
      min-height: 306px;
      border: 1px solid rgba(232, 199, 123, 0.30);
    }

    .queue-carousel-card--mine {
      background: linear-gradient(180deg, #fffdf8 0%, #fff9ef 100%);
      box-shadow: 0 12px 26px rgba(153, 96, 16, 0.11);
    }

    .queue-carousel-card--latest {
      background: linear-gradient(180deg, #fbfcff 0%, #f3f8ff 100%);
      box-shadow: 0 12px 26px rgba(37, 99, 235, 0.09);
      border-color: rgba(147, 197, 253, 0.34);
    }

    .queue-carousel {
      display: grid;
      grid-template-rows: 52px 18px 126px;
      gap: 10px;
      flex: 1;
      min-height: 0;
      height: 100%;
      align-content: start;
    }

    .queue-carousel__topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      min-height: 52px;
    }

    .queue-carousel__nav {
      border: 0;
      width: 36px;
      height: 36px;
      border-radius: 50%;
      font-size: 16px;
      font-weight: 700;
      cursor: pointer;
      transition: transform 0.18s ease, background 0.18s ease, color 0.18s ease;
      flex-shrink: 0;
    }

    .queue-carousel-card--mine .queue-carousel__nav {
      background: #f7e6bf;
      color: #4A0505;
    }

    .queue-carousel-card--mine .queue-carousel__nav:hover {
      background: #FAB12F;
      transform: translateY(-1px);
    }

    .queue-carousel-card--latest .queue-carousel__nav {
      background: #dbeafe;
      color: #1d4ed8;
    }

    .queue-carousel-card--latest .queue-carousel__nav:hover {
      background: #bfdbfe;
      color: #1e40af;
      transform: translateY(-1px);
    }

    .queue-carousel__nav:focus-visible,
    .queue-carousel__dot:focus-visible {
      outline: 2px solid #4A0505;
      outline-offset: 2px;
    }

    .queue-carousel__category {
      display: flex;
      align-items: center;
      justify-content: center;
      align-self: stretch;
      text-align: center;
      font-size: 20px;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      flex: 1;
      min-width: 0;
      line-height: 1.1;
    }

    .queue-carousel-card--mine .queue-carousel__category {
      color: #4A0505;
    }

    .queue-carousel-card--latest .queue-carousel__category {
      color: #143b7a;
    }

    .queue-carousel__dots {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 8px;
      min-height: 18px;
    }

    .queue-carousel__dot {
      width: 9px;
      height: 9px;
      border-radius: 999px;
      border: 0;
      padding: 0;
      cursor: pointer;
      background: #ead4a5;
      transition: transform 0.18s ease, background 0.18s ease;
    }

    .queue-carousel__dot.is-active {
      background: #FAB12F;
      transform: scale(1.15);
    }

    .queue-carousel-card--mine .queue-carousel__dot {
      background: #ead4a5;
    }

    .queue-carousel-card--mine .queue-carousel__dot.is-active {
      background: #FAB12F;
      transform: scale(1.15);
    }

    .queue-carousel-card--latest .queue-carousel__dot {
      background: #bfdbfe;
    }

    .queue-carousel-card--latest .queue-carousel__dot.is-active {
      background: #3b82f6;
      transform: scale(1.15);
    }

    .queue-carousel__list {
      display: flex;
      align-items: flex-start;
      min-height: 126px;
      height: 126px;
      padding: 0 8px;
      box-sizing: border-box;
      overflow: hidden;
      opacity: 1;
      transition: opacity 0.2s ease;
      margin-top: 0;
    }

    .queue-carousel__list.is-fading {
      opacity: 0.35;
    }

    .queue-item {
      border: 1px solid #e7d5ad;
      background: #fffaf0;
      border-radius: 14px;
      padding: 14px 16px;
      height: 112px;
      min-height: 112px;
      display: grid;
      grid-template-rows: 34px 24px 20px;
      align-content: start;
      width: 100%;
      box-sizing: border-box;
      transition: none;
    }

    .queue-carousel-card--mine .queue-item {
      border: 1px solid #f0dfbe;
      background: #fffaf0;
    }


    .queue-carousel-card--latest .queue-item {
      border: 1px solid #c8dcfb;
      background: #f7faff;
    }


    .queue-item__head {
      display: grid;
      grid-template-columns: minmax(0, 1fr) 112px;
      align-items: start;
      column-gap: 12px;
      min-height: 34px;
    }

    .queue-item__code {
      margin-left: 0;
      font-size: 22px;
      font-weight: 700;
      line-height: 1.1;
      word-break: break-word;
    }

    .queue-carousel-card--mine .queue-item__code {
      color: #13274a;
    }

    .queue-carousel-card--latest .queue-item__code {
      color: #163d73;
    }

    .queue-item__badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      justify-self: end;
      align-self: start;
      width: 112px;
      min-width: 112px;
      border-radius: 999px;
      padding: 5px 12px;
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      line-height: 1.2;
      border: 1px solid transparent;
      white-space: nowrap;
      box-sizing: border-box;
      text-align: center;
    }

    .queue-item__badge--pending {
      background: #f3f4f6;
      color: #4b5563;
      border-color: #d1d5db;
    }

    .queue-item__badge--ongoing {
      background: #fff7ed;
      color: #c2410c;
      border-color: #fdba74;
    }

    .queue-item__badge--pickup {
      background: #eff6ff;
      color: #2563eb;
      border-color: #93c5fd;
    }

    .queue-item__badge--done {
      background: #ecfdf5;
      color: #16a34a;
      border-color: #86efac;
    }

    .queue-item__badge--cancelled {
      background: #fef2f2;
      color: #dc2626;
      border-color: #fca5a5;
    }

    .queue-item__label {
      margin-top: 6px;
      min-height: 24px;
      font-size: 15px;
      font-weight: 700;
      display: flex;
      align-items: center;
      width: 100%;
      max-width: 100%;
      padding-right: 124px;
      box-sizing: border-box;
    }

    .queue-carousel-card--mine .queue-item__label {
      color: #4A0505;
    }

    .queue-carousel-card--latest .queue-item__label {
      color: #163d73;
    }

    .queue-item__details,
    .queue-item__meta {
      margin-top: 2px;
      font-size: 13px;
      line-height: 20px;
      min-height: 20px;
      width: 100%;
      max-width: 100%;
      padding-right: 124px;
      box-sizing: border-box;
      overflow: hidden;
      white-space: nowrap;
      text-overflow: ellipsis;
      display: block;
      align-self: start;
    }

    .queue-carousel-card--mine .queue-item__details,
    .queue-carousel-card--mine .queue-item__meta {
      color: #6b5a3b;
    }

    .queue-carousel-card--latest .queue-item__details,
    .queue-carousel-card--latest .queue-item__meta {
      color: #4f6485;
    }

    .queue-carousel__empty {
      display: flex;
      align-items: center;
      justify-content: center;
      height: 112px;
      min-height: 112px;
      border-radius: 14px;
      text-align: center;
      padding: 14px 16px;
      width: 100%;
      box-sizing: border-box;
    }

    .queue-carousel-card--mine .queue-carousel__empty {
      border: 1px dashed #e0c991;
      background: #fffaf0;
      color: #7a6b4f;
    }

    .queue-carousel-card--latest .queue-carousel__empty {
      border: 1px dashed #bfdbfe;
      background: #f8fbff;
      color: #4f6485;
    }

    @media (max-width: 900px) {
      body.customer-layout.customer-page--dashboard .customer-dashboard {
        grid-template-columns: 1fr !important;
      }

      body.customer-layout.customer-page--dashboard .customer-dashboard > .dashboard-card {
        height: auto;
      }

      .queue-carousel-card {
        height: 306px;
        min-height: 306px;
      }
    }

    @media (max-width: 640px) {
      .queue-carousel-card {
        height: 296px;
        min-height: 296px;
      }

      .queue-carousel__topbar {
        gap: 8px;
        min-height: 44px;
      }

      .queue-carousel__nav {
        width: 32px;
        height: 32px;
        font-size: 14px;
      }

      .queue-carousel__category {
        font-size: 16px;
        letter-spacing: 0.02em;
      }

      .queue-item__head {
        grid-template-columns: 1fr;
        row-gap: 8px;
        min-height: auto;
      }

      .queue-item {
        height: 126px;
        min-height: 126px;
        grid-template-rows: auto auto 20px;
      }

      .queue-carousel__list {
        height: 126px;
        min-height: 126px;
        padding: 0 4px;
      }

      .queue-item {
        padding: 12px 14px;
      }

      .queue-item__code {
        font-size: 19px;
      }

      .queue-item__badge {
        width: auto;
        min-width: 0;
        justify-self: start;
        white-space: normal;
      }

      .queue-item__label,
      .queue-item__details,
      .queue-item__meta {
        padding-right: 0;
      }

      .queue-item__details,
      .queue-item__meta {
        min-height: 20px;
        line-height: 20px;
      }
    }
  </style>
</head>
<body class="customer-layout customer-page--dashboard">

<?php include __DIR__ . "/../../components/header.php"; ?>

<section class="customer-hero">
  <h2>Welcome, <span id="customerName"><?php echo htmlspecialchars($display_name); ?></span>!</h2>
  <p>Manage your queue, request status and print orders.</p>
</section>

<section class="customer-dashboard">
  <div class="dashboard-card queue-carousel-card queue-carousel-card--mine">
    <h3>MY CURRENT REQUESTS</h3>
    <div class="divider"></div>

    <div class="queue-carousel">
      <div class="queue-carousel__topbar">
        <button type="button" class="queue-carousel__nav" id="activeQueuePrev" aria-label="Previous active request category">&#9664;</button>
        <div class="queue-carousel__category" id="activeQueueCategory">ONLINE PRINTING</div>
        <button type="button" class="queue-carousel__nav" id="activeQueueNext" aria-label="Next active request category">&#9654;</button>
      </div>

      <div class="queue-carousel__dots" id="activeQueueDots" aria-label="Active request category indicators"></div>
      <div class="queue-carousel__list" id="activeQueueList"></div>
    </div>
  </div>

  <div class="dashboard-card queue-carousel-card queue-carousel-card--latest">
    <h3>LATEST QUEUE</h3>
    <div class="divider"></div>

    <div class="queue-carousel">
      <div class="queue-carousel__topbar">
        <button type="button" class="queue-carousel__nav" id="recentQueuePrev" aria-label="Previous latest queue category">&#9664;</button>
        <div class="queue-carousel__category" id="recentQueueCategory">ONLINE PRINTING</div>
        <button type="button" class="queue-carousel__nav" id="recentQueueNext" aria-label="Next latest queue category">&#9654;</button>
      </div>

      <div class="queue-carousel__dots" id="recentQueueDots" aria-label="Latest queue category indicators"></div>
      <div class="queue-carousel__list" id="recentQueueList"></div>
    </div>
  </div>
</section>

<section class="quick-access">
  <h3>Quick Access</h3>
  <div class="divider"></div>

  <div class="quick-grid">
    <a href="/pages/customer/custo_place_queueing.php" class="quick-card-link">
      <div class="quick-card">
        <div class="quick-icon-box">
          <img src="/assets/images/LANDING_QUEUEING.png" alt="Join Queue" class="quick-icon">
        </div>
        <h4>Join Queue</h4>
        <p>Join the line to place your request.</p>
      </div>
    </a>

    <a href="/pages/customer/custo_service_status.php" class="quick-card-link">
      <div class="quick-card">
        <div class="quick-icon-box">
          <img src="/assets/images/LANDING_SERVICE-STAT.png" alt="Service Status" class="quick-icon">
        </div>
        <h4>Service Status</h4>
        <p>Check your requested service status or your queue status.</p>
      </div>
    </a>

    <a href="/pages/customer/custo_print_order.php" class="quick-card-link">
      <div class="quick-card">
        <div class="quick-icon-box">
          <img src="/assets/images/LANDING_PRINT-ORD.png" alt="Print Order" class="quick-icon">
        </div>
        <h4>Print Order</h4>
        <p>Place an order to print your document.</p>
      </div>
    </a>

    <a href="/pages/customer/custo_edit_profile.php" class="quick-card-link">
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

<?php include __DIR__ . "/../../components/footer.php"; ?>

<script>
  const queueCategories = ["online_print", "printing", "installation", "repair"];
  const queueCategoryMeta = <?php echo json_encode($queueCategoryMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  const queuePollIntervalMs = 5000;

  let dashboardQueues = <?php echo json_encode($dashboardQueues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  let activeQueueIndex = 0;
  let recentQueueIndex = 0;

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
    if (["pending", "ongoing", "pickup", "done", "cancelled"].includes(raw)) return raw;
    if (raw === "for pick-up") return "pickup";
    return "pending";
  }

  function createQueueCarousel(config) {
    const {
      mode,
      emptyMessage,
      titleEl,
      listEl,
      dotsEl,
      prevEl,
      nextEl,
      getIndex,
      setIndex
    } = config;

    let renderTimer = null;
    let sectionData = dashboardQueues[mode] || {};

    function renderDots() {
      const activeIndex = getIndex();
      dotsEl.innerHTML = queueCategories.map((categoryKey, index) => {
        const label = queueCategoryMeta[categoryKey] || categoryKey;
        const activeClass = index === activeIndex ? " is-active" : "";
        return `
          <button
            type="button"
            class="queue-carousel__dot${activeClass}"
            data-category-index="${index}"
            aria-label="Show ${escapeHtml(label)} in ${escapeHtml(mode)}"
          ></button>
        `;
      }).join("");
    }

    function buildItemMarkup(item, categoryLabel) {
      const tone = normalizeStatusTone(item.status_tone || item.status);
      const code = escapeHtml(formatQueueCode(item.queue_code));
      const badge = escapeHtml(item.status_label || "Pending");
      const serviceLabel = escapeHtml(item.service_label || categoryLabel);
      const detailsLabel = escapeHtml(item.details_label || "No extra details");

      return `
        <article class="queue-item">
          <div class="queue-item__head">
            <div class="queue-item__code">${code}</div>
            <div class="queue-item__badge queue-item__badge--${tone}">${badge}</div>
          </div>
          <div class="queue-item__label">${serviceLabel}</div>
          <div class="queue-item__details">${detailsLabel}</div>
        </article>
      `;
    }

    function render() {
      const currentIndex = getIndex();
      const categoryKey = queueCategories[currentIndex];
      const categoryLabel = queueCategoryMeta[categoryKey] || categoryKey;
      const items = Array.isArray(sectionData[categoryKey]) ? sectionData[categoryKey] : [];

      titleEl.textContent = categoryLabel.toUpperCase();
      listEl.classList.add("is-fading");

      if (renderTimer) {
        window.clearTimeout(renderTimer);
      }

      renderTimer = window.setTimeout(() => {
        if (!items.length) {
          listEl.innerHTML = `<div class="queue-carousel__empty">${escapeHtml(emptyMessage)}</div>`;
        } else {
          listEl.innerHTML = items.map((item) => buildItemMarkup(item, categoryLabel)).join("");
        }

        renderDots();
        listEl.classList.remove("is-fading");
      }, 90);
    }

    function move(delta) {
      const nextIndex = (getIndex() + delta + queueCategories.length) % queueCategories.length;
      setIndex(nextIndex);
      render();
    }

    prevEl?.addEventListener("click", () => move(-1));
    nextEl?.addEventListener("click", () => move(1));

    dotsEl?.addEventListener("click", (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;

      const nextIndex = Number(target.dataset.categoryIndex);
      if (!Number.isInteger(nextIndex)) return;

      setIndex(nextIndex);
      render();
    });

    render();

    return {
      setData(nextData) {
        sectionData = nextData || {};
        render();
      }
    };
  }

  const activeCarousel = createQueueCarousel({
    mode: "active",
    emptyMessage: "No active requests in this category",
    titleEl: document.getElementById("activeQueueCategory"),
    listEl: document.getElementById("activeQueueList"),
    dotsEl: document.getElementById("activeQueueDots"),
    prevEl: document.getElementById("activeQueuePrev"),
    nextEl: document.getElementById("activeQueueNext"),
    getIndex: () => activeQueueIndex,
    setIndex: (value) => { activeQueueIndex = value; }
  });

  const recentCarousel = createQueueCarousel({
    mode: "recent",
    emptyMessage: "No latest queue in this category",
    titleEl: document.getElementById("recentQueueCategory"),
    listEl: document.getElementById("recentQueueList"),
    dotsEl: document.getElementById("recentQueueDots"),
    prevEl: document.getElementById("recentQueuePrev"),
    nextEl: document.getElementById("recentQueueNext"),
    getIndex: () => recentQueueIndex,
    setIndex: (value) => { recentQueueIndex = value; }
  });

  async function refreshDashboardQueues() {
    try {
      const response = await fetch(`${servitechUrl("/pages/customer/get_latest_queues.php")}?t=${Date.now()}`, {
        cache: "no-store",
        credentials: "same-origin",
        headers: {
          "Accept": "application/json",
          "X-Requested-With": "XMLHttpRequest"
        }
      });

      if (!response.ok) {
        throw new Error(`Dashboard queue refresh failed with status ${response.status}`);
      }

      const data = await response.json();
      dashboardQueues = {
        active: data && typeof data.active === "object" ? data.active : {},
        recent: data && typeof data.recent === "object" ? data.recent : {}
      };

      activeCarousel.setData(dashboardQueues.active);
      recentCarousel.setData(dashboardQueues.recent);
    } catch (error) {
      console.warn("Dashboard queue refresh failed:", error);
    }
  }

  window.setInterval(refreshDashboardQueues, queuePollIntervalMs);
</script>

</body>
</html>