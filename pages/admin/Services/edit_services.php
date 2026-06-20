<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
require_once __DIR__ . "/../_includes/admin_db.php";
require_once __DIR__ . "/../_includes/url.php";


$tab = $_GET["tab"] ?? "printing";
if (!in_array($tab, ["printing","repair","installation"], true)) $tab = "printing";

$stmt = $pdo->prepare("
  SELECT id, category, name, description, price, price_range, pricing_json::text AS pricing_json,
         CASE WHEN active THEN 1 ELSE 0 END AS active, sort_order
  FROM services
  WHERE category=:cat
  ORDER BY sort_order ASC, id ASC
");
$stmt->execute([":cat"=>$tab]);
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function ms_display_service_name($name): string {
  $name = trim((string)$name);
  return strcasecmp($name, "xerox") === 0 ? "Photocopy" : $name;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Manage Services</title>
  <?= servitech_favicon_link() ?>
  <link rel="stylesheet" href="<?= admin_url('/assets/css/style.css?v=20260315h2') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260619-hero-actions') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/Services/manage_services.css?v=20260620-catalog-editor') ?>">
</head>
<body>

<?php
$adminHeaderVariant = "special";
$adminHeaderMenuId = "admin-services-header-menu";
require __DIR__ . "/../_includes/admin_header.php";
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
    <div class="ms-head">
      <button class="ms-add" id="msAdd">+ Add Services</button>
    </div>

    <div class="ms-tabs">
      <a class="ms-tab <?= $tab==="printing"?"active":"" ?>" href="?tab=printing">Print</a>
      <a class="ms-tab <?= $tab==="repair"?"active":"" ?>" href="?tab=repair">Repair</a>
      <a class="ms-tab <?= $tab==="installation"?"active":"" ?>" href="?tab=installation">Installation</a>
    </div>

    <div class="ms-tableWrap table-scroll-wrapper">
      <table class="ms-table table-content">
        <thead>
          <tr>
            <th style="width:220px">Services</th>
            <th>Description</th>
            <th style="width:150px">Price Range</th>
            <th style="width:90px">Base Price</th>
            <th style="width:90px">Active</th>
            <th style="width:140px">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$services): ?>
          <tr><td colspan="6" style="padding:14px;color:#666;font-weight:800;">No services yet. Click &ldquo;+ Add Services&rdquo;.</td></tr>
        <?php else: ?>
          <?php foreach($services as $s):
            $payload = [
              "id" => (int)$s["id"],
              "category" => (string)$s["category"],
              "name" => (string)$s["name"],
              "description" => (string)$s["description"],
              "price" => $s["price"],
              "price_range" => (string)$s["price_range"],
              "pricing_json" => (string)($s["pricing_json"] ?? ""),
              "active" => (int)$s["active"],
              "sort_order" => (int)$s["sort_order"],
            ];
          ?>
            <tr>
              <td><?= h(ms_display_service_name($s["name"])) ?></td>
              <td><?= h($s["description"]) ?></td>
              <td><?= h($s["price_range"] ?: "Not set") ?></td>
              <td><?= $s["price"]===null ? "&mdash;" : "&#8369;".h(number_format((float)$s["price"],2)) ?></td>
              <td><span class="ms-pill <?= (int)$s["active"] ? "on":"off" ?>"><?= (int)$s["active"] ? "ON":"OFF" ?></span></td>
              <td class="ms-actions">
                <button class="edit" type="button" data-ms-edit='<?= h(json_encode($payload)) ?>'>Edit</button>
                <button class="del" type="button" data-ms-del="<?= (int)$s["id"] ?>">Archive</button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</main>
</div>

<div class="ms-overlay" id="msOverlay">
  <div class="ms-modal">
    <button class="ms-x" id="msX" type="button" aria-label="Close">&times;</button>

    <div class="ms-mhead">
      <h3 id="msModalTitle">Add Service</h3>
    </div>
    <div class="ms-accent"></div>

    <div class="ms-body">
      <input type="hidden" id="ms_id" value="">
      <div class="ms-row2">
        <div class="ms-field">
          <label>Category</label>
          <select id="ms_category">
            <option value="printing">Print</option>
            <option value="repair">Repair</option>
            <option value="installation">Installation</option>
          </select>
        </div>
        <div class="ms-field">
          <label>Active</label>
          <select id="ms_active">
            <option value="1">ON</option>
            <option value="0">OFF</option>
          </select>
        </div>
      </div>

      <div class="ms-field">
        <label>Service Name</label>
        <input id="ms_name" type="text" placeholder="e.g., Document Print">
      </div>

      <div class="ms-field">
        <label>Description</label>
        <textarea id="ms_description" placeholder="Short customer-facing note for this service"></textarea>
        <small id="ms_description_hint">Use the catalog editor for selectable options and pricing.</small>
      </div>

      <div class="ms-field">
        <label>Price Range</label>
        <input id="ms_price_range" type="text" placeholder="e.g., PHP 1000 - PHP 5000">
        <small>This appears on the landing page service card.</small>
      </div>

      <div class="ms-row2">
        <div class="ms-field" id="ms_priceModeField">
          <label>Price Mode</label>
          <select id="ms_priceMode">
            <option value="default">Default price</option>
            <option value="full">Full price</option>
            <option value="half">Half price</option>
          </select>
        </div>
        <div class="ms-field" id="ms_priceField">
          <label id="ms_priceLabel">Price (optional)</label>
          <input id="ms_price" type="text" placeholder="e.g., 10.00">
        </div>
      </div>
      <div class="ms-field">
        <small id="ms_price_hint">Use the catalog editor below for dynamic service pricing.</small>
      </div>

      <div class="ms-field">
        <div id="ms_catalogEditor" class="ms-catalog-editor"></div>
      </div>

      <div class="ms-row2">
        <div class="ms-field">
          <label>Sort order</label>
          <input id="ms_sort" type="number" value="0">
        </div>
      </div>

      <div class="ms-err" id="msErr"></div>
    </div>

    <div class="ms-foot">
      <button class="ms-btn ghost" id="msCancel" type="button">Cancel</button>
      <button class="ms-btn primary" id="msSave" type="button">Save</button>
    </div>
  </div>
</div>

<script>
  window.MS_ACTIVE_TAB = <?= json_encode($tab) ?>;
  window.MS_API_URL = <?= json_encode(admin_url_raw('/pages/admin/Services/services_api.php')) ?>;
</script>
<script src="<?= admin_url('/assets/js/csrf.js') ?>"></script>
<script src="<?= admin_url('/pages/admin/Services/manage_services.js?v=20260620-dynamic-catalog') ?>"></script>
<?php require_once __DIR__ . "/../_includes/admin_footer.php"; ?>

<script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>
</body>
</html>



