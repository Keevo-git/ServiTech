<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/db.php";

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

// Ongoing only (matches ON-GOING SERVICE(S) card label)
$stmt = $pdo->prepare("
  SELECT COUNT(*) AS cnt
  FROM queues
  WHERE user_id = :uid
    AND status = 'ONGOING'
");
$stmt->execute([":uid" => $user_id]);
$ongoingCount = (int)($stmt->fetch()["cnt"] ?? 0);

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Customer Dashboard</title>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260315h3">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260315h3">
</head>
<body class="customer-layout customer-page--dashboard">

<?php include __DIR__ . "/../../components/header.php"; ?>

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
    <h3>ON-GOING SERVICE(S)</h3>
    <div class="divider"></div>
    <h1 id="ongoingCount"><?php echo str_pad((string)$ongoingCount, 2, "0", STR_PAD_LEFT); ?></h1>
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

</body>
</html>

