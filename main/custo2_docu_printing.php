<?php
require_once __DIR__ . "/includes/auth.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Document Printing</title>
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
        <p class="static-text">Document Printing</p><br>

        <label>Paper Size<span class="required">*</span></label>
        <select class="form-select" id="paperSizeSelect">
          <option selected disabled>Select paper size</option>
          <option>Short Bond (8.5 x 11)</option>
          <option>Long Bond (8.5 x 13)</option>
          <option>A4</option>
          <option>A3</option>
        </select><br><br>

        <label>Quantity / Copies<span class="required">*</span></label>
        <input type="number" min="1" value="1" class="form-input" id="qtyInput">

        <label>Additional Instructions / Edit Request</label>
        <textarea class="form-textarea" id="notes"></textarea>
      </div>

      <div>
        <label>Color Option<span class="required">*</span></label>
        <div class="radio-group">
          <label><input type="radio" name="color" value="Black & White"> Black & White</label>
          <label><input type="radio" name="color" value="Colored - Full"> Colored (Full)</label>
          <label><input type="radio" name="color" value="Colored - Half"> Colored (Half)</label>
          <label><input type="radio" name="color" value="Colored - N/A"> Colored (N/A)</label>
        </div>
      </div>

    </div>
  </div><br>

  <div class="form-card">
    <h3 class="step-title">3. UPLOAD FILES</h3>

    <label for="fileUpload">Upload your document</label>
    <input type="file" id="fileUpload" class="form-file">

    <p class="file-note">Accepted formats: PDF, DOC, DOCX, JPG, PNG</p>
  </div>

  <div class="summary-card">
    <h3 class="summary-title">ORDER SUMMARY</h3>

    <div class="summary-row">
      <span>SERVICE:</span>
      <strong>DOCUMENT PRINTING</strong>
    </div>

    <div class="summary-row">
      <span>PAPER SIZE:</span>
      <strong id="summaryPaperSize">Not Selected</strong>
    </div>

    <div class="summary-row">
      <span>QUANTITY:</span>
      <strong id="summaryQty">1</strong>
    </div>

    <div class="summary-divider"></div>

    <div class="summary-total">
      <span>Estimated Total:</span>
      <strong id="summaryTotal">₱05.00</strong>
    </div>
  </div>

  <div class="form-actions">
    <a href="custo1_printing_option.php" class="btn-back">Back</a>
    <a href="#" class="btn-next" id="joinQueueBtn">Join Queue</a>
  </div>

</section>

<?php include "includes/footer.php"; ?>
<?php include "includes/queue_modal.php"; ?>


<?php require_once __DIR__ . "/includes/footer.php"; ?>

<!-- DEBUG: modal include marker -->
<?php
  require_once __DIR__ . "/includes/queue_modal.php";
  echo "<!-- queue_modal.php INCLUDED OK -->";
?>

<script src="main.js"></script>
</body>
</html>

