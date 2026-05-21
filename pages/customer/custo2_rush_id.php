<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/db.php";

$rushPricing = [
  "package1" => 40.0,
  "package2" => 30.0,
  "package3" => 30.0,
  "package4" => 50.0,
  "package5" => 30.0,
  "package6" => 50.0,
];

try {
  $rushStmt = $pdo->prepare("
    SELECT price, pricing_json::text AS pricing_json
    FROM services
    WHERE category = 'printing'
      AND LOWER(name) LIKE '%rush%'
      AND LOWER(name) LIKE '%id%'
      AND active = TRUE
    ORDER BY sort_order ASC, id ASC
    LIMIT 1
  ");
  $rushStmt->execute();
  $rushService = $rushStmt->fetch(PDO::FETCH_ASSOC);

  if (is_array($rushService)) {
    $storedPricing = json_decode((string)($rushService["pricing_json"] ?? ""), true);
    if (is_array($storedPricing)) {
      foreach ($rushPricing as $key => $fallback) {
        if (isset($storedPricing[$key]) && is_numeric($storedPricing[$key])) {
          $rushPricing[$key] = max(0, (float)$storedPricing[$key]);
        }
      }
    }
  }
} catch (Throwable $e) {
  // Keep the Rush ID form usable if service pricing cannot be loaded.
}

function rush_price(array $pricing, string $key): string {
  return number_format((float)($pricing[$key] ?? 0), 2, ".", "");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Rush ID</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="/assets/css/style.css?v=20260410d1">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260410d1">
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
            <p class="static-text">Rush ID</p>

            <label for="packageSelect">Select Package<span class="required">*</span></label>
            <select id="packageSelect" class="form-select">
              <option value="" selected disabled>Select a Package</option>
              <option data-price="<?= rush_price($rushPricing, "package1") ?>">Package 1: 1x1 (4pcs.), 2x2 (2pcs.) &mdash; &#8369;<?= rush_price($rushPricing, "package1") ?></option>
              <option data-price="<?= rush_price($rushPricing, "package2") ?>">Package 2: 1x1 (6pcs.) &mdash; &#8369;<?= rush_price($rushPricing, "package2") ?></option>
              <option data-price="<?= rush_price($rushPricing, "package3") ?>">Package 3: 2x2 (4pcs.) &mdash; &#8369;<?= rush_price($rushPricing, "package3") ?></option>
              <option data-price="<?= rush_price($rushPricing, "package4") ?>">Package 4: 2x2 (4pcs.), 1x1 (4pcs.) &mdash; &#8369;<?= rush_price($rushPricing, "package4") ?></option>
              <option data-price="<?= rush_price($rushPricing, "package5") ?>">Package 5: Passport size (4pcs.) &mdash; &#8369;<?= rush_price($rushPricing, "package5") ?></option>
              <option data-price="<?= rush_price($rushPricing, "package6") ?>">Package 6: 1x1 (10pcs.) &mdash; &#8369;<?= rush_price($rushPricing, "package6") ?></option>
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

            <label for="notes">Additional Instructions / Edit Request</label>
            <textarea class="form-textarea" id="notes"></textarea>
          </div>

          <div>
            <label for="qtyInput">Quantity / Copies<span class="required">*</span></label>
            <input type="number" min="1" value="1" id="qtyInput" class="form-input">
          </div>
        </div>
      </div>
    </div>

    <aside class="summary-card">
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
<script src="/assets/js/main.js?v=20260521rush-prices"></script>

</body>
</html>



