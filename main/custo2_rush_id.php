<?php
require_once __DIR__ . "/includes/auth.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Rush ID</title>
  <link rel="stylesheet" href="style.css">
</head>
<body data-service="printing">

<?php include "includes/header.php"; ?>

<section class="form-page">

  <h2 class="page-title">JC PRINTING SERVICES</h2>
  <p class="page-subtitle">Place your print, copy, or ID photo order below.</p>

  <div class="form-card">
    <h3 class="step-title">2. ENTER DETAILS</h3>

    <div class="form-grid">
      <div>
        <label>Service Type<span class="required">*</span></label>
        <p class="static-text">Rush ID</p><br>

        <label>Select Package<span class="required">*</span></label>
        <select id="packageSelect" class="form-select">
          <option value="" selected disabled>Select a Package</option>
          <option data-price="40">Package 1: 1x1 (4pcs.), 2x2 (2pcs.) — ₱40</option>
          <option data-price="30">Package 2: 1x1 (6pcs.) — ₱30</option>
          <option data-price="30">Package 3: 2x2 (4pcs.) — ₱30</option>
          <option data-price="50">Package 4: 2x2 (4pcs.), 1x1 (4pcs.) — ₱50</option>
          <option data-price="30">Package 5: Passport size (4pcs.) — ₱30</option>
          <option data-price="50">Package 6: 1x1 (10pcs.) — ₱50</option>
        </select>

        <div class="two-col-fields">
          <div>
            <label>Additional Edit 1</label>
            <p class="field-hint">Provide the name in the additional instructions box</p>
            <div class="radio-vertical">
              <label><input type="radio" name="edit1"> With name</label>
              <label><input type="radio" name="edit1"> With no name</label>
            </div>
          </div>

          <div>
            <label>Additional Edit 2</label>
            <p class="field-hint">Staffs will edit your picture to be in formal attire</p>
            <div class="radio-vertical">
              <label><input type="radio" name="edit2"> Formal Attire</label>
              <label><input type="radio" name="edit2"> No Formal Attire</label>
            </div>
          </div>
        </div>

        <label>Additional Instructions / Edit Request</label>
        <textarea class="form-textarea" id="notes"></textarea>
      </div>

      <div>
        <label>Quantity / Copies<span class="required">*</span></label>
        <input type="number" min="1" value="1" id="qtyInput" class="form-input">
      </div>
    </div>
  </div>

  <div class="summary-card">
    <h3 class="summary-title">ORDER SUMMARY</h3>

    <div class="summary-row">
      <span>SERVICE:</span>
      <strong>RUSH ID</strong>
    </div>

    <div class="summary-row">
      <span>PACKAGE:</span>
      <strong id="summaryPackage">Not Selected</strong>
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
