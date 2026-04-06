<?php
require_once __DIR__ . "/../../components/auth_guard.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Document Printing</title>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260315h9">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260315h15">
  <style>
    #fileAnalysisList {
      list-style: none;
      margin: 0.75rem 0 0;
      padding: 0;
    }

    #fileAnalysisList li {
      align-items: center;
      display: flex;
      gap: 0.75rem;
      justify-content: space-between;
      margin-bottom: 0.5rem;
    }

    #fileAnalysisList li span {
      flex: 1;
      min-width: 0;
      word-break: break-word;
    }

    #fileAnalysisList button {
      background: #fff7ed;
      border: 1px solid #f08a00;
      border-radius: 999px;
      color: #d9480f;
      cursor: pointer;
      flex-shrink: 0;
      font-size: 0.8rem;
      font-weight: 700;
      padding: 0.3rem 0.75rem;
    }

    #fileAnalysisList button:hover {
      background: #ffedd5;
    }

    #paymentSection[hidden],
    #cashPaymentNote[hidden] {
      display: none !important;
    }

    .payment-section {
      margin-top: 1rem;
      padding-top: 1rem;
    }

    .payment-section__hint {
      color: #5f5f5f;
      font-size: 0.95rem;
      margin: 0.5rem 0 0;
    }

    .queue-success-modal {
      width: min(100%, 420px);
    }

    .queue-success-modal__actions {
      align-items: stretch !important;
      display: grid !important;
      gap: 12px !important;
      grid-template-columns: 1fr;
    }

    .queue-success-modal__actions > * {
      flex: 0 0 auto !important;
      min-height: 48px;
      width: 100% !important;
    }

    @media (min-width: 768px) {
      .queue-success-modal__actions {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }
  </style>
</head>
<body class="customer-layout customer-page--forms customer-page--custo2" data-service="printing">

<?php include __DIR__ . "/../../components/header.php"; ?>

<section class="form-page form-page--single">
  <div class="form-page-shell">
    <div class="form-page-intro">
      <h2 class="page-title">JC PRINTING SERVICES</h2>
      <p class="page-subtitle">Place your print, copy, or ID photo order below.</p>
    </div>

    <div class="form-content-stack">
      <div class="form-card form-card--primary">
        <h3 class="step-title">2. ENTER DETAILS</h3>

        <div class="form-grid">
          <div>
            <label>Service Type<span class="required">*</span></label>
            <p class="static-text">Document Printing</p>

            <label for="orderTypeSelect">Order Type<span class="required">*</span></label>
            <select class="form-select" id="orderTypeSelect">
              <option value="" selected>Select order type</option>
              <option value="walkin">Walk-in</option>
              <option value="online">Online Print Order</option>
            </select>

            <div id="paymentSection" class="payment-section" hidden>
              <label for="paymentMethodSelect">Payment Method<span class="required">*</span></label>
              <select class="form-select" id="paymentMethodSelect">
                <option value="" selected>Select payment method</option>
                <option value="cash">Cash</option>
                <option value="gcash">GCash</option>
              </select>
              <p id="cashPaymentNote" class="payment-section__hint" hidden>You must go to the store to complete payment before printing.</p>
            </div>

            <label for="paperSizeSelect">Paper Size<span class="required">*</span></label>
            <select class="form-select" id="paperSizeSelect">
              <option value="" selected>Select paper size</option>
              <option>Short Bond (8.5 x 11)</option>
              <option>Long Bond (8.5 x 13)</option>
              <option>A4</option>
              <option>A3</option>
            </select>

            <label for="qtyInput">Quantity / Copies<span class="required">*</span></label>
            <input type="number" min="1" value="1" class="form-input" id="qtyInput">

            <label for="notes">Additional Instructions / Edit Request</label>
            <textarea class="form-textarea" id="notes"></textarea>
          </div>

          <div>
            <label>Color Option<span class="required">*</span></label>
            <div class="radio-group">
              <label><input type="radio" name="color" value="Black & White"> Black & White</label>
              <label><input type="radio" name="color" value="Colored Full"> Colored (Full)</label>
              <label><input type="radio" name="color" value="Colored Half"> Colored (Half)</label>
            </div>
          </div>
        </div>
      </div>

      <div class="form-card form-card--secondary">
        <h3 class="step-title">3. UPLOAD FILES</h3>

        <label for="fileUpload">Upload your document</label>
        <input type="file" id="fileUpload" class="form-file" accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png" multiple>

        <p class="file-note">Accepted formats: PDF, DOC, DOCX, PPT, PPTX, JPG, PNG</p>

        <div class="file-note" id="fileAnalysisPanel">
          <p class="file-note">Use Remove to take a file out before submitting.</p>
          <strong>Uploaded Files</strong>
          <ul id="fileAnalysisList"></ul>
          <p id="fileAnalysisMeta">No files uploaded yet.</p>
        </div>
      </div>
    </div>

    <aside class="summary-card">
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

      <div class="summary-row">
        <span>TOTAL PAGES:</span>
        <strong id="summaryTotalPages">0</strong>
      </div>

      <div class="summary-row">
        <span>PRICE / PAGE:</span>
        <strong id="summaryPricePerPage">&#8369;0.00</strong>
      </div>

      <div class="summary-divider"></div>

      <div class="summary-total">
        <span>Estimated Total:</span>
        <strong id="summaryTotal">&#8369;0.00</strong>
      </div>
    </aside>

    <p id="formFeedback" class="form-feedback" role="alert" aria-live="polite"></p>

    <div class="form-actions">
      <a href="/pages/customer/custo1_printing_option.php" class="btn-back">Back</a>
      <button type="button" class="btn-next" id="joinQueueBtn">Join Queue</button>
    </div>
  </div>
</section>

<?php include __DIR__ . "/../../components/footer.php"; ?>
<?php include __DIR__ . "/../../components/queue_modal.php"; ?>

<script src="/assets/js/csrf.js"></script>
<script src="/assets/js/main.js?v=20260326c4"></script>
<script src="/assets/js/custo2_docu_printing.js?v=20260406a1"></script>
</body>
</html>
