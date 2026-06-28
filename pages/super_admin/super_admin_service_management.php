<?php
require_once __DIR__ . "/../admin/_includes/admin_auth.php";
servitech_require_super_admin();
require_once __DIR__ . "/../admin/_includes/admin_db.php";
require_once __DIR__ . "/../admin/_includes/url.php";
require_once __DIR__ . "/../../config/input_limits.php";
require_once __DIR__ . "/../../api/service_catalog.php";


$tab = $_GET["tab"] ?? "printing";
if (!in_array($tab, ["printing","repair","installation"], true)) $tab = "printing";
$stmt = $pdo->prepare("
  SELECT id, category, name, description,
         CASE WHEN active THEN 1 ELSE 0 END AS active, sort_order
  FROM services
  WHERE category=:cat
  ORDER BY sort_order ASC, id ASC
");
$stmt->execute([":cat"=>$tab]);
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function ms_supported_catalog_service(array $service): bool {
  $category = strtolower(trim((string)($service["category"] ?? "")));
  $name = strtolower(trim((string)($service["name"] ?? "")));
  if ($category === "printing") {
    return (str_contains($name, "document") && str_contains($name, "print"))
      || str_contains($name, "photocopy")
      || str_contains($name, "xerox")
      || (str_contains($name, "rush") && str_contains($name, "id"))
      || str_contains($name, "laminat")
      || str_contains($name, "scan");
  }
  return in_array($category, ["repair", "installation"], true);
}
function ms_display_service_name($name): string {
  $name = trim((string)$name);
  if (strcasecmp($name, "xerox") === 0) return "Photocopy";
  return $name;
}
$unsupportedServices = array_values(array_filter($services, static fn($service) => !ms_supported_catalog_service($service)));
$services = servitech_catalog_dedupe_services(array_values(array_filter($services, "ms_supported_catalog_service")), false);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Manage Services</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= admin_url('/assets/css/style.css?v=20260621-global-ui-polish') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260619-hero-actions') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/Services/manage_services.css?v=20260628-service-edit-layout') ?>">
</head>
<body>

<?php
$adminHeaderVariant = "special";
$adminHeaderMenuId = "admin-services-header-menu";
require __DIR__ . "/../admin/_includes/admin_header.php";
?>

<div class="admin-wrapper">
  <section class="admin-hero admin-hero--actions">
    <div class="admin-hero-text">
      <h1>Manage Services</h1>
      <p>Manage and update services shown on the landing page.</p>
    </div>
    <div class="admin-hero-actions" aria-label="Edit Services actions">
      <button type="button" class="hero-btn hero-btn-secondary" onclick="goAdminBack()">Back</button>
      <a class="hero-btn hero-btn-primary" href="<?= admin_url('/pages/admin/queue_list/printing.php') ?>">View Queue</a>
      <a class="hero-btn hero-btn-primary" href="<?= admin_url('/pages/admin/order_management/printM.php') ?>">View Orders</a>
    </div>
  </section>

<main class="admin-container">
<div class="ms-wrap">
  <div class="ms-card">
    <div class="ms-tabs">
      <a class="ms-tab <?= $tab==="printing"?"active":"" ?>" href="?tab=printing">Print</a>
      <a class="ms-tab <?= $tab==="repair"?"active":"" ?>" href="?tab=repair">Repair</a>
      <a class="ms-tab <?= $tab==="installation"?"active":"" ?>" href="?tab=installation">Installation</a>
    </div>

    <div class="ms-page-error" id="msPageError" role="alert" hidden></div>
    <div class="ms-service-grid">
        <?php if (!$services): ?>
          <div class="ms-empty">No configured services were found. Run the service catalog migration first.</div>
        <?php else: ?>
          <?php foreach($services as $s):
            $catalogPriceRange = (int)$s["active"] ? "Catalog unavailable" : "Not shown to customers";
            if ((int)$s["active"]) {
              try {
                $catalog = servitech_catalog_fetch($pdo, (int)$s["id"], true);
                $availability = servitech_catalog_customer_availability($s, $catalog);
                $catalogPriceRange = !empty($availability["available"])
                  ? (string)($catalog["service"]["catalog_price_range"] ?? "For assessment")
                  : "Catalog unavailable";
              } catch (Throwable $e) {
                // Keep the explicit unavailable state until this service has an active catalog.
              }
            }
            $payload = [
              "id" => (int)$s["id"],
              "category" => (string)$s["category"],
              "name" => (string)$s["name"],
              "description" => (string)$s["description"],
              "active" => (int)$s["active"],
              "sort_order" => (int)$s["sort_order"],
            ];
          ?>
            <article class="ms-service-card">
              <div class="ms-service-card__head">
                <div>
                  <span class="ms-service-card__category"><?= h(ucfirst($tab)) ?></span>
                  <h2><?= h(ms_display_service_name($s["name"])) ?></h2>
                </div>
                <span class="ms-pill <?= (int)$s["active"] ? "on":"off" ?>"><?= (int)$s["active"] ? "Active":"Inactive" ?></span>
              </div>
              <p><?= h($s["description"]) ?></p>
              <div class="ms-service-card__foot">
                <span><strong>Customer price:</strong> <?= h($catalogPriceRange ?: "For assessment") ?></span>
                <button
                  class="ms-edit-button"
                  type="button"
                  data-ms-edit='<?= h(json_encode($payload)) ?>'
                  data-service-id="<?= (int)$s["id"] ?>"
                  data-service-category="<?= h($s["category"]) ?>"
                  data-service-name="<?= h($s["name"]) ?>"
                >Edit prices and options</button>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php if ($unsupportedServices): ?>
      <div class="ms-setup-note">
        <strong>Service<?= count($unsupportedServices) === 1 ? "" : "s" ?> needing setup:</strong>
        <?= h(implode(", ", array_map(static fn($service) => ms_display_service_name($service["name"] ?? "Unnamed service"), $unsupportedServices))) ?>.
        Add a configured service structure before managing <?= count($unsupportedServices) === 1 ? "this service" : "these services" ?> here.
      </div>
    <?php endif; ?>
  </div>
