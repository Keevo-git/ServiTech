<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/join_queue_flow.php";
servitech_redirect_completed_join_queue();
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/store_availability.php";
servitech_store_send_no_cache_headers();
$storeAvailability = servitech_store_current_availability($pdo);

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
      AND (LOWER(name) LIKE '%xerox%' OR LOWER(name) LIKE '%photocopy%')
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
  // Keep the photocopy form usable if service pricing cannot be loaded.
}
?>

<!DOCTYPE html>
<html lang="en" class="customer-order-summary-page">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Photocopy</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260616-footer-hover">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260612-sticky-scroll-root">
  <link rel="stylesheet" href="/assets/css/store-availability.css?v=20260615">
  <style>
    body.customer-page--custo2 .form-actions {
      align-items: stretch !important;
      display: flex !important;
      flex-direction: column !important;
      flex-wrap: wrap;
      gap: 0.75rem;
      margin: 8px auto 0;
      max-width: 860px;
      width: 100%;
    }

    body.customer-page--custo2 .form-actions .btn-back,
    body.customer-page--custo2 .form-actions .btn-next {
      align-items: center !important;
      border-radius: 10px !important;
      box-sizing: border-box;
      display: inline-flex !important;
      flex: 0 0 auto !important;
      font-size: 16px;
      font-weight: 600;
      height: auto !important;
      justify-content: center !important;
      line-height: 1.2;
      min-height: 52px !important;
      padding: 14px 24px !important;
      text-align: center !important;
      text-decoration: none !important;
      width: 100% !important;
    }

    body.customer-page--custo2 .form-actions .btn-back {
      background: #000 !important;
      border: 1px solid #000 !important;
      color: #fff !important;
    }

    body.customer-page--custo2 .form-actions .btn-next {
      background: #fbbf24 !important;
      border: 1px solid #000 !important;
      color: #000 !important;
    }

    body.customer-page--custo2 .form-actions .btn-back:hover,
    body.customer-page--custo2 .form-actions .btn-next:hover {
      transform: translateY(-2px);
    }

    @media (max-width: 720px) {
      body.customer-page--custo2 .form-actions .btn-back,
      body.customer-page--custo2 .form-actions .btn-next {
        flex: 0 0 auto;
        width: 100%;
      }
    }
  </style>
</head>
<body class="customer-layout customer-page--forms customer-page--custo2 customer-page--order-summary" data-service="xerox" data-service-label="Photocopy">

<?php include __DIR__ . "/../../components/header.php"; ?>

<section class="form-page form-page--single form-page--order-summary">
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
            <p class="static-text">Photocopy</p>

            <label for="paperSizeSelect">Paper Size<span class="required">*</span></label>
            <select class="form-select" id="paperSizeSelect">
              <option value="" selected disabled>Select paper size</option>
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
              Service uses default photocopy settings &mdash; no color choice needed.
            </p>
          </div>
        </div>
      </div>

    </div>

    <div class="order-summary-panel">
      <aside class="summary-card">
        <h3 class="summary-title">ORDER SUMMARY</h3>

        <div class="summary-row">
          <span>SERVICE:</span>
          <strong>PHOTOCOPY</strong>
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

      <div class="form-actions">
        <a href="/pages/customer/custo1_printing_option.php" class="btn-back">Back</a>
        <button type="button" class="btn-next" id="joinQueueBtn" <?= $storeAvailability["regular_queue_allowed"] ? "" : 'disabled data-availability-locked="true"' ?>>Join Queue</button>
        <?php if (!$storeAvailability["regular_queue_allowed"]): ?><p class="queue-unavailable-note"><?= htmlspecialchars($storeAvailability["message"], ENT_QUOTES, "UTF-8") ?></p><?php endif; ?>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__ . "/../../components/footer.php"; ?>
<?php include __DIR__ . "/../../components/queue_modal.php"; ?>
<?php
$joinQueueBackUrl = "/pages/customer/custo1_printing_option.php";
include __DIR__ . "/../../components/join_queue_leave_guard.php";
?>

<script src="/assets/js/csrf.js"></script>
<script>
  window.servitechXeroxPricing = <?= json_encode($xeroxPricing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="/assets/js/main.js?v=20260611-queue-success"></script>

</body>
</html>



