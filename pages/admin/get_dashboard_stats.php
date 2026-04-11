<?php
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/_includes/dashboard_stats.php";

$dashboardStats = fetch_admin_dashboard_stats($pdo);

// RESPONSE
header("Content-Type: application/json");
echo json_encode($dashboardStats);
