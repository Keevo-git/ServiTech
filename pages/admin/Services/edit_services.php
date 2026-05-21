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
      price_range VARCHAR(255) NOT NULL DEFAULT '',
      active BOOLEAN NOT NULL DEFAULT TRUE,
      sort_order INTEGER NOT NULL DEFAULT 0,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )
  ");
  $pdo->exec("ALTER TABLE services ADD COLUMN IF NOT EXISTS price_range VARCHAR(255) NOT NULL DEFAULT ''");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_services_category ON services(category)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_services_active ON services(active)");
  
  // Seed default services if table is empty
  $countStmt = $pdo->query("SELECT COUNT(*) FROM services");
  $count = (int)($countStmt->fetchColumn() ?: 0);
  
  if ($count === 0) {
    $seedData = [
      // Printing Services
      ['printing', 'Document Printing', "Short Bond Paper (Colored)\nFull – ₱10.00\nHalf – ₱5.00\n\nShort Bond Paper (B&W)\n₱5.00\n\nA4 (Colored)\nFull – ₱10.00\nHalf – ₱5.00\n\nA4 (B&W)\n₱5.00", 5.00, 1, 0],
      ['printing', 'Xerox', "Long Bond Paper: ₱5\nShort Bond Paper: ₱3\nA4: ₱3", 3.00, 1, 1],
      ['printing', 'Rush ID', "Choose between packages 1-6.\nPrice varies by selected package.", 30.00, 1, 2],
      ['printing', 'Laminating', "Manipis / Thin: ₱20\nMakapal / Thick: ₱30", 20.00, 1, 3],
      // Repair Services
      ['repair', 'LCD Replacement', "For mobile phones and laptops.\nPrice range: ₱1200 – ₱5500", 1200.00, 1, 0],
      ['repair', 'Battery Replacement', "For mobile phones and laptops.\nPrice range: ₱700 – ₱2500", 700.00, 1, 1],
      ['repair', 'Charging Pin Replacement', "For mobile phones and laptops.\nPrice range: ₱800 – ₱4000", 800.00, 1, 2],
      ['repair', 'Speaker / Mouthpiece Replacement', "For mobile phones and laptops.\nPrice range: ₱700 – ₱1500", 700.00, 1, 3],
      ['repair', 'Power Button Repair', "For mobile phones and laptops.\nPrice range: ₱500 – ₱2000", 500.00, 1, 4],
      ['repair', 'Volume Repair', "For mobile phones and laptops.\nPrice range: ₱1000 – ₱2000", 1000.00, 1, 5],
      ['repair', 'Camera Repair', "For mobile phones and laptops.\nPrice range: ₱1500 – ₱5000", 1500.00, 1, 6],
      // Installation Services
      ['installation', 'Reprogram Service', 'Price range: ₱1000 – ₱4000', 1000.00, 1, 0],
      ['installation', 'Hang Logo Fix Service', 'Price range: ₱1000 – ₱3500', 1000.00, 1, 1],
      ['installation', 'Boot Loop Fix Service', 'Price range: ₱1000 – ₱5000', 1000.00, 1, 2],
      ['installation', 'Openline Samsung & iPhone', 'Price range: ₱3500 – ₱6000', 3500.00, 1, 3],
      ['installation', 'Bypass Google Account', 'Price range: ₱500 – ₱2000', 500.00, 1, 4],
      ['installation', 'Bypass Password', 'Price range: ₱1000 – ₱3000', 1000.00, 1, 5],
    ];
    
    $insertStmt = $pdo->prepare("
      INSERT INTO services (category, name, description, price, price_range, active, sort_order)
      VALUES (:category, :name, :description, :price, :price_range, :active, :sort_order)
    ");
    
    foreach ($seedData as [$category, $name, $description, $price, $active, $sort_order]) {
      $insertStmt->execute([
        ':category' => $category,
        ':name' => $name,
        ':description' => $description,
        ':price' => $price,
        ':price_range' => '',
        ':active' => $active,
        ':sort_order' => $sort_order,
      ]);
    }
  }
  $pdo->exec("
    UPDATE services
    SET price_range = CASE
      WHEN category = 'printing' AND LOWER(name) LIKE '%document%printing%' THEN '₱5 – ₱10'
      WHEN category = 'printing' AND LOWER(name) LIKE '%xerox%' THEN '₱3 – ₱5'
      WHEN category = 'printing' AND LOWER(name) LIKE '%rush%id%' THEN '₱30 – ₱50'
      WHEN category = 'printing' AND LOWER(name) LIKE '%laminat%' THEN '₱20 – ₱30'
      WHEN category = 'repair' AND LOWER(name) LIKE '%lcd%' THEN '₱1200 – ₱5500'
      WHEN category = 'repair' AND LOWER(name) LIKE '%battery%' THEN '₱700 – ₱2500'
      WHEN category = 'repair' AND LOWER(name) LIKE '%charging%' THEN '₱800 – ₱4000'
      WHEN category = 'repair' AND (LOWER(name) LIKE '%speaker%' OR LOWER(name) LIKE '%mouthpiece%') THEN '₱700 – ₱1500'
      WHEN category = 'repair' AND LOWER(name) LIKE '%power%' THEN '₱500 – ₱2000'
      WHEN category = 'repair' AND LOWER(name) LIKE '%volume%' THEN '₱1000 – ₱2000'
      WHEN category = 'repair' AND LOWER(name) LIKE '%camera%' THEN '₱1500 – ₱5000'
      WHEN category = 'installation' AND LOWER(name) LIKE '%reprogram%' THEN '₱1000 – ₱4000'
      WHEN category = 'installation' AND LOWER(name) LIKE '%hang logo%' THEN '₱1000 – ₱3500'
      WHEN category = 'installation' AND LOWER(name) LIKE '%boot%' THEN '₱1000 – ₱5000'
      WHEN category = 'installation' AND LOWER(name) LIKE '%openline%' THEN '₱3500 – ₱6000'
      WHEN category = 'installation' AND LOWER(name) LIKE '%google%' THEN '₱500 – ₱2000'
      WHEN category = 'installation' AND LOWER(name) LIKE '%password%' THEN '₱1000 – ₱3000'
      ELSE price_range
    END
    WHERE price_range = ''
  ");
} catch (Throwable $e) {}

$tab = $_GET["tab"] ?? "printing";
if (!in_array($tab, ["printing","repair","installation"], true)) $tab = "printing";

$stmt = $pdo->prepare("
  SELECT id, category, name, description, price, price_range,
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
  <link rel="icon" type="images/png" href="/assets/images/favicon.png" >
  <link rel="stylesheet" href="<?= admin_url('/assets/css/style.css?v=20260315h2') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/admin.css?v=20260521layout') ?>">
  <link rel="stylesheet" href="<?= admin_url('/pages/admin/Services/manage_services.css?v=20260521modal-scroll') ?>">
</head>
<body>

<header class="navbar has-nav-menu">
  <a href="<?= admin_url('/pages/admin/admin_dashboard.php') ?>" class="logo">
    <img src="<?= admin_url('/assets/images/LOGO_SERVITECH.png') ?>" alt="ServiTech Logo" class="servitech-logo">
    <h1>ServiTech: JC Repair Shop</h1>
  </a>
  <button
    class="nav-toggle"
    type="button"
    aria-label="Toggle navigation menu"
    aria-expanded="false"
    aria-controls="admin-services-header-menu"
  >
    <span class="nav-toggle__bar"></span>
    <span class="nav-toggle__bar"></span>
    <span class="nav-toggle__bar"></span>
  </button>
  <nav id="admin-services-header-menu" data-collapsible-menu>
    <a href="<?= admin_url('/pages/admin/admin_dashboard.php') ?>">Admin Home</a>
    <a href="<?= admin_url('/pages/admin/logout.php') ?>">Logout</a>
  </nav>
</header>

<div class="admin-wrapper">
  <section class="admin-hero">
    <h1>Manage Services</h1>
    <p>Manage and update services shown on the landing page.</p>
  </section>

<main class="admin-container">
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
              "active" => (int)$s["active"],
              "sort_order" => (int)$s["sort_order"],
            ];
          ?>
            <tr>
              <td><?= h($s["name"]) ?></td>
              <td><?= h($s["description"]) ?></td>
              <td><?= h($s["price_range"] ?: "Not set") ?></td>
              <td><?= $s["price"]===null ? "&mdash;" : "&#8369;".h(number_format((float)$s["price"],2)) ?></td>
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
        <textarea id="ms_description" placeholder="Short Bond Paper (Colored)\nFull - 10.00\nHalf - 5.00\n\nShort Bond Paper (B&W)\n5.00"></textarea>
        <small id="ms_description_hint">Use newline-separated entries. For Document Printing, paper and color groups are fixed on the customer page.</small>
      </div>

      <div class="ms-field">
        <label>Price Range</label>
        <input id="ms_price_range" type="text" placeholder="e.g., ₱1000 – ₱5000">
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
        <small id="ms_price_hint">Choose Full or Half when editing print price lines inside the description.</small>
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
<script src="<?= admin_url('/pages/admin/Services/manage_services.js?v=20260521a3-a4-prices') ?>"></script>
<?php require_once __DIR__ . "/../_includes/admin_footer.php"; ?>

<script src="<?= admin_url('/assets/js/header-menu.js') ?>" defer></script>
</body>
</html>


