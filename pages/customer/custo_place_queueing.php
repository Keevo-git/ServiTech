<?php
require_once __DIR__ . "/../../components/auth_guard.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Place Queueing Customer</title>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260315h2">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260315h2">
</head>
<body class="customer-layout customer-page--queue">

<?php include __DIR__ . "/../../components/header.php"; ?>

<section class="choose-service">
  <h2>CHOOSE A SERVICE</h2>

  <div class="choose-grid">
    <a href="/pages/customer/custo1_printing_option.php" class="choose-card">
      <img src="/assets/images/CARD_PRINTING.png" alt="Printing">
      <span>PRINTING</span>
    </a>

    <a href="/pages/customer/custo1_repair_option.php" class="choose-card">
      <img src="/assets/images/CARD_REPAIR.png" alt="Repair">
      <span>REPAIR</span>
    </a>

    <a href="/pages/customer/custo1_installation_option.php" class="choose-card">
      <img src="/assets/images/CARD_INSTALLATION.png" alt="Installation">
      <span>INSTALLATION</span>
    </a>
  </div>
</section>

<?php include __DIR__ . "/../../components/footer.php"; ?>

</body>
</html>

