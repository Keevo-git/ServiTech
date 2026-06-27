<?php
/**
 * Public API for fetching services data
 * Used by the landing page to display service modals dynamically
 * No authentication required
 */

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/operational_controls.php";
require_once __DIR__ . "/service_catalog.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$action = $_GET["action"] ?? "";
$category = $_GET["category"] ?? null;

function respond(array $arr): void
{
    echo json_encode($arr);
    exit();
}

if ($action === "list" && $category) {
    // Fetch services by category
    if (!in_array($category, ["printing", "repair", "installation"], true)) {
        respond(["ok" => false, "error" => "Invalid category"]);
    }

    try {
        $stmt = $pdo->prepare("
          SELECT id, category, name, description,
                 CASE WHEN active THEN 1 ELSE 0 END AS active, sort_order
          FROM services
          WHERE category = :category
          ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute([":category" => $category]);
        $services = array_values(array_filter(
            servitech_catalog_dedupe_services($stmt->fetchAll(PDO::FETCH_ASSOC)),
            static fn($service) => (int)($service["active"] ?? 0) === 1
        ));
        $availableServices = [];
        foreach ($services as $service) {
            try {
                $service["catalog"] = servitech_catalog_fetch($pdo, (int)$service["id"], true);
                $availability = servitech_catalog_customer_availability($service, $service["catalog"]);
                if (empty($availability["available"])) continue;
                if (servitech_operational_customer_service_unavailable(
                    $pdo,
                    (string)($service["category"] ?? ""),
                    (string)($service["name"] ?? ""),
                    (int)($service["id"] ?? 0)
                ) !== "") continue;
                $service["catalog_price_range"] = (string)($service["catalog"]["service"]["catalog_price_range"] ?? "");
            } catch (Throwable $e) {
                continue;
            }
            $availableServices[] = $service;
        }
        $services = $availableServices;

        respond([
            "ok" => true,
            "category" => $category,
            "services" => $services
        ]);
    } catch (Throwable $e) {
        respond(["ok" => false, "error" => "Database error"]);
    }
}

if ($action === "detail" && $category) {
    // Fetch a specific service's details
    if (!in_array($category, ["printing", "repair", "installation"], true)) {
        respond(["ok" => false, "error" => "Invalid category"]);
    }

    $serviceId = (int)($_GET["id"] ?? 0);
    if ($serviceId <= 0) {
        respond(["ok" => false, "error" => "Service id required"]);
    }

    try {
        $stmt = $pdo->prepare("
          SELECT id, category, name, description,
                 CASE WHEN active THEN 1 ELSE 0 END AS active, sort_order
          FROM services
          WHERE category = :category AND id = :id AND active = TRUE
          LIMIT 1
        ");
        $stmt->execute([
            ":category" => $category,
            ":id" => $serviceId
        ]);
        $service = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$service) {
            respond(["ok" => false, "error" => "Service not found"]);
        }

        try {
            $service["catalog"] = servitech_catalog_fetch($pdo, (int)$service["id"], true);
            $availability = servitech_catalog_customer_availability($service, $service["catalog"]);
            if (empty($availability["available"])) {
                respond(["ok" => false, "error" => "Service is not currently available"]);
            }
            $manualUnavailable = servitech_operational_customer_service_unavailable(
                $pdo,
                (string)($service["category"] ?? ""),
                (string)($service["name"] ?? ""),
                (int)($service["id"] ?? 0)
            );
            if ($manualUnavailable !== "") {
                respond(["ok" => false, "error" => $manualUnavailable]);
            }
            $service["catalog_price_range"] = (string)($service["catalog"]["service"]["catalog_price_range"] ?? "");
        } catch (Throwable $e) {
            respond(["ok" => false, "error" => "Service is not currently available"]);
        }

        respond([
            "ok" => true,
            "service" => $service
        ]);
    } catch (Throwable $e) {
        respond(["ok" => false, "error" => "Database error"]);
    }
}

respond(["ok" => false, "error" => "Invalid action"]);
