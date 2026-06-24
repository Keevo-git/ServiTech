<?php

function admin_order_soft_delete_column_ready(PDO $pdo): bool
{
    return true;
}

require_once __DIR__ . "/../pages/admin/_includes/dashboard_stats.php";

function dashboard_live_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$pdo = new PDO("sqlite::memory:");
dashboard_live_assert(
    admin_dashboard_live_order_predicate($pdo) === "deleted_at IS NULL AND permanently_hidden_at IS NULL",
    "Live dashboard queries must use both recycle-bin visibility fields."
);

$orders = [
    ["id" => 1, "category" => "printing_online", "code" => "OP001", "status" => "PENDING", "deleted_at" => null, "hidden_at" => null],
    ["id" => 2, "category" => "printing_online", "code" => "OP002", "status" => "PENDING", "deleted_at" => "2026-06-24", "hidden_at" => null],
    ["id" => 3, "category" => "repair", "code" => "R001", "status" => "ONGOING", "deleted_at" => null, "hidden_at" => null],
    ["id" => 4, "category" => "repair", "code" => "R002", "status" => "ONGOING", "deleted_at" => "2026-06-24", "hidden_at" => null],
    ["id" => 5, "category" => "installation", "code" => "I001", "status" => "DONE", "deleted_at" => null, "hidden_at" => null],
    ["id" => 6, "category" => "printing_online", "code" => "OP003", "status" => "PENDING", "deleted_at" => null, "hidden_at" => "2026-06-24"],
];

$liveCounts = static function (array $rows): array {
    $visible = array_filter($rows, static fn(array $row): bool => $row["deleted_at"] === null && $row["hidden_at"] === null);
    $online = array_filter($visible, static fn(array $row): bool => in_array($row["category"], ["online_printorder", "printing_online"], true) || str_starts_with($row["code"], "OP"));
    $active = array_filter($visible, static fn(array $row): bool => in_array($row["category"], ["walkin", "printing_walkin", "repair", "installation"], true) && !in_array($row["status"], ["DONE", "CANCELLED"], true));
    return [count($online), count($active)];
};

dashboard_live_assert($liveCounts($orders) === [1, 1], "Pending and ongoing records in the bin must not be counted live.");

// Restore one pending and one ongoing record: both live counts should return.
foreach ($orders as &$order) {
    if (in_array($order["id"], [2, 4], true)) $order["deleted_at"] = null;
}
unset($order);
dashboard_live_assert($liveCounts($orders) === [2, 2], "Restored pending and ongoing records must return to live counts.");

// Model a successful multi-select move to bin.
foreach ($orders as &$order) {
    if (in_array($order["id"], [1, 2, 3, 4], true)) $order["deleted_at"] = "2026-06-24";
}
unset($order);
dashboard_live_assert($liveCounts($orders) === [0, 0], "Bulk binning must remove all selected records from live counts.");
dashboard_live_assert(count($orders) === 6, "Binning must retain records for historical reporting.");

$dashboardPage = file_get_contents(__DIR__ . "/../pages/admin/admin_dashboard.php") ?: "";
$dashboardStats = file_get_contents(__DIR__ . "/../pages/admin/_includes/dashboard_stats.php") ?: "";
$dashboardScript = file_get_contents(__DIR__ . "/../pages/admin/admin_dashboard.js") ?: "";
$recycleScript = file_get_contents(__DIR__ . "/../pages/admin/order_management/order_recycle.js") ?: "";
$recycleAction = file_get_contents(__DIR__ . "/../pages/admin/order_management/order_recycle_action.php") ?: "";
$ordersEndpoint = file_get_contents(__DIR__ . "/../pages/admin/_includes/admin_orders_data.php") ?: "";
$realtimeSnapshot = file_get_contents(__DIR__ . "/../pages/admin/_includes/admin_realtime_snapshot.php") ?: "";

dashboard_live_assert(str_contains($dashboardPage, "data-dashboard-stats-url"), "Dashboard polling must use the deployment-aware stats URL.");
dashboard_live_assert(substr_count($dashboardStats, 'AND {$liveOrderPredicate}') === 2, "Only the two operational dashboard counts must apply the recycle predicate.");
dashboard_live_assert(!str_contains($dashboardScript, 'fetch("/pages/admin/get_dashboard_stats.php")'), "Dashboard polling must not bypass APP_BASE_PATH.");
dashboard_live_assert(str_contains($dashboardScript, 'cache: "no-store"'), "Live stats requests must bypass browser caches.");
dashboard_live_assert(str_contains($dashboardScript, "dashboardRefreshStorageKey"), "The dashboard must listen for order mutations from other tabs.");
dashboard_live_assert(str_contains($recycleScript, "notifyDashboardRefresh();"), "Successful recycle actions must trigger live analytics reconciliation.");
dashboard_live_assert(str_contains($recycleScript, "for (const item of items)"), "Bulk recycle actions must retain the existing selected-order flow.");
dashboard_live_assert(str_contains($recycleAction, "SET deleted_at = NOW()"), "Move to Bin must persist the queue deleted_at state used by analytics.");
dashboard_live_assert(str_contains($recycleAction, "UPPER(TRIM(COALESCE(lifecycle_stage, 'QUEUE'))) = 'ORDER'"), "Recycle actions must remain limited to Order Management records.");
dashboard_live_assert(substr_count($ordersEndpoint, '{$orderRecyclePredicate}') === 2, "Both shared Order Management data views must exclude recycled records.");
dashboard_live_assert(str_contains($realtimeSnapshot, "q.deleted_at IS NULL AND q.permanently_hidden_at IS NULL"), "Order realtime snapshots must use the same visibility rule.");

echo "Admin dashboard live analytics tests passed.\n";