</div>
</main>
</div>

<div class="ms-overlay" id="msOverlay" hidden aria-hidden="true">
  <div class="ms-modal service-management-modal" role="dialog" aria-modal="true" aria-labelledby="msModalTitle" tabindex="-1">
    <button class="ms-x" id="msX" type="button" aria-label="Close modal">&times;</button>

    <div class="ms-mhead">
      <div>
        <span class="ms-modal-eyebrow">Manage Service</span>
        <h3 id="msModalTitle">Edit Service</h3>
        <p id="msModalHelp" class="ms-modal-help"></p>
      </div>
    </div>
    <div class="ms-accent"></div>

    <div class="ms-body">
      <input type="hidden" id="ms_id" value="">
      <input type="hidden" id="ms_category">

      <div class="ms-service-status">
        <div>
          <strong>Service status</strong>
          <span>Use this Active/Inactive toggle to show or hide the entire service from the landing page and queue forms.</span>
        </div>
        <label class="ms-switch">
          <input id="ms_active" type="checkbox">
          <span aria-hidden="true"></span>
          <em id="ms_active_label">Active</em>
        </label>
      </div>

      <details class="ms-service-details">
        <summary>Customer-facing service description</summary>
        <div class="ms-field" id="msServiceNameField" hidden>
          <label for="ms_name">Service name</label>
          <input id="ms_name" type="text" maxlength="<?= SERVITECH_LIMIT_SERVICE_NAME ?>" data-character-count>
          <small data-character-help>Maximum <?= SERVITECH_LIMIT_SERVICE_NAME ?> characters.</small>
          <small>Use Laminating or Lamination.</small>
        </div>
        <div class="ms-field">
          <label for="ms_description">Customer-facing service description</label>
          <textarea id="ms_description" placeholder="Short customer-facing note for this service" maxlength="<?= SERVITECH_LIMIT_SERVICE_DESCRIPTION ?>" data-character-count></textarea>
          <small data-character-help>Maximum <?= SERVITECH_LIMIT_SERVICE_DESCRIPTION ?> characters.</small>
          <small>This text appears with the service on customer-facing pages.</small>
        </div>
        <div class="ms-field">
          <label for="ms_sort">Service display order</label>
          <input id="ms_sort" type="number" min="0" max="9999" step="1">
          <small>Lower numbers appear first in customer and admin service lists.</small>
        </div>
      </details>

      <div id="ms_catalogEditor" class="ms-catalog-editor"></div>

      <div class="ms-err" id="msErr"></div>
    </div>

    <div class="ms-foot">
      <button class="ms-btn ghost" id="msCancel" type="button">Cancel</button>
      <button class="ms-btn primary" id="msSave" type="button">Save</button>
    </div>
  </div>
</div>

<div class="ms-confirm-overlay" id="msConfirmOverlay" hidden aria-hidden="true">
  <div class="ms-confirm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="msConfirmTitle" aria-describedby="msConfirmMessage" tabindex="-1">
    <button class="ms-x ms-confirm-x" id="msConfirmX" type="button" aria-label="Close modal">&times;</button>
    <div class="ms-confirm-icon" aria-hidden="true">!</div>
    <div class="ms-confirm-copy">
      <h3 id="msConfirmTitle">Confirm change</h3>
      <p id="msConfirmMessage"></p>
    </div>
    <div class="ms-confirm-actions">
      <button class="ms-btn ghost" id="msConfirmCancel" type="button">Cancel</button>
      <button class="ms-btn primary" id="msConfirmAccept" type="button">Confirm</button>
    </div>
  </div>
</div>

<script>
  window.MS_ACTIVE_TAB = <?= json_encode($tab) ?>;
  window.MS_API_URL = <?= json_encode(admin_url_raw('/pages/super_admin/super_admin_services_api.php')) ?>;
</script>
<script src="<?= admin_url('/assets/js/csrf.js') ?>"></script>
<?php require_once __DIR__ . "/../admin/_includes/admin_footer.php"; ?>
<script src="<?= admin_url('/pages/admin/Services/manage_services.js?v=' . time()) ?>"></script>

<script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>
</body>
</html>



