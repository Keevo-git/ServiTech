<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/db.php";

$xeroxPricing = [
  "long" => 5.0,
  "short" => 3.0,
  "a4" => 3.0,
  "a3" => 5.0,
];

function xerox_extract_line_price(string $description, string $label): ?float {
  $pattern = "/" . preg_quote($label, "/") . "\\s*:?\\s*\\x{20B1}?\\s*([0-9]+(?:\\.[0-9]+)?)/iu";
  if (preg_match($pattern, $description, $matches)) {
    return max(0, (float)$matches[1]);
  }

  return null;
}

try {
  $xeroxStmt = $pdo->prepare("
    SELECT description, price, pricing_json::text AS pricing_json
    FROM services
    WHERE category = 'printing'
      AND LOWER(name) LIKE '%xerox%'
      AND active = TRUE
    ORDER BY sort_order ASC, id ASC
    LIMIT 1
  ");
  $xeroxStmt->execute();
  $xeroxService = $xeroxStmt->fetch(PDO::FETCH_ASSOC);

  if (is_array($xeroxService)) {
    $description = (string)($xeroxService["description"] ?? "");
    $storedPricing = json_decode((string)($xeroxService["pricing_json"] ?? ""), true);
    $fallbackPrice = isset($xeroxService["price"]) ? max(0, (float)$xeroxService["price"]) : 3.0;

    $xeroxPricing = [
      "long" => isset($storedPricing["long"]) ? (float)$storedPricing["long"] : (xerox_extract_line_price($description, "Long Bond Paper") ?? 5.0),
      "short" => isset($storedPricing["short"]) ? (float)$storedPricing["short"] : (xerox_extract_line_price($description, "Short Bond Paper") ?? $fallbackPrice),
      "a4" => isset($storedPricing["a4"]) ? (float)$storedPricing["a4"] : (xerox_extract_line_price($description, "A4") ?? $fallbackPrice),
      "a3" => isset($storedPricing["a3"]) ? (float)$storedPricing["a3"] : (xerox_extract_line_price($description, "A3") ?? 5.0),
    ];
  }
} catch (Throwable $e) {
  // Keep the Xerox form usable if service pricing cannot be loaded.
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Xerox</title>
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="/assets/css/style.css?v=20260410d1">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260410d1">
</head>
<body class="customer-layout customer-page--forms customer-page--custo2" data-service="xerox">

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
            <p class="static-text">Xerox</p>

            <label for="paperSizeSelect">Paper Size<span class="required">*</span></label>
            <select class="form-select" id="paperSizeSelect">
              <option selected disabled>Select paper size</option>
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
            <p class="form-note">
              Service uses default Xerox settings &mdash; no color choice needed.
            </p>
          </div>
        </div>
      </div>
    </div>

    <aside class="summary-card">
      <h3 class="summary-title">ORDER SUMMARY</h3>

      <div class="summary-row">
        <span>SERVICE:</span>
        <strong>XEROX</strong>
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
<script>
  window.servitechXeroxPricing = <?= json_encode($xeroxPricing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="/assets/js/main.js?v=20260521xerox-prices"></script>

</body>
</html>



