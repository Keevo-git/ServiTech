\
<?php
require_once __DIR__ . "/includes/auth.php";
$queue = $_GET["queue"] ?? "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Print Order Confirmation</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

<?php include "includes/header.php"; ?>

<section class="form-page">
  <h2 class="page-title">PRINT ORDER CONFIRMATION</h2>

  <div class="form-card">
    <h3 class="step-title">You're in the queue!</h3>
    <p>Your print order has been saved.</p>
    <p><strong>Queue Code:</strong> <?php echo htmlspecialchars($queue ?: "—"); ?></p>

    <div class="form-actions" style="margin-top:18px;">
      <a href="customer_dash.php" class="btn-back">Back to Dashboard</a>
      <a href="custo_service_status.php" class="btn-next">View Status</a>
    </div>
  </div>
</section>

<?php include "includes/footer.php"; ?>

</body>
</html>
