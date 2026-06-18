<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/join_queue_flow.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/store_availability.php";
servitech_store_send_no_cache_headers();
servitech_start_new_join_queue_if_requested();
servitech_redirect_completed_join_queue();
$storeAvailability = servitech_store_current_availability($pdo);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Repair Option</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260616-footer-hover">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260524-queue-modal">
  <link rel="stylesheet" href="/assets/css/store-availability.css?v=20260615">
</head>
<body class="customer-layout customer-page--forms" data-service="repair">

<?php include __DIR__ . "/../../components/header.php"; ?>

<section class="form-page form-page--single">
  <div class="form-page-shell">
    <div class="form-page-intro">
      <h2 class="page-title">JC REPAIR SERVICES</h2>
      <p class="page-subtitle">Place your repair order below.</p>
    </div>

    <div class="form-card">
      <h3 class="step-title">2. CHOOSE REPAIR SERVICE</h3>

      <div class="form-grid">
        <div>
          <label for="repairServiceSelect">Select Service<span class="required">*</span></label>
          <select class="form-select" id="repairServiceSelect">
            <option value="" selected disabled>Select Repair Service</option>
            <option value="LCD Replacement" data-min="1200" data-max="5500">LCD Replacement</option>
            <option value="Battery Replacement" data-min="700" data-max="2500">Battery Replacement</option>
            <option value="Charging Pin Replacement" data-min="800" data-max="4000">Charging Pin Replacement</option>
            <option value="Speaker / Mouthpiece Replacement" data-min="700" data-max="1500">Speaker / Mouthpiece Replacement</option>
            <option value="Power Button Repair" data-min="500" data-max="2000">Power Button Repair</option>
            <option value="Volume Repair" data-min="1000" data-max="2000">Volume Repair</option>
            <option value="Part(s) Upgrade" data-min="1500" data-max="5000">Part(s) Upgrade</option>
            <option value="Other Repair Request" data-price-range="Price to be assessed">Other Repair Request</option>
          </select>
        </div>

        <div>
          <div class="service-form-price-card" aria-live="polite">
            <span class="service-form-price-card__label">Selected Service Price Range</span>
            <strong id="repairPriceRange">Choose a repair service</strong>
          </div>
        </div>
      </div>
    </div>

    <div class="form-card">
      <h3 class="step-title">3. ENTER SERVICE DETAILS</h3>

      <div class="form-grid">
        <div>
          <label for="deviceTypeSelect">Select Device Type<span class="required">*</span></label>
          <select class="form-select" id="deviceTypeSelect">
            <option value="" selected disabled>Select Device</option>
            <option>Mobile Phone / Tablet</option>
            <option>Laptop</option>
            <option>Desktop</option>
            <option>Other</option>
          </select>

          <label for="repairNotes">Additional Information/Other Request:</label>
          <textarea class="form-textarea" id="repairNotes"></textarea>
        </div>

        <div>
          <p class="form-note">Provide as much detail as possible to help our technicians.</p>
        </div>
      </div>
    </div>


    <div class="form-actions">
      <a href="/pages/customer/customer_dash.php" class="btn-back">Back</a>
      <button type="button" class="btn-next" id="joinQueueBtn" <?= $storeAvailability["regular_queue_allowed"] ? "" : 'disabled data-availability-locked="true"' ?>>Join Queue</button>
      <?php if (!$storeAvailability["regular_queue_allowed"]): ?><p class="queue-unavailable-note"><?= htmlspecialchars($storeAvailability["message"], ENT_QUOTES, "UTF-8") ?></p><?php endif; ?>
    </div>
  </div>
</section>

<?php include __DIR__ . "/../../components/footer.php"; ?>
<?php include __DIR__ . "/../../components/queue_modal.php"; ?>
<?php
$joinQueueBackUrl = "/pages/customer/customer_dash.php";
include __DIR__ . "/../../components/join_queue_leave_guard.php";
?>

<script src="/assets/js/csrf.js"></script>
<script src="/assets/js/main.js?v=20260611-queue-success"></script>

</body>
</html>


