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
