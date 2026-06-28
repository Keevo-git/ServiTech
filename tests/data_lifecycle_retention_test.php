<?php

function lifecycle_retention_source(string $path): string
{
    $source = file_get_contents(__DIR__ . "/../" . $path);
    if ($source === false) {
        fwrite(STDERR, "FAIL: Unable to read {$path}\n");
        exit(1);
    }
    return $source;
}

function lifecycle_retention_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$migration = lifecycle_retention_source("database/migrations/20260628_add_data_lifecycle_retention.sql");
$config = lifecycle_retention_source("config/data_lifecycle.php");
$script = lifecycle_retention_source("scripts/run_data_lifecycle_maintenance.php");
$uploadScript = lifecycle_retention_source("scripts/cleanup_upload_retention.php");
$adminDb = lifecycle_retention_source("pages/admin/_includes/admin_db.php");
$dashboardStats = lifecycle_retention_source("pages/admin/_includes/dashboard_stats.php");
$queueList = lifecycle_retention_source("api/queue_list.php");
$serviceStatus = lifecycle_retention_source("pages/customer/custo_service_status.php");
$exportReport = lifecycle_retention_source("pages/admin/order_management/export_report.php");
$readme = lifecycle_retention_source("README.md");

foreach ([
    "archived_at",
    "archived_by",
    "archive_reason",
    "archive_batch_id",
    "data_lifecycle_runs",
    "idx_queues_live_queue_lookup",
    "idx_queues_live_order_lookup",
    "idx_queues_archive_eligible",
    "idx_notifications_soft_deleted_retention",
    "idx_notifications_read_retention",
] as $needle) {
    lifecycle_retention_assert(str_contains($migration, $needle), "Lifecycle migration must include {$needle}.");
}

foreach ([
    '"archive_closed_days" => servitech_lifecycle_int_env("SERVITECH_ARCHIVE_CLOSED_DAYS", 60',
    '"temporary_upload_hours" => servitech_lifecycle_int_env("SERVITECH_TEMP_UPLOAD_RETENTION_HOURS", 24',
    '"closed_upload_days" => servitech_lifecycle_int_env("SERVITECH_CLOSED_UPLOAD_RETENTION_DAYS", 30',
    '"notification_read_days" => servitech_lifecycle_int_env("SERVITECH_NOTIFICATION_READ_DAYS", 45',
    '"soft_delete_purge_days" => servitech_lifecycle_int_env("SERVITECH_SOFT_DELETE_PURGE_DAYS", 30',
    "servitech_lifecycle_is_full_window",
    "servitech_lifecycle_archive_closed_records",
    "servitech_lifecycle_age_recycle_bin",
    "servitech_lifecycle_cleanup_notifications",
    "servitech_lifecycle_cleanup_temporary_auth_data",
] as $needle) {
    lifecycle_retention_assert(str_contains($config, $needle), "Lifecycle config must include {$needle}.");
}

foreach ([
    "--dry-run",
    "--force",
    "servitech-data-lifecycle.lock",
    "servitech_lifecycle_begin_run",
    "servitech_lifecycle_finish_run",
    "Full maintenance runs only from 02:00 to 04:00 Asia/Manila",
] as $needle) {
    lifecycle_retention_assert(str_contains($script, $needle), "Lifecycle CLI must include {$needle}.");
}

foreach ([
    "DELETE FROM queues",
    "DELETE FROM payments",
    "DELETE FROM queue_status_history",
    "DELETE FROM users",
] as $unsafeDelete) {
    lifecycle_retention_assert(!str_contains(strtoupper($config), strtoupper($unsafeDelete)), "Lifecycle config must not hard-delete protected records with {$unsafeDelete}.");
    lifecycle_retention_assert(!str_contains(strtoupper($script), strtoupper($unsafeDelete)), "Lifecycle script must not hard-delete protected records with {$unsafeDelete}.");
}

lifecycle_retention_assert(str_contains($config, "SET archived_at = NOW()"), "Closed records must be archived by metadata, not moved or deleted.");
lifecycle_retention_assert(str_contains($config, "permanently_hidden_at = COALESCE(permanently_hidden_at, NOW())"), "Recycle-bin purge must only hide records from system views.");
lifecycle_retention_assert(str_contains($uploadScript, "data_lifecycle.php"), "Existing upload cleanup must load centralized lifecycle policy.");
lifecycle_retention_assert(str_contains($adminDb, "admin_queue_visibility_predicate") && str_contains($adminDb, "archived_at IS NULL"), "Admin visibility helper must exclude archived rows.");
lifecycle_retention_assert(str_contains($dashboardStats, "archived_at IS NULL"), "Dashboard analytics must exclude archived rows.");
lifecycle_retention_assert(str_contains($queueList, 'scope", "live", "history"') || str_contains($queueList, '["all", "live", "history"]'), "Customer queue API must support scoped live/history reads.");
lifecycle_retention_assert(str_contains($serviceStatus, "scope=live&limit=100") && str_contains($serviceStatus, "scope=history&limit=100"), "Customer status page must fetch active and history lists separately.");
lifecycle_retention_assert(str_contains($exportReport, "include_archived"), "Order exports must expose an explicit archived-history switch.");
lifecycle_retention_assert(str_contains($readme, "Daily at 2:15 AM") && str_contains($readme, "--mode=full --dry-run --force"), "README must document off-peak scheduling and dry-run verification.");
lifecycle_retention_assert(!preg_match('/DELETE\s+FROM\s+users/i', $migration . $config . $script), "Lifecycle implementation must not auto-delete customer accounts.");

echo "Data lifecycle retention checks passed.\n";
