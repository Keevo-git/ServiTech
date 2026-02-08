\
<?php
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/db.php";

$user_id = (int)($_SESSION["user_id"] ?? 0);

$stmt = $pdo->prepare("SELECT fullname FROM users WHERE id = :id LIMIT 1");
$stmt->execute([":id" => $user_id]);
$user = $stmt->fetch();

$fullname = $user["fullname"] ?? "Customer";

function format_fullname($name) {
    $name = trim((string)$name);
    if ($name === "") return "Customer";
    $name = preg_replace('/\s+/', ' ', $name);
    $name = ucwords(strtolower($name));
    return $name;
}

$display_name = format_fullname($fullname);

// Active queue: latest not DONE/CANCELLED
$activeStmt = $pdo->prepare("
  SELECT queue_code, category, service_label, details, status
  FROM queues
  WHERE user_id = :uid
    AND status NOT IN ('DONE','CANCELLED')
  ORDER BY created_at DESC
  LIMIT 1
");
$activeStmt->execute([":uid" => $user_id]);
$active = $activeStmt->fetch();

$active_details = [];
if ($active && !empty($active["details"])) {
  $d = json_decode($active["details"], true);
  if (is_array($d)) $active_details = $d;
}

// "On-going services" count: everything not DONE/CANCELLED
$countStmt = $pdo->prepare("
  SELECT COUNT(*) AS c
  FROM queues
  WHERE user_id = :uid
    AND status NOT IN ('DONE','CANCELLED')
");
$countStmt->execute([":uid" => $user_id]);
$ongoingCount = (int)($countStmt->fetch()["c"] ?? 0);

// Build a short details line for the dashboard card
function build_details_line(array $d): string {
  $parts = [];
  if (!empty($d["paper_size"])) $parts[] = $d["paper_size"];
  if (!empty($d["quantity"])) $parts[] = "Qty: " . $d["quantity"];
  if (!empty($d["color_option"])) $parts[] = $d["color_option"];
  if (!empty($d["package_label"])) $parts[] = $d["package_label"];
  if (!empty($d["lamination_type"])) $parts[] = "Lam: " . $d["lamination_type"];
  if (!empty($d["device_type"])) $parts[] = $d["device_type"];
  return implode(" • ", $parts);
}

$queueNo = $active["queue_code"] ?? "#---";
$queueStatus = $active["status"] ?? "PENDING";
$queueService = $active["service_label"] ?? "---";
$queueDetails = $active ? build_details_line($active_details) : "---";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Customer Dashboard</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

<?php include "includes/header.php"; ?>

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
      <span class="status pending" id="queueStatus"><?php echo htmlspecialchars($queueStatus); ?></span>
    </div>

    <p id="queueService">Service: <?php echo htmlspecialchars($queueService); ?></p>
    <p id="queueDetails">Details: <?php echo htmlspecialchars($queueDetails); ?></p>

    <?php if (!$active): ?>
      <p id="noQueueMsg" style="color:#888; margin-top:10px;">
        You have no active queue.
      </p>
    <?php endif; ?>
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
    <a href="custo_place_queueing.php" class="quick-card-link">
      <div class="quick-card">
        <div class="quick-icon-box">
          <img src="./IMAGES/LANDING_QUEUEING.png" alt="Join Queue" class="quick-icon">
        </div>
        <h4>Join Queue</h4>
        <p>Join the line to place your request.</p>
      </div>
    </a>

    <a href="custo_service_status.php" class="quick-card-link">
      <div class="quick-card">
        <div class="quick-icon-box">
          <img src="./IMAGES/LANDING_SERVICE-STAT.png" alt="Service Status" class="quick-icon">
        </div>
        <h4>Service Status</h4>
        <p>Check your requested service status or your queue status.</p>
      </div>
    </a>

    <a href="custo_print_order.php" class="quick-card-link">
      <div class="quick-card">
        <div class="quick-icon-box">
          <img src="./IMAGES/LANDING_PRINT-ORD.png" alt="Print Order" class="quick-icon">
        </div>
        <h4>Print Order</h4>
        <p>Place an order to print your document.</p>
      </div>
    </a>

    <a href="custo_edit_profile.php" class="quick-card-link">
      <div class="quick-card">
        <div class="quick-icon-box">
          <img src="./IMAGES/ICON_EDIT_PROF.png" alt="Edit Profile" class="quick-icon">
        </div>
        <h4>Edit Profile</h4>
        <p>Edit your personal information.</p>
      </div>
    </a>
  </div>
</section>

<?php include "includes/footer.php"; ?>

</body>
</html>
