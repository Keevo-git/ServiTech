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
    admin_dashboard_live_order_predicate($pdo, "q") === "q.deleted_at IS NULL AND q.permanently_hidden_at IS NULL",
    "Live dashboard queries must use both recycle-bin visibility fields."
);

$printingScope = admin_dashboard_printing_scope_predicate("q");
foreach (["online_printorder", "printing_online", "printing", "walkin", "printing_walkin", "xerox", "rush-id", "laminating"] as $category) {
    dashboard_live_assert(str_contains($printingScope, "'{$category}'"), "Printing scope must include {$category} records shown by Print management pages.");
}
dashboard_live_assert(
    admin_dashboard_manila_day_predicate("q.created_at") === "(q.created_at AT TIME ZONE 'Asia/Manila')::date = (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date",
    "Daily metrics must use the Manila calendar-day boundary."
);

$orders = [
    ["id" => 1, "category" => "printing", "code" => "P001", "stage" => "QUEUE", "status" => "PENDING", "created" => "2026-06-24", "completed" => null, "closed" => null, "deleted" => null, "hidden" => null],
    ["id" => 2, "category" => "repair", "code" => "R001", "stage" => "QUEUE", "status" => "ONGOING", "created" => "2026-06-24", "completed" => null, "closed" => null, "deleted" => null, "hidden" => null],
    ["id" => 3, "category" => "installation", "code" => "I001", "stage" => "QUEUE", "status" => "COMPLETED", "created" => "2026-06-23", "completed" => "2026-06-23", "closed" => "2026-06-23", "deleted" => null, "hidden" => null],
    ["id" => 4, "category" => "printing", "code" => "P002", "stage" => "ORDER", "status" => "DONE", "created" => "2026-06-23", "completed" => "2026-06-24", "closed" => "2026-06-24", "deleted" => null, "hidden" => null],
    ["id" => 5, "category" => "printing_online", "code" => "OP001", "stage" => "ORDER", "status" => "CANCELED", "created" => "2026-06-23", "completed" => null, "closed" => "2026-06-24", "deleted" => null, "hidden" => null],
    ["id" => 6, "category" => "printing", "code" => "P003", "stage" => "ORDER", "status" => "PENDING", "created" => "2026-06-22", "completed" => null, "closed" => null, "deleted" => "2026-06-24", "hidden" => null],
    ["id" => 7, "category" => "xerox", "code" => "P004", "stage" => "ORDER", "status" => "DONE", "created" => "2026-06-22", "completed" => "2026-06-22", "closed" => "2026-06-22", "deleted" => null, "hidden" => null],
    ["id" => 8, "category" => "repair", "code" => "R002", "stage" => "ORDER", "status" => "ONGOING", "created" => "2026-06-22", "completed" => null, "closed" => null, "deleted" => null, "hidden" => null],
];

$metrics = static function (array $rows): array {
    $today = "2026-06-24";
    $printCategories = ["online_printorder", "printing_online", "printing", "walkin", "printing_walkin", "xerox", "rush-id", "laminating"];
    $isPrint = static fn(array $row): bool => in_array($row["category"], $printCategories, true) || str_starts_with($row["code"], "OP");
    $visible = static fn(array $row): bool => $row["deleted"] === null && $row["hidden"] === null;
    $terminal = ["DONE", "COMPLETED", "CANCEL", "CANCELLED", "CANCELED"];

    return [
        "printingOrders" => count(array_filter($rows, static fn(array $row): bool => $visible($row) && $row["stage"] === "ORDER" && $isPrint($row))),
        "activeQueue" => count(array_filter($rows, static fn(array $row): bool => $visible($row) && $row["stage"] === "QUEUE" && ($isPrint($row) || in_array($row["category"], ["repair", "installation"], true)) && !in_array($row["status"], $terminal, true))),
        "newToday" => count(array_filter($rows, static fn(array $row): bool => $row["created"] === $today)),
        "completedToday" => count(array_filter($rows, static fn(array $row): bool => $row["completed"] === $today && in_array($row["status"], ["DONE", "COMPLETED"], true))),
        "cancelledToday" => count(array_filter($rows, static fn(array $row): bool => $row["closed"] === $today && in_array($row["status"], ["CANCEL", "CANCELLED", "CANCELED"], true))),
        "historical" => count($rows),
    ];
};

$initial = $metrics($orders);
dashboard_live_assert($initial === ["printingOrders" => 3, "activeQueue" => 2, "newToday" => 2, "completedToday" => 1, "cancelledToday" => 1, "historical" => 8], "Initial card rules must distinguish Order, Queue, daily-event, and historical scopes.");

// Case A: a new print queue increments Active Queue and today's new requests.
$orders[] = ["id" => 9, "category" => "printing", "code" => "P005", "stage" => "QUEUE", "status" => "PENDING", "created" => "2026-06-24", "completed" => null, "closed" => null, "deleted" => null, "hidden" => null];
$created = $metrics($orders);
dashboard_live_assert($created["printingOrders"] === 3 && $created["activeQueue"] === 3 && $created["newToday"] === 3, "A same-day queue must affect Today and Active Queue without appearing in Order Management early.");

