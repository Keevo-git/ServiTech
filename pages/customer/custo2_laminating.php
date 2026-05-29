<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/db.php";

$laminatingPricing = [
  "thin" => 20.0,
  "thick" => 30.0,
];

function laminating_price(array $pricing, string $key): string {
  return number_format((float)($pricing[$key] ?? 0), 2, ".", "");
}

try {
  $laminatingStmt = $pdo->prepare("
    SELECT price, pricing_json::text AS pricing_json
    FROM services
    WHERE category = 'printing'
      AND LOWER(name) LIKE '%laminat%'
      AND active = TRUE
    ORDER BY sort_order ASC, id ASC
    LIMIT 1
  ");
  $laminatingStmt->execute();
  $laminatingService = $laminatingStmt->fetch(PDO::FETCH_ASSOC);

  if (is_array($laminatingService)) {
    $storedPricing = json_decode((string)($laminatingService["pricing_json"] ?? ""), true);
    if (is_array($storedPricing)) {
      foreach ($laminatingPricing as $key => $fallback) {
        if (isset($storedPricing[$key]) && is_numeric($storedPricing[$key])) {
          $laminatingPricing[$key] = max(0, (float)$storedPricing[$key]);
        }
      }
    }
  }
} catch (Throwable $e) {
  // Keep the Laminating form usable if service pricing cannot be loaded.
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Laminating</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="/assets/css/style.css?v=20260410d1">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260524-queue-modal">
  <style>
    .service-payment-card {
      display: grid;
      gap: 0.9rem;
    }

    .service-payment-grid {
      align-items: start;
      display: grid;
      gap: 1rem;
      grid-template-columns: minmax(0, 1fr) minmax(180px, 220px);
    }

    .service-gcash-card {
      background: #fffaf4;
      border: 1px solid rgba(95, 14, 15, 0.14);
      border-radius: 16px;
      display: none;
      gap: 0.75rem;
      justify-items: center;
      padding: 0.9rem;
      text-align: center;
    }

    .service-gcash-card.is-visible {
      display: grid;
    }

    .service-gcash-card strong {
      color: #5f0e0f;
      font-size: 0.88rem;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }

    .service-gcash-card img {
      border-radius: 10px;
      display: block;
      height: auto;
      max-width: 190px;
      object-fit: contain;
      width: 100%;
    }

    .service-payment-note {
      background: #fff;
      border: 1px solid rgba(95, 14, 15, 0.12);
      border-radius: 14px;
      color: #9a3412;
      margin: 0.75rem 0 0;
      padding: 0.85rem 1rem;
    }

    body.customer-page--custo2 .form-actions {
      align-items: center;
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      margin-top: 0;
      width: 100%;
    }

    body.customer-page--custo2 .form-actions .btn-back,
    body.customer-page--custo2 .form-actions .btn-next {
      align-items: center;
      border-radius: 10px;
      display: inline-flex;
      flex: 1 1 0;
      font-size: 16px;
      font-weight: 600;
      height: 52px !important;
      justify-content: center;
      line-height: 1.2;
      max-height: 56px;
      min-height: 0 !important;
      padding: 0 24px !important;
      text-align: center;
      width: auto;
    }

    @media (max-width: 720px) {
      .service-payment-grid {
        grid-template-columns: 1fr;
      }

      body.customer-page--custo2 .form-actions {
        align-items: stretch;
        flex-direction: column;
      }

      body.customer-page--custo2 .form-actions .btn-back,
      body.customer-page--custo2 .form-actions .btn-next {
        flex: 0 0 auto;
        width: 100%;
      }
    }
  </style>
</head>
<body class="customer-layout customer-page--forms customer-page--custo2" data-service="printing" data-price-per-page="20">

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
            <p class="static-text">Laminating</p>

            <label for="lamTypeSelect">Lamination Type<span class="required">*</span></label>
            <select class="form-select" id="lamTypeSelect">
              <option value="" selected disabled>Select lamination type</option>
              <option value="thin" data-price="<?= laminating_price($laminatingPricing, "thin") ?>">Thin (Manipis) &mdash; &#8369;<?= laminating_price($laminatingPricing, "thin") ?></option>
              <option value="thick" data-price="<?= laminating_price($laminatingPricing, "thick") ?>">Thick (Makapal) &mdash; &#8369;<?= laminating_price($laminatingPricing, "thick") ?></option>
            </select>

            <label for="notes">Additional Instructions / Edit Request</label>
            <textarea class="form-textarea" id="notes"></textarea>
          </div>

          <div>
            <label for="qtyInput">Quantity / Copies<span class="required">*</span></label>
            <input type="number" min="1" value="1" class="form-input" id="qtyInput">
            <p class="form-note">Note: Laminating price shown is an estimate per item.</p>
          </div>
        </div>
      </div>

      <div class="form-card form-card--secondary service-payment-card">
        <h3 class="step-title">3. PAYMENT</h3>
        <div class="service-payment-grid">
          <div>
            <label for="paymentMethodSelect">Payment Method<span class="required">*</span></label>
            <select id="paymentMethodSelect" class="form-select">
              <option value="" selected disabled>Select payment method</option>
              <option value="cash">Cash / Pay at Store</option>
              <option value="gcash">GCash</option>
            </select>
            <p id="serviceCashNote" class="service-payment-note" hidden>Please go to the store to complete payment before processing.</p>
          </div>

          <div id="serviceGcashCard" class="service-gcash-card" aria-live="polite">
            <strong>Scan GCash QR</strong>
            <img src="/assets/images/gcash-qr.jpg" alt="JC Shop GCash QR code">
          </div>
        </div>
      </div>
    </div>

    <aside class="summary-card">
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
<script src="/assets/js/main.js?v=20260524-queue-fix"></script>

</body>
</html>



