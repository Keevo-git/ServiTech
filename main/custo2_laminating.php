<?php
require_once __DIR__ . "/includes/auth.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Laminating</title>
  <link rel="stylesheet" href="style.css">
</head>
<body data-service="printing" data-price-per-page="20">

<?php include "includes/header.php"; ?>

<section class="form-page">

  <h2 class="page-title">JC PRINTING SERVICES</h2>
  <p class="page-subtitle">Place your print, copy, or ID photo order below.</p>

  <div class="form-card">
    <h3 class="step-title">2. ENTER DETAILS</h3>

    <div class="form-grid">

      <div>
        <label>Service Type<span class="required">*</span></label>
        <p class="static-text">Laminating</p><br>

        <label>Lamination Type<span class="required">*</span></label>
        <select class="form-select" id="lamTypeSelect">
          <option value="" selected disabled>Select lamination type</option>
          <option value="thin" data-price="20">Thin (Manipis)</option>
          <option value="thick" data-price="30">Thick (Makapal)</option>
        </select><br><br>

        <label>Additional Instructions / Edit Request</label>
        <textarea class="form-textarea" id="notes"></textarea>
      </div>

      <div>
        <label>Quantity / Copies<span class="required">*</span></label>
        <input type="number" min="1" value="1" class="form-input" id="qtyInput">
        <p style="font-size:13px;color:#555;margin-top:12px;">Note: Laminating price shown is an estimate per item.</p>
      </div>

    </div>
  </div><br>

  <div class="summary-card">
    <h3 class="summary-title">ORDER SUMMARY</h3>

    <div class="summary-row">
      <span>SERVICE:</span>
      <strong>LAMINATING</strong>
    </div>

    <div class="summary-row">
      <span>QUANTITY:</span>
      <strong id="summaryQty">1</strong>
    </div>

    <div class="summary-divider"></div>

    <div class="summary-total">
      <span>Estimated Total:</span>
      <strong id="summaryTotal">₱0.00</strong>
    </div>
  </div>

  <div class="form-actions">
    <a href="custo1_printing_option.php" class="btn-back">Back</a>
    <a href="#" class="btn-next" id="joinQueueBtn">Join Queue</a>
  </div>

</section>

<?php include "includes/footer.php"; ?>
<?php include "includes/queue_modal.php"; ?>

<script src="main.js"></script>

</body>
</html>
