<?php
/**
 * Public API for fetching services data
 * Used by the landing page to display service modals dynamically
 * No authentication required
 */

require_once __DIR__ . "/../config/db.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: public, max-age=300"); // Cache for 5 minutes

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
          SELECT id, category, name, description, price,
                 CASE WHEN active THEN 1 ELSE 0 END AS active, sort_order
          FROM services
          WHERE category = :category AND active = TRUE
          ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute([":category" => $category]);
        $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

    $serviceName = $_GET["service"] ?? "";
    if (!$serviceName) {
        respond(["ok" => false, "error" => "Service name required"]);
    }

    try {
        $stmt = $pdo->prepare("
          SELECT id, category, name, description, price,
                 CASE WHEN active THEN 1 ELSE 0 END AS active, sort_order
          FROM services
          WHERE category = :category AND name = :name AND active = TRUE
          LIMIT 1
        ");
        $stmt->execute([
            ":category" => $category,
            ":name" => $serviceName
        ]);
        $service = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$service) {
            respond(["ok" => false, "error" => "Service not found"]);
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
