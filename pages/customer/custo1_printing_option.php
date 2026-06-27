<?php
require_once __DIR__ . "/../../components/auth_guard.php";
require_once __DIR__ . "/../../config/join_queue_flow.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/store_availability.php";
require_once __DIR__ . "/../../config/operational_controls.php";
require_once __DIR__ . "/../../api/service_catalog.php";
servitech_store_send_no_cache_headers();
servitech_start_new_join_queue_if_requested();
servitech_redirect_completed_join_queue();
$storeAvailability = servitech_store_current_availability($pdo);
$printingServiceRows = [];
$printingServices = [];
$printingServiceOptions = [];
$printingServiceLoadError = "";
try {
  $stmt = $pdo->prepare("
    SELECT id, category, name,
           CASE WHEN active THEN 1 ELSE 0 END AS active,
           sort_order
    FROM services
    WHERE category = 'printing'
      AND active = TRUE
    ORDER BY sort_order ASC, id ASC
  ");
  $stmt->execute();
  $printingServiceRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $printingServices = array_values(array_filter(
    servitech_catalog_dedupe_services($printingServiceRows, false),
    static function (array $service) use ($pdo): bool {
      try {
        $catalog = servitech_catalog_fetch($pdo, (int)$service["id"], true);
        return !empty(servitech_catalog_customer_availability($service, $catalog)["available"]);
      } catch (Throwable $e) {
        return false;
      }
    }
  ));
} catch (Throwable $e) {
  $printingServiceLoadError = $e->getMessage();
  error_log("ServiTech printing service selection query failed: " . $e->getMessage());
}

function printing_service_route(array $service): string {
  return match (servitech_catalog_service_kind($service)) {
    "document_printing" => "custo2_docu_printing.php",
    "photocopy" => "custo2_photocopy.php",
    "rush_id" => "custo2_rush_id.php",
    "laminating" => "custo2_laminating.php",
    "scanning" => "custo2_scanning.php",
    default => "",
  };
}

function printing_service_display_name(string $name): string {
  return strcasecmp(trim($name), "xerox") === 0 ? "Photocopy" : $name;
}

$excludedPrintingServices = [];
foreach ($printingServices as $service) {
  $route = printing_service_route($service);
  if ($route === "") {
    $excludedPrintingServices[] = [
      "id" => (int)($service["id"] ?? 0),
      "name" => (string)($service["name"] ?? ""),
      "reason" => "unsupported_service_kind",
    ];
    continue;
  }
  $printingServiceOptions[] = ["service" => $service, "route" => $route];
}

error_log("ServiTech printing service selection: " . json_encode([
  "category" => "printing",
  "filters" => ["active" => true],
  "active_rows_found" => count($printingServiceRows),
  "services_returned" => array_map(static fn($service) => [
    "id" => (int)($service["id"] ?? 0),
    "name" => (string)($service["name"] ?? ""),
    "active" => (int)($service["active"] ?? 0),
  ], $printingServices),
  "excluded" => $excludedPrintingServices,
  "query_error" => $printingServiceLoadError,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ServiTech: Print Options</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="/assets/css/style.css?v=20260621-global-ui-polish">
  <link rel="stylesheet" href="/assets/css/customer-responsive.css?v=20260621-join-form-wrap">
  <link rel="stylesheet" href="/assets/css/store-availability.css?v=20260615">
</head>
<body class="customer-layout customer-page--forms">

<?php include __DIR__ . "/../../components/header.php"; ?>

<section class="form-page form-page--single">
  <div class="form-page-shell">
    <div class="form-page-intro">
      <h2 class="page-title">JC PRINTING SERVICES</h2>
      <p class="page-subtitle">Place your print, copy, or ID photo order below.</p>
    </div>

    <div class="form-card">
      <h3 class="step-title">2. CHOOSE PRINTING SERVICE</h3>

      <label for="serviceType">
        Select Service Type<span class="required">*</span>
      </label>

      <select id="serviceType" class="form-select">
        <option value="" selected disabled>Select A Service</option>
        <?php foreach ($printingServiceOptions as $option):
          $service = $option["service"];
          $route = (string)$option["route"];
          $requiresRegularQueue = servitech_catalog_service_kind($service) !== "document_printing";
          $manualUnavailable = servitech_operational_customer_service_unavailable(
            $pdo,
            (string)($service["category"] ?? ""),
            (string)($service["name"] ?? ""),
            (int)($service["id"] ?? 0)
          );
          $disabled = ($manualUnavailable !== "") || ($requiresRegularQueue && !$storeAvailability["regular_queue_allowed"]);
        ?>
          <option value="<?= htmlspecialchars($route, ENT_QUOTES, "UTF-8") ?>" <?= $disabled ? "disabled" : "" ?>>
            <?= htmlspecialchars(printing_service_display_name((string)$service["name"]), ENT_QUOTES, "UTF-8") ?><?= $disabled ? " - unavailable now" : "" ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if (!$printingServiceOptions): ?>
        <p class="queue-unavailable-note">No active printing services are available right now. Please contact the shop.</p>
      <?php endif; ?>
      <?php if (!$storeAvailability["regular_queue_allowed"]): ?>
        <p class="queue-unavailable-note"><?= htmlspecialchars($storeAvailability["message"], ENT_QUOTES, "UTF-8") ?></p>
      <?php endif; ?>
      <?php if (!empty(servitech_operational_fetch_overall($pdo)["all_services_closed"])): ?>
        <p class="queue-unavailable-note">Services are temporarily unavailable. Please check back later.</p>
      <?php endif; ?>
    </div>

    <div class="customer-form-actions customer-step-actions">
      <a href="/pages/customer/customer_dash.php" class="btn-back">Back</a>
      <button type="button" class="btn-next btn-primary-action" id="nextBtn" disabled>Continue to Queue</button>
    </div>
  </div>
</section>

<?php include __DIR__ . "/../../components/footer.php"; ?>

<script>
  const serviceSelect = document.getElementById("serviceType");
  const nextBtn = document.getElementById("nextBtn");

  serviceSelect.addEventListener("change", () => {
    nextBtn.disabled = !serviceSelect.value;
  });

  nextBtn.addEventListener("click", () => {
    const route = serviceSelect.value;
    if (!route) {
      alert("Please select a service first.");
      serviceSelect.focus();
      return;
    }

    window.location.href = route || "custo1_printing_option.php";
  });
</script>
<?php if (servitech_consume_new_join_queue_started()): ?>
<script>window.SERVITECH_JOIN_QUEUE_NEW_REQUEST = true;</script>
<?php endif; ?>
<script src="/assets/js/join_queue_post_success.js?v=20260619-new-request"></script>

</body>
</html>

