<?php
require_once __DIR__ . "/includes/auth.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Repair Option</title>
  <link rel="stylesheet" href="style.css">
</head>
<body data-service="repair">

<?php include "includes/header.php"; ?>

<section class="form-page">

  <h2 class="page-title">JC REPAIR SERVICES</h2>
  <p class="page-subtitle">Place your repair order below.</p>

  <div class="form-card">
    <h3 class="step-title">1. SERVICE AND DETAILS</h3>

    <div class="form-grid">
      <div>
        <label>Select Device Type<span class="required">*</span></label>
        <select class="form-select" id="deviceTypeSelect">
          <option value="" selected disabled>Select Device</option>
          <option>Mobile Phone / Tablet</option>
          <option>Laptop</option>
          <option>Desktop</option>
        </select><br><br>

        <label>Select Service<span class="required">*</span></label>
        <select class="form-select" id="repairServiceSelect">
          <option value="" selected disabled>Select Repair Service</option>
          <option data-min="1200" data-max="5500">LCD Replacement — (₱1200 – ₱5500)</option>
          <option data-min="700" data-max="2500">Battery Replacement — (₱700 – ₱2500)</option>
          <option data-min="800" data-max="4000">Charging Pin Replacement — (₱800 – ₱4000)</option>
          <option data-min="700" data-max="1500">Speaker / Mouthpiece Replacement — (₱700 – ₱1500)</option>
          <option data-min="500" data-max="2000">Power Button Repair — (₱500 – ₱2000)</option>
          <option data-min="1000" data-max="2000">Volume Repair — (₱1000 – ₱2000)</option>
          <option data-min="1500" data-max="5000">Part(s) Upgrade — (₱1500 – ₱5000)</option>
        </select><br><br>

        <label>Additional Information/Other Request:</label>
        <textarea class="form-textarea" id="repairNotes"></textarea>
      </div>

      <div>
        <p style="font-size:13px;color:#555;margin-top:12px;">Provide as much detail as possible to help our technicians.</p>
      </div>

    </div>
  </div>

  <div class="form-actions">
    <a href="custo_place_queueing.php" class="btn-back">Back</a>
    <a href="#" class="btn-next" id="joinQueueBtn">Join Queue</a>
  </div>

</section>

<?php include "includes/footer.php"; ?>
<?php include "includes/queue_modal.php"; ?>

<script src="main.js"></script>

</body>
</html>
