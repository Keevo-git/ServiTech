<?php
declare(strict_types=1);

function analytics_cycle_log(string $message): void
{
    $line = "[" . date(DATE_ATOM) . "] " . $message . PHP_EOL;
    echo $message . PHP_EOL;
    @file_put_contents(__DIR__ . "/../logs/analytics_cycle.log", $line, FILE_APPEND);
}

function analytics_cycle_month_bounds(DateTimeImmutable $date): array
{
    $start = $date->modify("first day of this month")->setTime(0, 0);
    $end = $date->modify("last day of this month")->setTime(0, 0);
    return [$start->format("Y-m"), $start->format("Y-m-d"), $end->format("Y-m-d")];
}

function analytics_cycle_snapshot_payload(array $analytics): array
{
    return [
        "generated_at" => (new DateTimeImmutable("now", new DateTimeZone("Asia/Manila")))->format(DATE_ATOM),
        "cycle" => $analytics["cycle"] ?? [],
        "summary" => $analytics["summary"] ?? [],
        "longest_waiting_request" => $analytics["longest_waiting_request"] ?? [],
        "status_distribution" => $analytics["status_distribution"] ?? [],
        "status_durations" => $analytics["status_durations"] ?? [],
        "requests_by_service" => $analytics["requests_by_service"] ?? [],
        "requests_by_period" => $analytics["requests_by_period"] ?? [],
        "completed_vs_cancelled" => $analytics["completed_vs_cancelled"] ?? [],
        "service_completion" => $analytics["service_completion"] ?? [],
    ];
}

$dryRun = in_array("--dry-run", $argv, true);
$summary = [
    "warnings_logged" => 0,
    "snapshots_created" => 0,
    "cycles_archived" => 0,
    "cycles_created" => 0,
    "errors" => [],
];

try {
    require_once __DIR__ . "/../config/db.php";
    require_once __DIR__ . "/../pages/super_admin/_includes/super_admin_analytics_data.php";

    $pdo = ($GLOBALS["pdo"] ?? null) instanceof PDO ? $GLOBALS["pdo"] : servitech_db_connect_privileged();
    if (!super_analytics_schema_ready($pdo)) {
        throw new RuntimeException("Analytics cycle schema is not installed.");
    }

    $timezone = new DateTimeZone("Asia/Manila");
    $today = new DateTimeImmutable("today", $timezone);
    [$currentKey, $currentStart, $currentEnd] = analytics_cycle_month_bounds($today);

    $pdo->beginTransaction();

    $activeStmt = $pdo->query("SELECT * FROM analytics_cycles WHERE status = 'active' ORDER BY start_date DESC, id DESC LIMIT 1 FOR UPDATE");
    $activeCycle = $activeStmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($activeCycle)) {
        analytics_cycle_log("No active analytics cycle found; creating {$currentKey}.");
        if (!$dryRun) {
            $insert = $pdo->prepare("
                INSERT INTO analytics_cycles (cycle_key, start_date, end_date, status)
                VALUES (:cycle_key, :start_date, :end_date, 'active')
                ON CONFLICT (cycle_key) DO UPDATE SET status = 'active', updated_at = NOW()
            ");
            $insert->execute([
                ":cycle_key" => $currentKey,
                ":start_date" => $currentStart,
                ":end_date" => $currentEnd,
            ]);
        }
        $summary["cycles_created"]++;
    } else {
        $cycleEnd = new DateTimeImmutable((string)$activeCycle["end_date"], $timezone);
        $daysRemaining = max(0, (int)$today->diff($cycleEnd)->format("%r%a"));
        if (in_array($daysRemaining, [7, 3, 1, 0], true)
            && (int)($activeCycle["last_warning_days_remaining"] ?? -1) !== $daysRemaining) {
            analytics_cycle_log("Analytics cycle {$activeCycle["cycle_key"]} ends in {$daysRemaining} day(s). Export reminder should be shown.");
            if (!$dryRun) {
                $warning = $pdo->prepare("
                    UPDATE analytics_cycles
                    SET last_warning_at = NOW(),
                        last_warning_days_remaining = :days_remaining,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $warning->execute([
                    ":days_remaining" => $daysRemaining,
                    ":id" => (int)$activeCycle["id"],
                ]);
            }
            $summary["warnings_logged"]++;
        }

        if ($today > $cycleEnd) {
            analytics_cycle_log("Analytics cycle {$activeCycle["cycle_key"]} has ended; creating snapshot and rolling to {$currentKey}.");
            $analytics = super_analytics_fetch($pdo, ["cycle_id" => (int)$activeCycle["id"]]);
            $payload = analytics_cycle_snapshot_payload($analytics);

            if (!$dryRun) {
                $snapshot = $pdo->prepare("
                    INSERT INTO analytics_monthly_snapshots (cycle_id, cycle_key, snapshot_json, created_by)
                    VALUES (:cycle_id, :cycle_key, :snapshot_json::jsonb, NULL)
                    ON CONFLICT (cycle_id) DO UPDATE
                    SET snapshot_json = EXCLUDED.snapshot_json,
                        created_at = NOW()
                ");
                $snapshot->execute([
                    ":cycle_id" => (int)$activeCycle["id"],
                    ":cycle_key" => (string)$activeCycle["cycle_key"],
                    ":snapshot_json" => json_encode($payload, JSON_UNESCAPED_SLASHES),
                ]);

                $archive = $pdo->prepare("
                    UPDATE analytics_cycles
                    SET status = 'archived',
                        snapshot_created_at = COALESCE(snapshot_created_at, NOW()),
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $archive->execute([":id" => (int)$activeCycle["id"]]);

                $insertNext = $pdo->prepare("
                    INSERT INTO analytics_cycles (cycle_key, start_date, end_date, status)
                    VALUES (:cycle_key, :start_date, :end_date, 'active')
                    ON CONFLICT (cycle_key) DO UPDATE SET status = 'active', updated_at = NOW()
                ");
                $insertNext->execute([
                    ":cycle_key" => $currentKey,
                    ":start_date" => $currentStart,
                    ":end_date" => $currentEnd,
                ]);
            }

            $summary["snapshots_created"]++;
            $summary["cycles_archived"]++;
            $summary["cycles_created"]++;
        }
    }

    if ($dryRun) {
        $pdo->rollBack();
        analytics_cycle_log("Dry run complete; no cycle changes committed.");
    } else {
        $pdo->commit();
        analytics_cycle_log("Analytics cycle check committed.");
    }
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $summary["errors"][] = $exception->getMessage();
    analytics_cycle_log("Analytics cycle check failed: " . $exception->getMessage());
}

echo "Analytics Cycle Summary" . PHP_EOL;
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($summary["errors"] ? 1 : 0);
