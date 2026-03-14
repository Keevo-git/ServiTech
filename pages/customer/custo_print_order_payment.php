<?php
require_once __DIR__ . "/../../components/auth_guard.php";
$queue = $_GET["queue"] ?? "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Print Order Confirmation</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css">
</head>
<body class="customer-layout customer-page--print-order">

<?php include __DIR__ . "/../../components/header.php"; ?>

<section class="form-page form-page--confirmation">
  <div class="form-page-shell">
    <div class="form-page-intro">
      <h2 class="page-title">PRINT ORDER CONFIRMATION</h2>
    </div>

    <div class="form-card confirmation-card">
      <h3 class="step-title">You're in the queue!</h3>
      <p>Your print order has been saved.</p>
      <p><strong>Queue Code:</strong> <?php echo htmlspecialchars($queue ?: "-"); ?></p>

      <div class="form-actions form-actions--compact">
        <a href="/pages/customer/customer_dash.php" class="btn-back">Back to Dashboard</a>
        <a href="/pages/customer/custo_service_status.php" class="btn-next">View Status</a>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__ . "/../../components/footer.php"; ?>

</body>
</html>
