<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/_includes/admin_db.php";
require_once __DIR__ . "/_includes/dashboard_stats.php";

$dashboardStats = fetch_admin_dashboard_stats($pdo);
if (!servitech_is_super_admin()) {
    $statusAnalytics = is_array($dashboardStats["analytics"]["status"] ?? null)
        ? $dashboardStats["analytics"]["status"]
        : ["pending" => 0, "approved" => 0, "ongoing" => 0, "forPickup" => 0];
    $todayAnalytics = is_array($dashboardStats["analytics"]["today"] ?? null)
        ? $dashboardStats["analytics"]["today"]
        : ["newRequests" => 0, "completed" => 0, "cancelled" => 0];
    $dashboardStats = [
        "available" => (bool)($dashboardStats["available"] ?? false),
        "error" => (string)($dashboardStats["error"] ?? ""),
        "generatedAt" => (string)($dashboardStats["generatedAt"] ?? ""),
        "activeRequests" => (int)($statusAnalytics["pending"] ?? 0),
        "activeQueue" => (int)($dashboardStats["activeQueue"] ?? 0),
        "visibleOrders" => (int)($dashboardStats["visibleOrders"] ?? 0),
        "analytics" => [
            "status" => $statusAnalytics,
            "today" => $todayAnalytics,
        ],
    ];
}

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
echo json_encode($dashboardStats);
