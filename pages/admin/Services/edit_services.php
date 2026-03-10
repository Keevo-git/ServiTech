<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
require_once __DIR__ . "/../_includes/admin_db.php";
require_once __DIR__ . "/../_includes/url.php";

try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS services (
      id BIGSERIAL PRIMARY KEY,
      category TEXT NOT NULL CHECK (category IN ('printing','repair','installation')),
      name VARCHAR(120) NOT NULL,
      description VARCHAR(255) NOT NULL DEFAULT '',
      price NUMERIC(10,2) NULL,
      active BOOLEAN NOT NULL DEFAULT TRUE,
      sort_order INTEGER NOT NULL DEFAULT 0,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )
  ");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_services_category ON services(category)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_services_active ON services(active)");
} catch (Throwable $e) {}

$tab = $_GET["tab"] ?? "printing";
if (!in_array($tab, ["printing","repair","installation"], true)) $tab = "printing";

$stmt = $pdo->prepare("
  SELECT id, category, name, description, price,
         CASE WHEN active THEN 1 ELSE 0 END AS active, sort_order
  FROM services
  WHERE category=:cat
  ORDER BY sort_order ASC, id ASC
");
$stmt->execute([":cat"=>$tab]);
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Manage Services</title>

  <link rel="stylesheet" href="<?= admin_url('/assets/css/style.css') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/Services/manage_services.css') ?>">
</head>
<body>

<header class="navbar">
  <a href="<?= admin_url('/pages/admin/admin_dashboard.php') ?>" class="logo">
    <img src="<?= admin_url('/assets/images/LOGO_SERVITECH.png') ?>" alt="ServiTech Logo" class="servitech-logo">
    <h1>ServiTech: JC Repair Shop</h1>
  </a>
  <nav>
    <a href="<?= admin_url('/pages/admin/admin_dashboard.php') ?>">Admin Home</a>
    <a href="<?= admin_url('/pages/admin/logout.php') ?>">Logout</a>
  </nav>
</header>

<div class="ms-wrap">
  <div class="ms-card">
    <div class="ms-head">
      <div>
        <h2>Manage Services</h2>
        <p>Manage and update services shown on the landing page</p>
      </div>
      <button class="ms-add" id="msAdd">+ Add Services</button>
    </div>

    <div class="ms-tabs">
      <a class="ms-tab <?= $tab==="printing"?"active":"" ?>" href="?tab=printing">Print</a>
      <a class="ms-tab <?= $tab==="repair"?"active":"" ?>" href="?tab=repair">Repair</a>
      <a class="ms-tab <?= $tab==="installation"?"active":"" ?>" href="?tab=installation">Install</a>
    </div>

    <div class="ms-tableWrap">
      <table class="ms-table">
        <thead>
          <tr>
            <th style="width:220px">Services</th>
            <th>Description</th>
            <th style="width:90px">Price</th>
            <th style="width:90px">Active</th>
            <th style="width:140px">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$services): ?>
          <tr><td colspan="5" style="padding:14px;color:#666;font-weight:800;">No services yet. Click â€œ+ Add Servicesâ€.</td></tr>
        <?php else: ?>
          <?php foreach($services as $s):
            $payload = [
              "id" => (int)$s["id"],
              "category" => (string)$s["category"],
              "name" => (string)$s["name"],
              "description" => (string)$s["description"],
              "price" => $s["price"],
              "active" => (int)$s["active"],
              "sort_order" => (int)$s["sort_order"],
            ];
          ?>
            <tr>
              <td><?= h($s["name"]) ?></td>
              <td><?= h($s["description"]) ?></td>
              <td><?= $s["price"]===null ? "â€”" : "â‚±".h(number_format((float)$s["price"],2)) ?></td>
              <td><span class="ms-pill <?= (int)$s["active"] ? "on":"off" ?>"><?= (int)$s["active"] ? "ON":"OFF" ?></span></td>
              <td class="ms-actions">
                <button class="edit" type="button" data-ms-edit='<?= h(json_encode($payload)) ?>'>Edit</button>
                <button class="del" type="button" data-ms-del="<?= (int)$s["id"] ?>">Delete</button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="ms-overlay" id="msOverlay">
  <div class="ms-modal">
    <button class="ms-x" id="msX" type="button">Ã—</button>

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
            <option value="printing">Printing</option>
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
        <input id="ms_name" type="text" placeholder="e.g., Document Printing">
      </div>

      <div class="ms-field">
        <label>Description</label>
        <textarea id="ms_description" placeholder="Short Bond Paper, Long Bond Paper, A4..."></textarea>
      </div>

      <div class="ms-row2">
        <div class="ms-field">
          <label>Price (optional)</label>
          <input id="ms_price" type="text" placeholder="e.g., 10.00">
        </div>
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
<script src="<?= admin_url('/pages/admin/Services/manage_services.js') ?>"></script>

<footer class="footer">
  <p class="footer-bottom">Â© 2026 ServiTech: JC Store</p>
</footer>

</body>
</html>



