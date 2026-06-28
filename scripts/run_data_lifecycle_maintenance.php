<?php
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit();
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../api/upload_helpers.php";
require_once __DIR__ . "/../config/data_lifecycle.php";

$usage = "Usage: php scripts/run_data_lifecycle_maintenance.php --mode=full|uploads [--dry-run] [--force]";

function lifecycle_cli_option(array $argv, string $name, string $default = ""): string
{
    $prefix = "--" . $name . "=";
    foreach ($argv as $arg) {
        if ($arg === "--" . $name) {
            return "1";
        }
        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
    }
    return $default;
}

function lifecycle_cli_flag(array $argv, string $name): bool
{
    return in_array("--" . $name, $argv, true) || lifecycle_cli_option($argv, $name, "") === "1";
}

function lifecycle_cli_line(string $message): void
{
    echo "[" . (new DateTimeImmutable("now", new DateTimeZone("Asia/Manila")))->format("Y-m-d H:i:s P") . "] " . $message . PHP_EOL;
}

if (in_array("--help", $argv, true) || in_array("-h", $argv, true)) {
    echo $usage . PHP_EOL;
    exit(0);
}

$mode = strtolower(trim(lifecycle_cli_option($argv, "mode", SERVITECH_LIFECYCLE_MODE_FULL)));
if (!in_array($mode, [SERVITECH_LIFECYCLE_MODE_FULL, SERVITECH_LIFECYCLE_MODE_UPLOADS], true)) {
    fwrite(STDERR, "Invalid mode. Use --mode=full or --mode=uploads." . PHP_EOL);
    exit(2);
}

$dryRun = lifecycle_cli_flag($argv, "dry-run");
$force = lifecycle_cli_flag($argv, "force");
$lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "servitech-data-lifecycle.lock";
$lock = @fopen($lockPath, "c");
if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
    lifecycle_cli_line("Data lifecycle maintenance is already running.");
    exit(0);
}

$runId = 0;
$status = "success";
$report = [];

try {
    if (!servitech_lifecycle_maintenance_ready($pdo)) {
        throw new RuntimeException("Apply database/migrations/20260628_add_data_lifecycle_retention.sql before running lifecycle maintenance.");
    }

    $policy = servitech_lifecycle_policy();
    $run = servitech_lifecycle_begin_run($pdo, $mode, $dryRun, $force, $policy);
    $runId = (int)$run["id"];

    lifecycle_cli_line("Lifecycle run {$run["run_token"]} started. mode={$mode}; dry_run=" . ($dryRun ? "yes" : "no"));

    if ($mode === SERVITECH_LIFECYCLE_MODE_FULL && !$force && !servitech_lifecycle_is_full_window()) {
        $status = "skipped";
        $report = [
            "mode" => $mode,
            "dry_run" => $dryRun,
            "skipped" => true,
            "reason" => "Full maintenance runs only from 02:00 to 04:00 Asia/Manila unless --force is supplied.",
            "policy" => $policy,
        ];
        lifecycle_cli_line($report["reason"]);
        servitech_lifecycle_finish_run($pdo, $runId, $status, $report);
        exit(0);
    }

    $report = servitech_lifecycle_run($pdo, $mode, $dryRun);
    $status = $dryRun ? "dry_run" : "success";
    servitech_lifecycle_finish_run($pdo, $runId, $status, $report);

    lifecycle_cli_line("Uploads: request states " . (int)($report["uploads"]["request_states_deleted"] ?? 0)
        . "; temporary " . (int)($report["uploads"]["temporary_deleted"] ?? 0)
        . "; closed-request files " . (int)($report["uploads"]["closed_deleted"] ?? 0) . ".");

    if ($mode === SERVITECH_LIFECYCLE_MODE_FULL) {
        lifecycle_cli_line("Archived closed records: " . (int)($report["archived_closed_records"] ?? 0) . ".");
        lifecycle_cli_line("Recycle-bin records hidden: " . (int)($report["recycle_bin_hidden"] ?? 0) . ".");
        $notifications = (array)($report["notifications_deleted"] ?? []);
        lifecycle_cli_line("Notifications deleted: soft-deleted "
            . (int)($notifications["soft_deleted"] ?? 0)
            . "; read " . (int)($notifications["read"] ?? 0)
            . "; archived unread " . (int)($notifications["archived_unread"] ?? 0) . ".");
        $temporaryAuth = (array)($report["temporary_auth_deleted"] ?? []);
        lifecycle_cli_line("Temporary auth rows deleted: remember tokens "
            . (int)($temporaryAuth["remember_tokens"] ?? 0)
            . "; login attempts " . (int)($temporaryAuth["login_attempts"] ?? 0) . ".");
    }

    if (!empty($report["uploads"]["errors"])) {
        lifecycle_cli_line("Upload cleanup errors: " . implode(", ", (array)$report["uploads"]["errors"]));
        exit(1);
    }

    lifecycle_cli_line("Lifecycle run finished with status {$status}.");
} catch (Throwable $exception) {
    $status = "failed";
    $message = $exception->getMessage();
    if ($runId > 0) {
        try {
            servitech_lifecycle_finish_run($pdo, $runId, $status, $report, $message);
        } catch (Throwable $loggingException) {
            error_log("Lifecycle run logging failed: " . $loggingException->getMessage());
        }
    }
    fwrite(STDERR, "Lifecycle maintenance failed: {$message}" . PHP_EOL);
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
