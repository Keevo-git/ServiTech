<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/join_queue_flow.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/store_availability.php";
require_once __DIR__ . "/../../api/service_catalog.php";
servitech_store_send_no_cache_headers();
servitech_start_new_join_queue_if_requested();
servitech_redirect_completed_join_queue();
$storeAvailability = servitech_store_current_availability($pdo);
$repairServiceId = 0;
$repairServiceName = "Repair Services";
servitech_store_redirect_customer_unavailable_service($storeAvailability, $repairServiceName);
$repairDeviceOptions = [];
$repairRules = [];
try {
  $repairService = servitech_catalog_fetch_service_by_kind($pdo, "repair", true);
  if (is_array($repairService)) {
    $repairServiceId = (int)$repairService["id"];
    $repairServiceName = trim((string)($repairService["name"] ?? "")) ?: $repairServiceName;
    $catalog = servitech_catalog_fetch($pdo, $repairServiceId, true);
    foreach ($catalog["groups"] as $group) {
      if (($group["group_key"] ?? "") === "device_type") $repairDeviceOptions = $group["values"] ?? [];
    }
    $repairRules = $catalog["rules"] ?? [];
    $devicesWithServices = [];
    foreach ($repairRules as $rule) {
      $deviceKey = trim((string)($rule["option_value_keys"]["device_type"] ?? ""));
      if ($deviceKey !== "") $devicesWithServices[$deviceKey] = true;
    }
    $repairDeviceOptions = array_values(array_filter(
      $repairDeviceOptions,
      static fn($option) => isset($devicesWithServices[(string)($option["value_key"] ?? "")])
    ));
  }
} catch (Throwable $e) {
  $repairDeviceOptions = [];
  $repairRules = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Repair Option</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260621-assessment-payment">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260621-join-form-wrap">
  <link rel="stylesheet" href="/assets/css/store-availability.css?v=20260615">
</head>
<body class="customer-layout customer-page--forms"
      data-service="repair"
      data-service-label="<?= htmlspecialchars($repairServiceName, ENT_QUOTES, "UTF-8") ?>"
      data-catalog-service-id="<?= (int)$repairServiceId ?>">

<?php include __DIR__ . "/../../components/header.php"; ?>

<section class="form-page form-page--single">
  <div class="form-page-shell">
    <div class="form-page-intro">
      <h2 class="page-title">JC REPAIR SERVICES</h2>
      <p class="page-subtitle">Place your repair order below.</p>
    </div>

    <div class="form-card">
      <h3 class="step-title">1. CHOOSE DEVICE</h3>

      <div class="form-grid">
        <div>
          <label for="deviceTypeSelect">Device<span class="required">*</span></label>
          <select class="form-select" id="deviceTypeSelect">
            <option value="" selected disabled>Choose device</option>
            <?php foreach ($repairDeviceOptions as $option): ?>
              <option value="<?= htmlspecialchars((string)$option["value_key"], ENT_QUOTES, "UTF-8") ?>"
                      data-value-id="<?= (int)($option["id"] ?? 0) ?>"
                      data-value-key="<?= htmlspecialchars((string)$option["value_key"], ENT_QUOTES, "UTF-8") ?>">
                <?= htmlspecialchars((string)$option["label"], ENT_QUOTES, "UTF-8") ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="form-card" id="repairServiceStep" hidden>
      <h3 class="step-title">2. CHOOSE SERVICE TYPE</h3>

      <div class="form-grid">
        <div>
          <label for="repairServiceSelect">Service Type<span class="required">*</span></label>
          <select class="form-select" id="repairServiceSelect" disabled>
            <option value="" selected disabled>Choose service type</option>
          </select>
          <p class="form-note" id="repairAvailabilityMessage" role="status" hidden>No repair services are available for this device. Please contact the shop or choose Others if available.</p>
        </div>
      </div>
    </div>

    <div class="form-card" id="repairPriceStep" hidden>
      <h3 class="step-title">3. PRICE ASSESSMENT</h3>
      <div class="service-form-price-card" aria-live="polite">
        <span class="service-form-price-card__label">Estimated Service Price</span>
        <strong id="repairPriceRange">Choose a repair service</strong>
      </div>
    </div>

    <div class="form-card" id="repairIssueCard" hidden>
      <label for="repairNotes">Describe the issue/request<span class="required">*</span></label>
      <textarea class="form-textarea" id="repairNotes"></textarea>
      <p class="form-note">A description is required when you select Others.</p>
    </div>

    <div class="customer-form-actions customer-step-actions">
      <a href="/pages/customer/customer_dash.php" class="btn-back">Back</a>
      <button type="button" class="btn-next btn-primary-action" id="joinQueueBtn" <?= $storeAvailability["regular_queue_allowed"] ? "" : 'disabled data-availability-locked="true"' ?>>Join Queue</button>
      <?php if (!$storeAvailability["regular_queue_allowed"]): ?><p class="queue-unavailable-note"><?= htmlspecialchars($storeAvailability["message"], ENT_QUOTES, "UTF-8") ?></p><?php endif; ?>
    </div>
  </div>
</section>

<?php include __DIR__ . "/../../components/footer.php"; ?>
<?php
$servitechJoinQueueNewRequestStarted = servitech_consume_new_join_queue_started();
include __DIR__ . "/../../components/queue_modal.php";
?>
<?php
$joinQueueBackUrl = "/pages/customer/customer_dash.php";
include __DIR__ . "/../../components/join_queue_leave_guard.php";
?>

<script src="/assets/js/csrf.js"></script>
<script>
  window.servitechCatalogServiceId = <?= (int)$repairServiceId ?>;
  window.servitechCatalogRules = <?= json_encode($repairRules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="/assets/js/service_catalog_client.js?v=20260621-option-ids"></script>
<script src="/assets/js/main.js?v=20260621-no-service-payment-ui"></script>

</body>
</html>


