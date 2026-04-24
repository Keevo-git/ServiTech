<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
require_once __DIR__ . "/../../../config/csrf.php";
require_once __DIR__ . "/../_includes/admin_db.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

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
        INSERT INTO services (category, name, description, price, active, sort_order)
        VALUES (:category, :name, :description, :price, :active, :sort_order)
      ");
      
      foreach ($seedData as [$category, $name, $description, $price, $active, $sort_order]) {
        $insertStmt->execute([
          ':category' => $category,
          ':name' => $name,
          ':description' => $description,
          ':price' => $price,
          ':active' => $active,
          ':sort_order' => $sort_order,
        ]);
      }
    }
} catch (Throwable $e) {
    // Keep API resilient even if schema migration is restricted.
}

$action = $_POST["action"] ?? $_GET["action"] ?? "";

function respond(array $arr): void
{
    echo json_encode($arr);
    exit();
}

if ($action === "list") {
    $cat = $_GET["category"] ?? "";
    $params = [];
    $where = "";
    if ($cat === "printing" || $cat === "repair" || $cat === "installation") {
        $where = "WHERE category = :cat";
        $params[":cat"] = $cat;
    }
    $stmt = $pdo->prepare("
      SELECT id, category, name, description, price,
             CASE WHEN active THEN 1 ELSE 0 END AS active, sort_order
      FROM services
      $where
      ORDER BY category ASC, sort_order ASC, id ASC
    ");
    $stmt->execute($params);
    respond(["ok" => true, "services" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($action === "save") {
    $id = (int)($_POST["id"] ?? 0);
    $category = trim((string)($_POST["category"] ?? ""));
    $name = trim((string)($_POST["name"] ?? ""));
    $description = trim((string)($_POST["description"] ?? ""));
    $priceRaw = trim((string)($_POST["price"] ?? ""));
    $active = isset($_POST["active"]) ? (int)($_POST["active"]) : 1;
    $sort_order = isset($_POST["sort_order"]) ? (int)($_POST["sort_order"]) : 0;

    if (!in_array($category, ["printing", "repair", "installation"], true)) {
        respond(["ok" => false, "error" => "Invalid category"]);
    }
    if ($name === "") {
        respond(["ok" => false, "error" => "Service name required"]);
    }

    $price = null;
    if ($priceRaw !== "") {
        if (!is_numeric($priceRaw)) {
            respond(["ok" => false, "error" => "Price must be a number"]);
        }
        $price = (float)$priceRaw;
    }

    if ($id > 0) {
        $stmt = $pdo->prepare("
          UPDATE services
          SET category=:category,
              name=:name,
              description=:description,
              price=:price,
              active=:active,
              sort_order=:sort_order,
              updated_at=NOW()
          WHERE id=:id
        ");
        $stmt->execute([
            ":category" => $category,
            ":name" => $name,
            ":description" => $description,
            ":price" => $price,
            ":active" => ($active ? true : false),
            ":sort_order" => $sort_order,
            ":id" => $id,
        ]);
        respond(["ok" => true, "id" => $id]);
    }

    $stmt = $pdo->prepare("
      INSERT INTO services (category, name, description, price, active, sort_order)
      VALUES (:category, :name, :description, :price, :active, :sort_order)
      RETURNING id
    ");
    $stmt->execute([
        ":category" => $category,
        ":name" => $name,
        ":description" => $description,
        ":price" => $price,
        ":active" => ($active ? true : false),
        ":sort_order" => $sort_order,
    ]);
    $newId = (int)($stmt->fetchColumn() ?: 0);
    respond(["ok" => true, "id" => $newId]);
}

if ($action === "delete") {
    $id = (int)($_POST["id"] ?? 0);
    if ($id <= 0) {
        respond(["ok" => false, "error" => "Invalid id"]);
    }
    $stmt = $pdo->prepare("DELETE FROM services WHERE id = :id");
    $stmt->execute([":id" => $id]);
    respond(["ok" => true]);
}

respond(["ok" => false, "error" => "Unknown action"]);
