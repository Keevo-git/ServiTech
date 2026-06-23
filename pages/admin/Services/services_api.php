<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
require_once __DIR__ . "/../../../config/csrf.php";
require_once __DIR__ . "/../_includes/admin_db.php";
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
        $where = "WHERE category = :cat";
        $params[":cat"] = $cat;
    } else {
        $where = "";
    }
    $stmt = $pdo->prepare("
      SELECT id, category, name, description,
             CASE WHEN active THEN 1 ELSE 0 END AS active, sort_order
      FROM services
      $where
      ORDER BY category ASC, sort_order ASC, id ASC
    ");
    $stmt->execute($params);
    $services = servitech_catalog_dedupe_services($stmt->fetchAll(PDO::FETCH_ASSOC));
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
    $catalogJsonRaw = trim((string)($_POST["catalog_json"] ?? ""));
    $catalogData = null;
    $active = isset($_POST["active"]) ? (int)($_POST["active"]) : 1;
    $sort_order = isset($_POST["sort_order"]) ? (int)($_POST["sort_order"]) : 0;

    if ($id <= 0) respond(["ok" => false, "error" => "New top-level services cannot be added here. Edit one of the configured services instead."]);

    if ($catalogJsonRaw !== "") {
        $catalogData = json_decode($catalogJsonRaw, true);
        if (!is_array($catalogData)) {
            respond(["ok" => false, "error" => "Invalid catalog data"]);
        }
    }

    try {
        $existingStmt = $pdo->prepare("
          SELECT id, category, name, description,
                 CASE WHEN active THEN 1 ELSE 0 END AS active, sort_order
          FROM services
          WHERE id = :id
          LIMIT 1
        ");
        $existingStmt->execute([":id" => $id]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($existing)) throw new DomainException("Service not found.");

        $requestedName = $name;
        $category = (string)$existing["category"];
        $name = (string)$existing["name"];
        $serviceKind = servitech_catalog_service_kind($existing);
        if ($serviceKind === "laminating") {
            $normalizedRequestedName = strtolower(trim($requestedName));
            if (!in_array($normalizedRequestedName, ["laminating", "lamination"], true)) {
                throw new DomainException("Use Laminating or Lamination as the service name.");
            }
            $name = trim($requestedName);
        } elseif ($serviceKind === "scanning") {
            $normalizedRequestedName = strtolower(trim($requestedName));
            if (!in_array($normalizedRequestedName, ["scanning", "scan"], true)) {
                throw new DomainException("Use Scanning or Scan as the service name.");
            }
            $name = trim($requestedName);
        }
        if (!is_array($catalogData)) throw new DomainException("Service options are required.");
        $catalogData = servitech_catalog_normalize_admin_payload($existing, $catalogData);
        $activeRules = servitech_catalog_customer_rules_from_admin_catalog($catalogData);
        if (servitech_catalog_service_kind($existing) === "rush_id") {
            $activeRules = array_values(array_filter($activeRules, static fn($rule) => isset($rule["option_value_keys"]["package"])));
        } elseif (servitech_catalog_service_kind($existing) === "installation") {
            $deviceMode = false;
            foreach ($catalogData["groups"] as $group) {
                if (($group["group_key"] ?? "") === "device_type" && !empty($group["active"])) {
                    $deviceMode = true;
                    break;
                }
            }
            $activeRules = array_values(array_filter($activeRules, static function ($rule) use ($deviceMode): bool {
                $hasDevice = isset($rule["option_value_keys"]["device_type"]);
                return $deviceMode ? $hasDevice : !$hasDevice;
            }));
        }
        $priceRange = servitech_catalog_price_range_from_rules($activeRules);

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
          UPDATE services
          SET category=:category,
              name=:name,
              description=:description,
              price=NULL,
              price_range=:price_range,
              active=CAST(:active AS boolean),
              sort_order=:sort_order,
              updated_at=NOW()
          WHERE id=:id
        ");
        $stmt->execute([
            ":category" => $category,
            ":name" => $name,
            ":description" => $description,
            ":price_range" => $priceRange,
            ":active" => servitech_catalog_bool_param($active),
            ":sort_order" => (int)$existing["sort_order"],
            ":id" => $id,
        ]);
        servitech_catalog_upsert($pdo, $id, $catalogData);
        $pdo->commit();
        respond([
            "ok" => true,
            "id" => $id,
            "message" => $name . " updated successfully.",
            "service" => [
                "id" => $id,
                "category" => $category,
                "name" => $name,
                "description" => $description,
                "active" => $active ? 1 : 0,
                "sort_order" => (int)$existing["sort_order"],
                "catalog_price_range" => $active ? ($priceRange ?: "For assessment") : "Not shown to customers",
            ],
            "catalog" => $catalogData,
        ]);
    } catch (DomainException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond(["ok" => false, "error" => $e->getMessage()]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $serviceLabel = $name !== "" ? $name : ("service #" . $id);
        error_log("services_api save error for {$serviceLabel}: " . $e->getMessage());
        respond([
            "ok" => false,
            "error" => "Failed to save {$serviceLabel}. The database rejected the service update; please check the service setup and try again.",
        ]);
    }
}

if ($action === "delete") {
    respond([
        "ok" => false,
        "error" => "Removing services is no longer supported from this editor. Use the Active/Inactive toggle instead.",
    ]);
}

respond(["ok" => false, "error" => "Unknown action"]);


