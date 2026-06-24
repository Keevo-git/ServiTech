<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/db.php";

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$user_id = (int)($_SESSION["user_id"] ?? 0);

function qm_parse_queue_details($details): array {
  if (is_array($details)) return $details;
  if (is_string($details) && trim($details) !== "") {
    $decoded = json_decode($details, true);
    if (is_array($decoded)) return $decoded;
  }
  return [];
}

function qm_format_status_label($status): string {
  $status = strtoupper(trim((string)$status));
  if ($status === "FOR PICK-UP") return "For Pick-up";
  return ucwords(strtolower($status));
}

function qm_status_tone($status): string {
  $status = strtoupper(trim((string)$status));
  if ($status === "ONGOING") return "ongoing";
  if ($status === "FOR PICK-UP") return "pickup";
  if ($status === "DONE") return "done";
  if ($status === "CANCELLED") return "cancelled";
  return "pending";
}

function qm_category_meta(string $categoryKey): array {
  return match ($categoryKey) {
    "printing" => [
      "label" => "Print",
      "sql" => "(q.category IN (:category_printing, :category_online_printorder, :category_printing_online, :category_walkin, :category_printing_walkin) OR UPPER(TRIM(COALESCE(q.queue_code, ''))) LIKE 'OP%')",
      "params" => [
        ":category_printing" => "printing",
        ":category_online_printorder" => "online_printorder",
        ":category_printing_online" => "printing_online",
        ":category_walkin" => "walkin",
        ":category_printing_walkin" => "printing_walkin",
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

function qm_normalize_service_label(string $serviceLabel, string $fallbackLabel): string {
  $serviceLabel = trim($serviceLabel);
  if ($serviceLabel === "") return $fallbackLabel;
  $normalized = strtolower($serviceLabel);
  if (
    in_array($normalized, [
    "document printing",
    "document print",
    "walk-in printing",
    "walk-in document printing",
    "walk-in document print",
    "walkin printing",
    "print walk-in",
    "walk-in",
    "walk in",
    "walkin",
    ], true)
    || (str_contains($normalized, "document") && str_contains($normalized, "print"))
    || (str_contains($normalized, "print") && str_contains($normalized, "order"))
  ) return "Document Print";
  if (strcasecmp($serviceLabel, "xerox") === 0) return "Photocopy";
  if (strcasecmp($serviceLabel, "lamination") === 0) return "Laminating";
  return $serviceLabel;
}

function qm_build_short_details(array $details, bool $includeNotes = true): string {
  $parts = [];
  $value = static function (array $keys) use ($details): string {
    foreach ($keys as $key) {
      if (isset($details[$key]) && !is_array($details[$key]) && trim((string)$details[$key]) !== "") {
        return trim((string)$details[$key]);
      }
    }
    return "";
  };

  foreach ([
    ["paper_size_snapshot", "paper_size"],
    ["color_option_snapshot", "color_option"],
    ["package_snapshot", "package_label"],
    ["device_snapshot", "device_type"],
    ["service_type_snapshot", "repair_type"],
    ["installation_type_snapshot", "installation_type"],
  ] as $keys) {
    if (($selected = $value($keys)) !== "") $parts[] = $selected;
  }

  if (($quantity = $value(["quantity_snapshot", "quantity"])) !== "") {
    $qty = max(1, (int)$quantity);
    array_splice($parts, 1, 0, $qty . " " . ($qty === 1 ? "copy" : "copies"));
  }

  if (($lamination = $value(["lamination_type_snapshot", "lamination_type"])) !== "") {
    $parts[] = ucfirst(strtolower($lamination)) . " Lamination";
  }

  $addOns = $details["add_ons_snapshot"] ?? [];
  if (is_array($addOns)) {
    $addOnNames = [];
    foreach ($addOns as $addOn) {
      if (is_array($addOn) && trim((string)($addOn["name"] ?? "")) !== "") {
        $addOnNames[] = trim((string)$addOn["name"]);
      }
    }
    if ($addOnNames !== []) {
      $parts[] = "Add-ons: " . implode(", ", $addOnNames);
    }
  }

  if ($includeNotes && !count($parts) && ($notes = $value(["customer_notes_snapshot", "notes"])) !== "") {
    $parts[] = $notes;
  }

  if (!count($parts)) return "No extra details";
  return implode(" | ", array_slice($parts, 0, 4));
}

function qm_fetch_user_queue_items(PDO $pdo, int $userId, string $categoryKey, int $limit, bool $activeOnly): array {
  $limit = max(1, $limit);
  $meta = qm_category_meta($categoryKey);
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

  $stmt = $pdo->prepare($sql);
  $stmt->execute(array_merge([":user_id" => $userId], $meta["params"]));

  $items = [];
  foreach ($stmt->fetchAll() as $row) {
    $details = qm_parse_queue_details($row["details"] ?? null);
    $createdAt = trim((string)($row["created_at"] ?? ""));
    $status = strtoupper(trim((string)($row["status"] ?? "PENDING")));
    $serviceLabel = qm_normalize_service_label((string)($details["service_name_snapshot"] ?? ($details["service_label"] ?? "")), $meta["label"]);

    $items[] = [
      "queue_code" => trim((string)($row["queue_code"] ?? "")),
      "status" => $status,
      "status_label" => qm_format_status_label($status),
      "status_tone" => qm_status_tone($status),
      "category_label" => $meta["label"],
      "service_label" => $serviceLabel,
      "details_label" => qm_build_short_details($details),
      "created_at" => $createdAt,
      "created_at_label" => $createdAt !== "" ? date("M d, Y h:i A", strtotime($createdAt)) : "",
    ];
  }

  return $items;
}

function qm_fetch_latest_queue_items(PDO $pdo, string $categoryKey, int $limit): array {
  $limit = max(1, $limit);
  $meta = qm_category_meta($categoryKey);

  $sql = "
    SELECT q.queue_code, q.status, q.details, q.created_at
    FROM queues q
    WHERE {$meta['sql']}
    ORDER BY q.created_at DESC
    LIMIT {$limit}
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($meta["params"]);

  $items = [];
  foreach ($stmt->fetchAll() as $row) {
    $details = qm_parse_queue_details($row["details"] ?? null);
    $createdAt = trim((string)($row["created_at"] ?? ""));
    $status = strtoupper(trim((string)($row["status"] ?? "PENDING")));
    $serviceLabel = qm_normalize_service_label((string)($details["service_name_snapshot"] ?? ($details["service_label"] ?? "")), $meta["label"]);

    $items[] = [
      "queue_code" => trim((string)($row["queue_code"] ?? "")),
      "status" => $status,
      "status_label" => qm_format_status_label($status),
      "status_tone" => qm_status_tone($status),
      "category_label" => $meta["label"],
      "service_label" => $serviceLabel,
      "details_label" => qm_build_short_details($details, false),
      "created_at" => $createdAt,
      "created_at_label" => $createdAt !== "" ? date("M d, Y h:i A", strtotime($createdAt)) : "",
    ];
  }

  return $items;
}

$queueCategories = ["printing", "installation", "repair"];
$queueCategoryMeta = [];
$activeQueues = [];
$recentQueues = [];

foreach ($queueCategories as $categoryKey) {
  $meta = qm_category_meta($categoryKey);
  $queueCategoryMeta[$categoryKey] = $meta["label"];
  $activeQueues[$categoryKey] = qm_fetch_user_queue_items($pdo, $user_id, $categoryKey, 1, true);
  $recentQueues[$categoryKey] = qm_fetch_latest_queue_items($pdo, $categoryKey, 1);
}

$monitorQueues = [
  "active" => $activeQueues,
  "recent" => $recentQueues,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Queue Monitor</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260621-global-ui-polish">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260526status-badges">
  <style>
    body.customer-layout.customer-page--queue-monitor {
      overflow-x: hidden;
      background: #fff4e4 !important;
      background-color: #fff4e4 !important;
    }

    body.customer-layout.customer-page--queue-monitor .queue-monitor-page {
      width: min(100%, 1200px);
      margin: 0 auto;
      padding: 20px 20px 80px;
      box-sizing: border-box;
      background: #fff4e4;
    }

    body.customer-layout.customer-page--queue-monitor .queue-monitor-hero {
      display: grid;
      gap: 10px;
      margin: 30px 0 25px;
      padding: 40px 20px;
      border-radius: 16px;
      background:
        linear-gradient(135deg, rgba(255, 255, 255, 0.05), rgba(255, 255, 255, 0.05)),
        linear-gradient(135deg, #ff7a18, #ffb347);
      color: #ffffff;
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
      box-sizing: border-box;
    }

    body.customer-layout.customer-page--queue-monitor .queue-monitor-hero h1 {
      margin: 0;
      font-size: clamp(28px, 5vw, 42px);
      line-height: 1.1;
      text-shadow: 0 4px 12px rgba(0, 0, 0, 0.22);
    }

    body.customer-layout.customer-page--queue-monitor .queue-monitor-hero p {
      margin: 0;
      max-width: 720px;
      color: rgba(255, 255, 255, 0.94);
      font-size: 16px;
      line-height: 1.55;
    }

    body.customer-layout.customer-page--queue-monitor .queue-monitor-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 20px;
      align-items: stretch;
      width: 100%;
    }

    body.customer-layout.customer-page--queue-monitor .dashboard-card {
      min-width: 0;
      padding: 22px;
      border-radius: 16px;
      box-sizing: border-box;
      height: 100%;
    }

    body.customer-layout.customer-page--queue-monitor .dashboard-card h3 {
      margin: 0;
      color: #4A0505;
      font-size: 18px;
      line-height: 1.25;
      text-transform: uppercase;
    }

    body.customer-layout.customer-page--queue-monitor .divider {
      width: 100%;
      height: 2px;
      margin: 12px 0 12px;
      border-radius: 999px;
      background: #e8c77b;
    }

    .queue-carousel-card {
      overflow: hidden;
      height: 320px;
      min-height: 320px;
      border: 1px solid rgba(232, 199, 123, 0.30);
      display: flex;
      flex-direction: column;
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
      grid-template-rows: 52px 18px minmax(126px, 1fr);
      gap: 14px;
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
      align-items: stretch;
      min-height: 126px;
      height: 100%;
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
      height: 126px;
      min-height: 126px;
      display: grid;
      grid-template-rows: 34px 24px 20px;
      align-content: start;
      width: 100%;
      box-sizing: border-box;
    }

    .queue-carousel-card--latest .queue-item {
      border-color: #c8dcfb;
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
      color: #13274a;
      font-size: 22px;
      font-weight: 700;
      line-height: 1.1;
      word-break: break-word;
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
      padding: 6px 12px;
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      line-height: 1.2;
      border: 1px solid transparent;
      white-space: nowrap;
      box-sizing: border-box;
      text-align: center;
    }

    .queue-item__badge--pending { background: #fef3c7; color: #b45309; }
    .queue-item__badge--ongoing { background: #dbeafe; color: #1d4ed8; }
    .queue-item__badge--pickup { background: #ede9fe; color: #7c3aed; }
    .queue-item__badge--done { background: #dcfce7; color: #15803d; }
    .queue-item__badge--cancelled { background: #fee2e2; color: #b91c1c; }

    .queue-item__label {
      margin-top: 6px;
      min-height: 24px;
      color: #4A0505;
      font-size: 15px;
      font-weight: 700;
      display: flex;
      align-items: center;
      width: 100%;
      max-width: 100%;
      padding-right: 124px;
      box-sizing: border-box;
    }

    .queue-carousel-card--latest .queue-item__label {
      color: #163d73;
    }

    .queue-item__details {
      margin-top: 2px;
      color: #6b5a3b;
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

    .queue-carousel-card--latest .queue-item__details {
      color: #4f6485;
    }

    .queue-carousel__empty {
      display: flex;
      align-items: center;
      justify-content: center;
      height: 126px;
      min-height: 126px;
      border-radius: 14px;
      text-align: center;
      padding: 14px 16px;
      width: 100%;
      box-sizing: border-box;
      border: 1px dashed #e0c991;
      background: #fffaf0;
      color: #7a6b4f;
    }

    .queue-carousel-card--latest .queue-carousel__empty {
      border-color: #bfdbfe;
      background: #f8fbff;
      color: #4f6485;
    }

    @media (max-width: 900px) {
      body.customer-layout.customer-page--queue-monitor .queue-monitor-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 640px) {
      body.customer-layout.customer-page--queue-monitor .queue-monitor-page {
        padding: 16px 14px 56px;
      }

      body.customer-layout.customer-page--queue-monitor .queue-monitor-hero {
        margin-top: 20px;
        margin-bottom: 20px;
        padding: 28px 18px;
      }

      body.customer-layout.customer-page--queue-monitor .dashboard-card {
        padding: 18px 16px;
      }

      .queue-carousel-card {
        height: auto;
        min-height: 320px;
      }

      .queue-carousel {
        grid-template-rows: 44px 18px auto;
        gap: 12px;
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

      .queue-carousel__list {
        height: auto;
        min-height: 138px;
        padding: 0 4px;
      }

      .queue-item {
        height: auto;
        min-height: 138px;
        grid-template-rows: auto auto auto;
        padding: 12px 14px;
      }

      .queue-item__head {
        grid-template-columns: 1fr;
        row-gap: 8px;
        min-height: auto;
      }

      .queue-item__badge {
        width: auto;
        min-width: 0;
        justify-self: start;
        white-space: normal;
      }

      .queue-item__label,
      .queue-item__details {
        padding-right: 0;
      }
    }
  </style>
</head>
<body class="customer-layout customer-page--queue-monitor has-fixed-site-header">

<?php include __DIR__ . "/../../components/header.php"; ?>

<main class="queue-monitor-page">
  <section class="queue-monitor-hero">
    <h1>Queue Monitor</h1>
    <p>View your latest queue updates and the current queue being served.</p>
  </section>

  <section class="queue-monitor-grid" aria-label="Queue monitor widgets">
    <div class="dashboard-card queue-carousel-card queue-carousel-card--mine">
      <h3>YOUR QUEUE UPDATES</h3>
      <div class="divider"></div>

      <div class="queue-carousel">
        <div class="queue-carousel__topbar">
          <button type="button" class="queue-carousel__nav" id="activeQueuePrev" aria-label="Previous queue update category">&#9664;</button>
          <div class="queue-carousel__category" id="activeQueueCategory">PRINTING</div>
          <button type="button" class="queue-carousel__nav" id="activeQueueNext" aria-label="Next queue update category">&#9654;</button>
        </div>

        <div class="queue-carousel__dots" id="activeQueueDots" aria-label="Queue update category indicators"></div>
        <div class="queue-carousel__list" id="activeQueueList"></div>
      </div>
    </div>

    <div class="dashboard-card queue-carousel-card queue-carousel-card--latest">
      <h3>JC STORE CURRENTLY SERVING</h3>
      <div class="divider"></div>

      <div class="queue-carousel">
        <div class="queue-carousel__topbar">
          <button type="button" class="queue-carousel__nav" id="recentQueuePrev" aria-label="Previous now serving category">&#9664;</button>
          <div class="queue-carousel__category" id="recentQueueCategory">PRINTING</div>
          <button type="button" class="queue-carousel__nav" id="recentQueueNext" aria-label="Next now serving category">&#9654;</button>
        </div>

        <div class="queue-carousel__dots" id="recentQueueDots" aria-label="Now serving category indicators"></div>
        <div class="queue-carousel__list" id="recentQueueList"></div>
      </div>
    </div>
  </section>
</main>

<?php include __DIR__ . "/../../components/footer.php"; ?>

<script>
  const queueCategories = ["printing", "installation", "repair"];
  const queueCategoryMeta = <?php echo json_encode($queueCategoryMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  const queuePollIntervalMs = 5000;

  let monitorQueues = <?php echo json_encode($monitorQueues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
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
    const raw = String(value ?? "").trim().toLowerCase().replace(/[\s_]+/g, "-");
    if (["pending", "ongoing", "pickup", "done", "cancelled"].includes(raw)) return raw;
    if (raw === "for-pick-up" || raw === "for-pickup") return "pickup";
    if (raw === "canceled") return "cancelled";
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
    let sectionData = monitorQueues[mode] || {};

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
            <div class="status-badge queue-item__badge status-${tone} queue-item__badge--${tone}">${badge}</div>
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

  async function refreshMonitorQueues() {
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
        throw new Error(`Queue monitor refresh failed with status ${response.status}`);
      }

      const data = await response.json();
      monitorQueues = {
        active: data && typeof data.active === "object" ? data.active : {},
        recent: data && typeof data.recent === "object" ? data.recent : {}
      };

      activeCarousel.setData(monitorQueues.active);
      recentCarousel.setData(monitorQueues.recent);
    } catch (error) {
      console.warn("Queue monitor refresh failed:", error);
    }
  }

  window.setInterval(refreshMonitorQueues, queuePollIntervalMs);
</script>
</body>
</html>
