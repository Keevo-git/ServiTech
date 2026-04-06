<?php
require_once __DIR__ . "/../components/auth_guard.php";
require_once __DIR__ . "/../config/db.php";

$user_id = (int)($_SESSION["user_id"] ?? 0);

// Fetch user display name
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

// Active queue: latest queue that is still in progress
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

// Parse details (jsonb) safely
$activeDetails = [];
if ($activeQueue && isset($activeQueue["details"])) {
  if (is_array($activeQueue["details"])) {
    $activeDetails = $activeQueue["details"];
  } elseif (is_string($activeQueue["details"]) && $activeQueue["details"] !== "") {
    $d = json_decode($activeQueue["details"], true);
    if (is_array($d)) $activeDetails = $d;
  }
}

function fetch_last_queue_code(PDO $pdo, string $whereSql, array $params = []): string {
  $stmt = $pdo->prepare("
    SELECT queue_code
    FROM queues
    WHERE {$whereSql}
    ORDER BY id DESC
    LIMIT 1
  ");
  $stmt->execute($params);
  $row = $stmt->fetch();
  return trim((string)($row["queue_code"] ?? "")) ?: "---";
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

$display_name = format_fullname($fullname);
$hasQueue = !empty($activeQueue);
$queueNo = $hasQueue ? ($activeQueue["queue_code"] ?? "#---") : "#---";
$queueStatus = $hasQueue ? strtoupper($activeQueue["status"] ?? "PENDING") : "PENDING";
$queueService = $hasQueue ? ($activeDetails["service_label"] ?? "---") : "---";
$queueDetails = $hasQueue ? build_details_line($activeDetails) : "---";
$latestQueueNumbers = [
  "Printing" => fetch_last_queue_code(
    $pdo,
    "category = :category
     AND COALESCE(details->>'service_label', '') <> :online_label",
    [
      ":category" => "printing",
      ":online_label" => "Online Print Order",
    ]
  ),
  "Repair" => fetch_last_queue_code(
    $pdo,
    "category = :category",
    [":category" => "repair"]
  ),
  "Installation" => fetch_last_queue_code(
    $pdo,
    "category = :category",
    [":category" => "installation"]
  ),
  "Online Print Order" => fetch_last_queue_code(
    $pdo,
    "category = :category
     AND COALESCE(details->>'service_label', '') = :online_label",
    [
      ":category" => "printing",
      ":online_label" => "Online Print Order",
    ]
  ),
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
    .queue-summary-list {
      display: grid;
      gap: 12px;
    }

    .queue-summary-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      padding: 10px 0;
      border-bottom: 1px solid #f1e2c2;
    }

    .queue-summary-row:last-child {
      border-bottom: 0;
      padding-bottom: 0;
    }

    .queue-summary-label {
      color: #4A0505;
      font-weight: 600;
      line-height: 1.4;
    }

    .queue-summary-code {
      color: #13274a;
      font-size: 22px;
      font-weight: 700;
      white-space: nowrap;
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

  <div class="dashboard-card">
    <h3>LAST QUEUE NUMBER(S)</h3>
    <div class="divider"></div>
    <div class="queue-summary-list">
      <?php foreach ($latestQueueNumbers as $label => $code): ?>
        <div class="queue-summary-row">
          <span class="queue-summary-label"><?php echo htmlspecialchars($label); ?></span>
          <span class="queue-summary-code"><?php echo htmlspecialchars($code); ?></span>
        </div>
      <?php endforeach; ?>
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

</body>
</html>

