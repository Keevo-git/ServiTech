<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/db.php";

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

function parse_now_serving_details($details): array {
  if (is_array($details)) return $details;
  if (is_string($details) && trim($details) !== "") {
    $decoded = json_decode($details, true);
    if (is_array($decoded)) return $decoded;
  }
  return [];
}

function format_now_serving_status_label($status): string {
  $status = strtoupper(trim((string)$status));
  if ($status === "FOR PICK-UP") return "For Pick-up";
  return ucwords(strtolower($status));
}

function now_serving_status_tone($status): string {
  $status = strtoupper(trim((string)$status));
  if ($status === "ONGOING") return "ongoing";
  if ($status === "FOR PICK-UP") return "pickup";
  if ($status === "DONE") return "done";
  if ($status === "CANCELLED") return "cancelled";
  return "pending";
}

function now_serving_category_meta(string $categoryKey): array {
  return match ($categoryKey) {
    "online_print" => [
      "label" => "Online Print Order",
      "sql" => "q.category = :category_online_printorder",
      "params" => [":category_online_printorder" => "online_printorder"],
    ],
    "printing" => [
      "label" => "Printing",
      "sql" => "q.category = :category_printing",
      "params" => [":category_printing" => "printing"],
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

function normalize_now_serving_label(string $serviceLabel, string $fallbackLabel): string {
  $serviceLabel = trim($serviceLabel);
  if ($serviceLabel === "") return $fallbackLabel;
  if (strcasecmp($serviceLabel, "Online Print Order") === 0) return "Online Print Order";
  return $serviceLabel;
}

function build_now_serving_details(array $details): string {
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

  if (!count($parts)) return "No extra details";

  return implode(" | ", array_slice($parts, 0, 3));
}

function fetch_now_serving_items(PDO $pdo, string $categoryKey, int $limit = 1): array {
  $limit = max(1, $limit);
  $meta = now_serving_category_meta($categoryKey);

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
    $details = parse_now_serving_details($row["details"] ?? null);
    $createdAt = trim((string)($row["created_at"] ?? ""));
    $status = strtoupper(trim((string)($row["status"] ?? "PENDING")));
    $serviceLabel = normalize_now_serving_label((string)($details["service_label"] ?? ""), $meta["label"]);

    $items[] = [
      "queue_code" => trim((string)($row["queue_code"] ?? "")),
      "status" => $status,
      "status_label" => format_now_serving_status_label($status),
      "status_tone" => now_serving_status_tone($status),
      "category_label" => $meta["label"],
      "service_label" => $serviceLabel,
      "details_label" => build_now_serving_details($details),
      "created_at" => $createdAt,
      "created_at_label" => $createdAt !== "" ? date("M d, Y h:i A", strtotime($createdAt)) : "",
    ];
  }

  return $items;
}

function h_now_serving($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$queueCategories = ["online_print", "printing", "installation", "repair"];
$categoryLabels = [];
$nowServingItems = [];

foreach ($queueCategories as $categoryKey) {
  $meta = now_serving_category_meta($categoryKey);
  $categoryLabels[$categoryKey] = $meta["label"];
  $nowServingItems[$categoryKey] = fetch_now_serving_items($pdo, $categoryKey, 1);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Now Serving</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png">
  <link rel="stylesheet" href="/assets/css/style.css?v=20260526status-badges">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260526status-badges">
  <style>
    body.customer-layout.customer-page--now-serving {
      overflow-x: hidden;
    }

    body.customer-layout.customer-page--now-serving .now-serving-page {
      width: min(100%, 1120px);
      margin: 0 auto;
      padding: clamp(24px, 5vw, 48px) 20px 64px;
      box-sizing: border-box;
    }

    body.customer-layout.customer-page--now-serving .now-serving-hero {
      display: grid;
      gap: 10px;
      margin-bottom: 24px;
      padding: clamp(24px, 4vw, 34px);
      border-radius: 18px;
      background: linear-gradient(135deg, #ff7a18, #ffb347);
      color: #ffffff;
      box-shadow: 0 18px 34px rgba(74, 5, 5, 0.16);
      box-sizing: border-box;
    }

    body.customer-layout.customer-page--now-serving .now-serving-hero__back {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: fit-content;
      min-height: 42px;
      padding: 9px 14px;
      border-radius: 12px;
      background: rgba(255, 255, 255, 0.18);
      color: #ffffff;
      text-decoration: none;
      font-weight: 800;
      line-height: 1.2;
    }

    body.customer-layout.customer-page--now-serving .now-serving-hero h1 {
      margin: 0;
      font-size: clamp(28px, 5vw, 42px);
      line-height: 1.1;
      text-shadow: 0 4px 12px rgba(0, 0, 0, 0.22);
    }

    body.customer-layout.customer-page--now-serving .now-serving-hero p {
      margin: 0;
      max-width: 680px;
      color: rgba(255, 255, 255, 0.92);
      font-size: 16px;
      line-height: 1.55;
    }

    body.customer-layout.customer-page--now-serving .now-serving-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 20px;
      width: 100%;
    }

    body.customer-layout.customer-page--now-serving .now-serving-card {
      min-width: 0;
      padding: 22px;
      border: 1px solid rgba(175, 108, 9, 0.16);
      border-radius: 18px;
      background: linear-gradient(180deg, #ffffff 0%, #fff9ef 100%);
      box-shadow: 0 12px 28px rgba(74, 5, 5, 0.09);
      box-sizing: border-box;
    }

    body.customer-layout.customer-page--now-serving .now-serving-card__header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      margin-bottom: 16px;
    }

    body.customer-layout.customer-page--now-serving .now-serving-card h2 {
      margin: 0;
      color: #4A0505;
      font-size: 20px;
      line-height: 1.25;
    }

    body.customer-layout.customer-page--now-serving .now-serving-card__time {
      color: #7a5b38;
      font-size: 12px;
      font-weight: 800;
      text-transform: uppercase;
      white-space: nowrap;
    }

    body.customer-layout.customer-page--now-serving .now-serving-item {
      display: grid;
      gap: 10px;
      padding: 18px;
      border: 1px solid #ead6ad;
      border-radius: 16px;
      background: #fffaf0;
    }

    body.customer-layout.customer-page--now-serving .now-serving-item__top {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
    }

    body.customer-layout.customer-page--now-serving .now-serving-code {
      color: #13274a;
      font-size: clamp(24px, 5vw, 34px);
      font-weight: 900;
      line-height: 1.1;
      overflow-wrap: anywhere;
    }

    body.customer-layout.customer-page--now-serving .now-serving-service {
      color: #4A0505;
      font-size: 16px;
      font-weight: 800;
      line-height: 1.35;
    }

    body.customer-layout.customer-page--now-serving .now-serving-details,
    body.customer-layout.customer-page--now-serving .now-serving-empty {
      color: #6b5a4a;
      font-size: 14px;
      line-height: 1.5;
    }

    body.customer-layout.customer-page--now-serving .now-serving-empty {
      padding: 24px 18px;
      border: 1px dashed #e0c991;
      border-radius: 16px;
      background: #fffaf0;
      text-align: center;
    }

    @media (max-width: 760px) {
      body.customer-layout.customer-page--now-serving .now-serving-grid {
        grid-template-columns: 1fr;
      }

      body.customer-layout.customer-page--now-serving .now-serving-card__header,
      body.customer-layout.customer-page--now-serving .now-serving-item__top {
        flex-direction: column;
        align-items: flex-start;
      }

      body.customer-layout.customer-page--now-serving .now-serving-card__time {
        white-space: normal;
      }
    }

    @media (max-width: 420px) {
      body.customer-layout.customer-page--now-serving .now-serving-page {
        padding-inline: 14px;
      }

      body.customer-layout.customer-page--now-serving .now-serving-card,
      body.customer-layout.customer-page--now-serving .now-serving-hero {
        padding: 18px;
      }
    }
  </style>
</head>
<body class="customer-layout customer-page--now-serving">

<?php include __DIR__ . "/../../components/header.php"; ?>

<main class="now-serving-page">
  <section class="now-serving-hero">
    <a href="/pages/customer/customer_dash.php" class="now-serving-hero__back">Back to Dashboard</a>
    <h1>Now Serving</h1>
    <p>View the latest queue being served or processed for each service category.</p>
  </section>

  <section class="now-serving-grid" id="nowServingGrid" aria-label="Now serving by category">
    <?php foreach ($queueCategories as $categoryKey): ?>
      <?php
        $categoryItems = $nowServingItems[$categoryKey] ?? [];
        $item = $categoryItems[0] ?? null;
      ?>
      <article class="now-serving-card" data-category="<?= h_now_serving($categoryKey) ?>">
        <div class="now-serving-card__header">
          <h2><?= h_now_serving($categoryLabels[$categoryKey] ?? $categoryKey) ?></h2>
          <?php if ($item && !empty($item["created_at_label"])): ?>
            <span class="now-serving-card__time"><?= h_now_serving($item["created_at_label"]) ?></span>
          <?php endif; ?>
        </div>

        <?php if ($item): ?>
          <?php
            $queueCode = trim((string)($item["queue_code"] ?? ""));
            $queueCode = $queueCode !== "" && str_starts_with($queueCode, "#") ? $queueCode : "#" . ($queueCode !== "" ? $queueCode : "---");
            $tone = preg_replace('/[^a-z0-9_-]/i', '', (string)($item["status_tone"] ?? "pending"));
          ?>
          <div class="now-serving-item">
            <div class="now-serving-item__top">
              <div class="now-serving-code"><?= h_now_serving($queueCode) ?></div>
              <div class="status-badge status-<?= h_now_serving($tone) ?>"><?= h_now_serving($item["status_label"] ?? "Pending") ?></div>
            </div>
            <div class="now-serving-service"><?= h_now_serving($item["service_label"] ?? ($categoryLabels[$categoryKey] ?? $categoryKey)) ?></div>
            <div class="now-serving-details"><?= h_now_serving($item["details_label"] ?? "No extra details") ?></div>
          </div>
        <?php else: ?>
          <div class="now-serving-empty">No queue is available in this category yet.</div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </section>
</main>

<?php include __DIR__ . "/../../components/footer.php"; ?>

<script>
  const nowServingGrid = document.getElementById("nowServingGrid");
  const nowServingCategories = <?php echo json_encode($queueCategories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  const nowServingLabels = <?php echo json_encode($categoryLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

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

  function buildNowServingCard(categoryKey, item) {
    const categoryLabel = nowServingLabels[categoryKey] || categoryKey;
    const timeMarkup = item && item.created_at_label
      ? `<span class="now-serving-card__time">${escapeHtml(item.created_at_label)}</span>`
      : "";

    let bodyMarkup = `<div class="now-serving-empty">No queue is available in this category yet.</div>`;

    if (item) {
      const tone = normalizeStatusTone(item.status_tone || item.status);
      bodyMarkup = `
        <div class="now-serving-item">
          <div class="now-serving-item__top">
            <div class="now-serving-code">${escapeHtml(formatQueueCode(item.queue_code))}</div>
            <div class="status-badge status-${tone}">${escapeHtml(item.status_label || "Pending")}</div>
          </div>
          <div class="now-serving-service">${escapeHtml(item.service_label || categoryLabel)}</div>
          <div class="now-serving-details">${escapeHtml(item.details_label || "No extra details")}</div>
        </div>
      `;
    }

    return `
      <article class="now-serving-card" data-category="${escapeHtml(categoryKey)}">
        <div class="now-serving-card__header">
          <h2>${escapeHtml(categoryLabel)}</h2>
          ${timeMarkup}
        </div>
        ${bodyMarkup}
      </article>
    `;
  }

  async function refreshNowServing() {
    try {
      const response = await fetch(`${servitechUrl("/pages/customer/get_latest_queues.php")}?t=${Date.now()}`, {
        cache: "no-store",
        credentials: "same-origin",
        headers: {
          "Accept": "application/json",
          "X-Requested-With": "XMLHttpRequest"
        }
      });

      if (!response.ok) return;

      const data = await response.json();
      const recent = data && typeof data.recent === "object" ? data.recent : {};
      nowServingGrid.innerHTML = nowServingCategories.map((categoryKey) => {
        const items = Array.isArray(recent[categoryKey]) ? recent[categoryKey] : [];
        return buildNowServingCard(categoryKey, items[0] || null);
      }).join("");
    } catch (error) {
      console.warn("Now serving refresh failed:", error);
    }
  }

  window.setInterval(refreshNowServing, 5000);
</script>
</body>
</html>
