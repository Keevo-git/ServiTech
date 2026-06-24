<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/_includes/dashboard_stats.php";

$dashboardStats = fetch_admin_dashboard_stats($pdo);

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
echo json_encode($dashboardStats);
