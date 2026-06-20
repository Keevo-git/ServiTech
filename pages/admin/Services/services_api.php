<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
require_once __DIR__ . "/../../../config/csrf.php";
require_once __DIR__ . "/../_includes/admin_db.php";
require_once __DIR__ . "/../../../api/service_pricing.php";
require_once __DIR__ . "/../../../api/service_catalog.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);


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
        $where = "WHERE category = :cat AND archived_at IS NULL";
        $params[":cat"] = $cat;
    } else {
        $where = "WHERE archived_at IS NULL";
    }
    $stmt = $pdo->prepare("
      SELECT id, category, name, description, price, price_range, pricing_json::text AS pricing_json,
             CASE WHEN active THEN 1 ELSE 0 END AS active, sort_order
      FROM services
      $where
      ORDER BY category ASC, sort_order ASC, id ASC
    ");
    $stmt->execute($params);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($services as &$service) {
        try {
            $service["catalog"] = servitech_catalog_fetch($pdo, (int)$service["id"], false);
        } catch (Throwable $e) {
            $service["catalog"] = null;
        }
    }
    unset($service);
    respond(["ok" => true, "services" => $services]);
}

if ($action === "catalog") {
    $id = (int)($_GET["id"] ?? $_POST["id"] ?? 0);
    if ($id <= 0) {
        respond(["ok" => false, "error" => "Invalid service id"]);
    }
    try {
        respond(["ok" => true, "catalog" => servitech_catalog_fetch($pdo, $id, false)]);
    } catch (Throwable $e) {
        respond(["ok" => false, "error" => "Catalog not found"]);
    }
}

if ($action === "save") {
    $id = (int)($_POST["id"] ?? 0);
    $category = trim((string)($_POST["category"] ?? ""));
    $name = trim((string)($_POST["name"] ?? ""));
    $description = trim((string)($_POST["description"] ?? ""));
    $priceRaw = trim((string)($_POST["price"] ?? ""));
    $priceRange = trim((string)($_POST["price_range"] ?? ""));
    $pricingJsonRaw = trim((string)($_POST["pricing_json"] ?? ""));
    $catalogJsonRaw = trim((string)($_POST["catalog_json"] ?? ""));
    $pricingJson = null;
    $decodedPricing = null;
    $catalogData = null;
    $active = isset($_POST["active"]) ? (int)($_POST["active"]) : 1;
    $sort_order = isset($_POST["sort_order"]) ? (int)($_POST["sort_order"]) : 0;

    if ($id <= 0) respond(["ok" => false, "error" => "New top-level services cannot be added here. Edit one of the configured services instead."]);

    $price = null;
    if ($priceRaw !== "") {
        if (!is_numeric($priceRaw)) {
            respond(["ok" => false, "error" => "Price must be a number"]);
        }
        $price = (float)$priceRaw;
    }

    if ($pricingJsonRaw !== "") {
        $decodedPricing = json_decode($pricingJsonRaw, true);
        if (!is_array($decodedPricing)) {
            respond(["ok" => false, "error" => "Invalid pricing data"]);
        }
        $pricingJson = json_encode($decodedPricing, JSON_UNESCAPED_UNICODE);
    }

    if ($catalogJsonRaw !== "") {
        $catalogData = json_decode($catalogJsonRaw, true);
        if (!is_array($catalogData)) {
            respond(["ok" => false, "error" => "Invalid catalog data"]);
        }
    }

    try {
        servitech_pricing_validate_admin_catalog($category, $name, $price, $decodedPricing);
    } catch (DomainException $e) {
        respond(["ok" => false, "error" => $e->getMessage()]);
    }

    try {
        $existingStmt = $pdo->prepare("
          SELECT id, category, name, description, price, price_range, pricing_json::text AS pricing_json,
                 CASE WHEN active THEN 1 ELSE 0 END AS active, sort_order
          FROM services
          WHERE id = :id AND archived_at IS NULL
          LIMIT 1
        ");
        $existingStmt->execute([":id" => $id]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($existing)) throw new DomainException("Service not found.");

        $category = (string)$existing["category"];
        $name = (string)$existing["name"];
        if (!is_array($catalogData)) throw new DomainException("Service options are required.");
        $catalogData = servitech_catalog_normalize_admin_payload($existing, $catalogData);
        $activeRules = array_values(array_filter($catalogData["rules"], static fn($rule) => !empty($rule["active"])));
        if (servitech_catalog_service_kind($existing) === "rush_id") {
            $activeRules = array_values(array_filter($activeRules, static fn($rule) => isset($rule["option_value_keys"]["package"])));
        }
        $priceRange = servitech_catalog_price_range_from_rules($activeRules);

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
          UPDATE services
          SET category=:category,
              name=:name,
              description=:description,
              price=:price,
              price_range=:price_range,
              pricing_json='{}'::jsonb,
              active=:active,
              archived_at=CASE WHEN :reactivate THEN NULL ELSE archived_at END,
              sort_order=:sort_order,
              updated_at=NOW()
          WHERE id=:id
        ");
        $stmt->execute([
            ":category" => $category,
            ":name" => $name,
            ":description" => $description,
            ":price" => $price,
            ":price_range" => $priceRange,
            ":active" => ($active ? true : false),
            ":reactivate" => ($active ? true : false),
            ":sort_order" => (int)$existing["sort_order"],
            ":id" => $id,
        ]);
        servitech_catalog_upsert($pdo, $id, $catalogData);
        $pdo->commit();
        respond(["ok" => true, "id" => $id, "message" => $name . " updated successfully."]);
    } catch (DomainException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond(["ok" => false, "error" => $e->getMessage()]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("services_api save error: " . $e->getMessage());
        respond(["ok" => false, "error" => "Failed to save changes. Please try again."]);
    }
}

if ($action === "delete") {
    $id = (int)($_POST["id"] ?? 0);
    if ($id <= 0) {
        respond(["ok" => false, "error" => "Invalid id"]);
    }
    try {
        $stmt = $pdo->prepare("
          UPDATE services
          SET active = FALSE, archived_at = COALESCE(archived_at, NOW()), updated_at = NOW()
          WHERE id = :id
        ");
        $stmt->execute([":id" => $id]);
    } catch (Throwable $e) {
        $stmt = $pdo->prepare("
          UPDATE services
          SET active = FALSE, updated_at = NOW()
          WHERE id = :id
        ");
        $stmt->execute([":id" => $id]);
    }
    respond(["ok" => true, "archived" => true]);
}

respond(["ok" => false, "error" => "Unknown action"]);