// Case E: completion moves the record from Queue to Order and records today's event.
foreach ($orders as &$order) {
    if ($order["id"] === 9) {
        $order["stage"] = "ORDER";
        $order["status"] = "DONE";
        $order["completed"] = "2026-06-24";
        $order["closed"] = "2026-06-24";
    }
}
unset($order);
$completed = $metrics($orders);
dashboard_live_assert($completed["printingOrders"] === 4 && $completed["activeQueue"] === 2 && $completed["completedToday"] === 2, "Completion must move a print record between the correct live cards and use completed_at for Today.");

// Cases D/F: binning removes only the operational Order count, not daily/all-time history.
foreach ($orders as &$order) {
    if ($order["id"] === 9) $order["deleted"] = "2026-06-24";
}
unset($order);
$binned = $metrics($orders);
dashboard_live_assert($binned["printingOrders"] === 3 && $binned["activeQueue"] === 2, "Binning must remove an order only from applicable operational counts.");
dashboard_live_assert($binned["newToday"] === 3 && $binned["completedToday"] === 2 && $binned["historical"] === 9, "Binning must preserve today's event history and all-time analytics.");

$dashboardPage = file_get_contents(__DIR__ . "/../pages/admin/admin_dashboard.php") ?: "";
$dashboardStats = file_get_contents(__DIR__ . "/../pages/admin/_includes/dashboard_stats.php") ?: "";
$dashboardScript = file_get_contents(__DIR__ . "/../pages/admin/admin_dashboard.js") ?: "";
$recycleScript = file_get_contents(__DIR__ . "/../pages/admin/order_management/order_recycle.js") ?: "";
$recycleAction = file_get_contents(__DIR__ . "/../pages/admin/order_management/order_recycle_action.php") ?: "";
$ordersEndpoint = file_get_contents(__DIR__ . "/../pages/admin/_includes/admin_orders_data.php") ?: "";
$realtimeSnapshot = file_get_contents(__DIR__ . "/../pages/admin/_includes/admin_realtime_snapshot.php") ?: "";
$printOrdersPage = file_get_contents(__DIR__ . "/../pages/admin/order_management/printM.php") ?: "";

dashboard_live_assert(str_contains($dashboardPage, "data-dashboard-stats-url"), "Dashboard polling must use the deployment-aware stats URL.");
dashboard_live_assert(substr_count($dashboardStats, 'AND {$liveOrderPredicate}') === 2, "Only the two operational dashboard counts must apply the recycle predicate.");
dashboard_live_assert(str_contains($dashboardStats, "UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) = 'ORDER'"), "Printing Orders must be scoped to Order Management.");
dashboard_live_assert(str_contains($dashboardStats, "UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) = 'QUEUE'"), "Active Queue must be scoped to Queue Management.");
dashboard_live_assert(str_contains($dashboardStats, 'admin_dashboard_manila_day_predicate("q.created_at")'), "New requests must use created_at in Manila.");
dashboard_live_assert(str_contains($dashboardStats, 'admin_dashboard_manila_day_predicate("q.completed_at")'), "Completed Today must use completed_at in Manila.");
dashboard_live_assert(str_contains($dashboardStats, 'admin_dashboard_manila_day_predicate("q.closed_at")'), "Cancelled Today must use closed_at in Manila.");
dashboard_live_assert(str_contains($dashboardPage, "Operations Analytics") && str_contains($dashboardPage, '>All-time</span>') && str_contains($dashboardPage, "Activity by Manila calendar day"), "Dashboard labels must disclose operational, historical, and today-only scopes.");
dashboard_live_assert(!str_contains($dashboardScript, 'fetch("/pages/admin/get_dashboard_stats.php")'), "Dashboard polling must not bypass APP_BASE_PATH.");
dashboard_live_assert(str_contains($dashboardScript, 'cache: "no-store"'), "Live stats requests must bypass browser caches.");
dashboard_live_assert(str_contains($dashboardScript, "dashboardRefreshStorageKey"), "The dashboard must listen for order mutations from other tabs.");
dashboard_live_assert(str_contains($recycleScript, "notifyDashboardRefresh();"), "Successful recycle actions must trigger live analytics reconciliation.");
dashboard_live_assert(str_contains($recycleScript, "for (const item of items)"), "Bulk recycle actions must retain the existing selected-order flow.");
dashboard_live_assert(str_contains($recycleAction, "SET deleted_at = NOW()"), "Move to Bin must persist the queue deleted_at state used by analytics.");
dashboard_live_assert(str_contains($recycleAction, "UPPER(TRIM(COALESCE(lifecycle_stage, 'QUEUE'))) = 'ORDER'"), "Recycle actions must remain limited to Order Management records.");
dashboard_live_assert(substr_count($ordersEndpoint, '{$orderRecyclePredicate}') === 2, "Both shared Order Management data views must exclude recycled records.");
dashboard_live_assert(str_contains($realtimeSnapshot, "q.deleted_at IS NULL AND q.permanently_hidden_at IS NULL"), "Order realtime snapshots must use the same visibility rule.");
dashboard_live_assert(str_contains($printOrdersPage, "'xerox', 'rush-id', 'laminating'"), "Order Management Print must retain print-side categories after queue rollover.");

echo "Admin dashboard live analytics tests passed.\n";
