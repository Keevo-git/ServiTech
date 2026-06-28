<?php

if (!defined("SERVITECH_LIFECYCLE_MODE_FULL")) {
    define("SERVITECH_LIFECYCLE_MODE_FULL", "full");
    define("SERVITECH_LIFECYCLE_MODE_UPLOADS", "uploads");
}

function servitech_lifecycle_env_value(string $key): string
{
    if (function_exists("servitech_db_raw_env_value")) {
        return servitech_db_raw_env_value($key);
    }

    foreach ([getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null] as $value) {
        if (is_string($value) && trim($value) !== "") {
            return trim($value);
        }
    }
    return "";
}

function servitech_lifecycle_int_env(string $key, int $default, int $minimum, int $maximum): int
{
    $raw = servitech_lifecycle_env_value($key);
    if ($raw === "" || !preg_match('/^-?\d+$/', $raw)) {
        return $default;
    }
    return max($minimum, min($maximum, (int)$raw));
}

function servitech_lifecycle_policy(): array
{
    return [
        "archive_closed_days" => servitech_lifecycle_int_env("SERVITECH_ARCHIVE_CLOSED_DAYS", 60, 30, 3650),
        "temporary_upload_hours" => servitech_lifecycle_int_env("SERVITECH_TEMP_UPLOAD_RETENTION_HOURS", 24, 1, 168),
        "closed_upload_days" => servitech_lifecycle_int_env("SERVITECH_CLOSED_UPLOAD_RETENTION_DAYS", 30, 7, 3650),
        "notification_soft_deleted_days" => servitech_lifecycle_int_env("SERVITECH_NOTIFICATION_SOFT_DELETED_DAYS", 30, 7, 3650),
        "notification_read_days" => servitech_lifecycle_int_env("SERVITECH_NOTIFICATION_READ_DAYS", 45, 7, 3650),
        "notification_archived_unread_days" => servitech_lifecycle_int_env("SERVITECH_NOTIFICATION_ARCHIVED_UNREAD_DAYS", 60, 30, 3650),
        "soft_delete_purge_days" => servitech_lifecycle_int_env("SERVITECH_SOFT_DELETE_PURGE_DAYS", 30, 7, 3650),
        "login_attempt_days" => servitech_lifecycle_int_env("SERVITECH_LOGIN_ATTEMPT_RETENTION_DAYS", 1, 1, 30),
    ];
}

function servitech_lifecycle_table_exists(PDO $pdo, string $table): bool
{
    if (!preg_match('/^[a-z_][a-z0-9_]*$/', $table)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("
          SELECT 1
          FROM information_schema.tables
          WHERE table_schema = ANY(current_schemas(false))
            AND table_name = :table
          LIMIT 1
        ");
        $stmt->execute([":table" => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $exception) {
        return false;
    }
}

function servitech_lifecycle_table_has_columns(PDO $pdo, string $table, array $columns): bool
{
    if (!preg_match('/^[a-z_][a-z0-9_]*$/', $table)) {
        return false;
    }

    $columns = array_values(array_unique(array_filter(array_map(
        static fn($column): string => strtolower(trim((string)$column)),
        $columns
    ))));
    if ($columns === []) {
        return false;
    }

    try {
        $placeholders = implode(",", array_fill(0, count($columns), "?"));
        $stmt = $pdo->prepare("
          SELECT COUNT(DISTINCT column_name)
          FROM information_schema.columns
          WHERE table_schema = ANY(current_schemas(false))
            AND table_name = ?
            AND column_name IN ({$placeholders})
        ");
        $stmt->execute(array_merge([$table], $columns));
        return (int)$stmt->fetchColumn() === count($columns);
    } catch (Throwable $exception) {
        return false;
    }
}

function servitech_lifecycle_maintenance_ready(PDO $pdo): bool
{
    return servitech_lifecycle_table_exists($pdo, "data_lifecycle_runs")
        && servitech_lifecycle_table_has_columns($pdo, "queues", [
            "closed_at", "deleted_at", "permanently_hidden_at",
            "archived_at", "archive_reason", "archive_batch_id",
        ]);
}

function servitech_lifecycle_is_full_window(?DateTimeImmutable $now = null): bool
{
    $timezone = new DateTimeZone("Asia/Manila");
    $now = ($now ?? new DateTimeImmutable("now", $timezone))->setTimezone($timezone);
    $hour = (int)$now->format("G");
    return $hour >= 2 && $hour < 4;
}

function servitech_lifecycle_new_token(): string
{
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable $exception) {
        return substr(hash("sha256", uniqid("servitech-lifecycle-", true)), 0, 32);
    }
}

function servitech_lifecycle_begin_run(PDO $pdo, string $mode, bool $dryRun, bool $forceRun, array $policy): array
{
    $runToken = servitech_lifecycle_new_token();
    $stmt = $pdo->prepare("
      INSERT INTO data_lifecycle_runs (run_token, mode, dry_run, force_run, status, retention_policy)
      VALUES (:run_token, :mode, :dry_run, :force_run, 'running', :policy::jsonb)
      RETURNING id, run_token
    ");
    $stmt->execute([
        ":run_token" => $runToken,
        ":mode" => $mode,
        ":dry_run" => $dryRun ? 1 : 0,
        ":force_run" => $forceRun ? 1 : 0,
        ":policy" => json_encode($policy, JSON_UNESCAPED_SLASHES),
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        "id" => (int)($row["id"] ?? 0),
        "run_token" => (string)($row["run_token"] ?? $runToken),
    ];
}

function servitech_lifecycle_finish_run(PDO $pdo, int $runId, string $status, array $report, string $errorMessage = ""): void
{
    if ($runId <= 0) {
        return;
    }

    $stmt = $pdo->prepare("
      UPDATE data_lifecycle_runs
      SET status = :status,
          finished_at = NOW(),
          report = :report::jsonb,
          error_message = :error_message
      WHERE id = :id
    ");
    $stmt->execute([
        ":status" => $status,
        ":report" => json_encode($report, JSON_UNESCAPED_SLASHES),
        ":error_message" => $errorMessage,
        ":id" => $runId,
    ]);
}

function servitech_lifecycle_count_request_states(int $maximumAgeHours): int
{
    if (!function_exists("servitech_upload_request_state_dir")) {
        return 0;
    }

    $stateDir = servitech_upload_request_state_dir();
    if (!is_dir($stateDir)) {
        return 0;
    }

    $cutoff = time() - (max(1, $maximumAgeHours) * 3600);
    $count = 0;
    foreach (glob($stateDir . DIRECTORY_SEPARATOR . "*.json") ?: [] as $path) {
        $modifiedAt = @filemtime($path);
        if (is_int($modifiedAt) && $modifiedAt < $cutoff) {
            $count++;
        }
    }
    return $count;
}

function servitech_lifecycle_count_orphan_uploads(PDO $pdo, int $minimumAgeHours): int
{
    $stmt = $pdo->prepare("
      SELECT COUNT(*)
      FROM uploads
      WHERE queue_id IS NULL
        AND deleted_at IS NULL
        AND created_at < NOW() - (CAST(:minimum_age_hours AS INTEGER) * INTERVAL '1 hour')
    ");
    $stmt->execute([":minimum_age_hours" => max(1, $minimumAgeHours)]);
    return (int)$stmt->fetchColumn();
}

function servitech_lifecycle_count_closed_uploads(PDO $pdo, int $retentionDays): int
{
    $stmt = $pdo->prepare("
      SELECT COUNT(*)
      FROM uploads u
      INNER JOIN queues q ON q.id = u.queue_id
      WHERE u.queue_id IS NOT NULL
        AND u.deleted_at IS NULL
        AND UPPER(TRIM(COALESCE(q.status, ''))) IN ('DONE', 'COMPLETED', 'CANCEL', 'CANCELLED', 'CANCELED')
        AND q.closed_at IS NOT NULL
        AND q.closed_at <= NOW() - (CAST(:retention_days AS INTEGER) * INTERVAL '1 day')
    ");
    $stmt->execute([":retention_days" => max(1, $retentionDays)]);
    return (int)$stmt->fetchColumn();
}

function servitech_lifecycle_cleanup_uploads(PDO $pdo, array $policy, bool $dryRun = false): array
{
    $temporaryHours = (int)$policy["temporary_upload_hours"];
    $closedDays = (int)$policy["closed_upload_days"];

    if ($dryRun) {
        return [
            "request_states_deleted" => servitech_lifecycle_count_request_states($temporaryHours),
            "temporary_deleted" => servitech_lifecycle_count_orphan_uploads($pdo, $temporaryHours),
            "closed_deleted" => servitech_lifecycle_count_closed_uploads($pdo, $closedDays),
            "errors" => [],
            "dry_run" => true,
        ];
    }

    if (!function_exists("servitech_cleanup_upload_retention")) {
        throw new RuntimeException("Upload retention helpers are not loaded.");
    }

    $result = servitech_cleanup_upload_retention($pdo, $temporaryHours, $closedDays);
    $result["dry_run"] = false;
    return $result;
}

function servitech_lifecycle_archive_closed_records(PDO $pdo, array $policy, bool $dryRun, string $batchId): int
{
    $days = (int)$policy["archive_closed_days"];
    $where = "
      UPPER(TRIM(COALESCE(status, ''))) IN ('DONE', 'COMPLETED', 'CANCEL', 'CANCELLED', 'CANCELED')
      AND closed_at IS NOT NULL
      AND closed_at <= NOW() - (CAST(:retention_days AS INTEGER) * INTERVAL '1 day')
      AND archived_at IS NULL
      AND deleted_at IS NULL
      AND permanently_hidden_at IS NULL
      AND COALESCE(NULLIF(to_jsonb(queues)->>'customer_edit_required', '')::BOOLEAN, FALSE) = FALSE
      AND NULLIF(TRIM(COALESCE(to_jsonb(queues)->>'send_back_message', '')), '') IS NULL
    ";

    if ($dryRun) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM queues WHERE {$where}");
        $stmt->execute([":retention_days" => $days]);
        return (int)$stmt->fetchColumn();
    }

    $stmt = $pdo->prepare("
      UPDATE queues
      SET archived_at = NOW(),
          archive_reason = 'retention:closed-record',
          archive_batch_id = :batch_id,
          updated_at = NOW()
      WHERE {$where}
    ");
    $stmt->execute([
        ":retention_days" => $days,
        ":batch_id" => $batchId,
    ]);
    return $stmt->rowCount();
}

function servitech_lifecycle_age_recycle_bin(PDO $pdo, array $policy, bool $dryRun): int
{
    $days = (int)$policy["soft_delete_purge_days"];
    $where = "
      UPPER(TRIM(COALESCE(lifecycle_stage, 'QUEUE'))) = 'ORDER'
      AND deleted_at IS NOT NULL
      AND permanently_hidden_at IS NULL
      AND deleted_at <= NOW() - (CAST(:retention_days AS INTEGER) * INTERVAL '1 day')
    ";

    if ($dryRun) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM queues WHERE {$where}");
        $stmt->execute([":retention_days" => $days]);
        return (int)$stmt->fetchColumn();
    }

    $stmt = $pdo->prepare("
      UPDATE queues
      SET permanently_hidden_at = COALESCE(permanently_hidden_at, NOW()),
          archive_reason = COALESCE(archive_reason, 'retention:recycle-bin-expired'),
          updated_at = NOW()
      WHERE {$where}
    ");
    $stmt->execute([":retention_days" => $days]);
    return $stmt->rowCount();
}

function servitech_lifecycle_cleanup_notifications(PDO $pdo, array $policy, bool $dryRun): array
{
    $queries = [
        "soft_deleted" => [
            "sql" => "FROM notifications WHERE deleted_at IS NOT NULL AND deleted_at <= NOW() - (CAST(:days AS INTEGER) * INTERVAL '1 day')",
            "days" => (int)$policy["notification_soft_deleted_days"],
        ],
        "read" => [
            "sql" => "FROM notifications WHERE deleted_at IS NULL AND is_read = TRUE AND created_at <= NOW() - (CAST(:days AS INTEGER) * INTERVAL '1 day')",
            "days" => (int)$policy["notification_read_days"],
        ],
        "archived_unread" => [
            "sql" => "
              FROM notifications n
              USING queues q
              WHERE q.id = n.reference_id
                AND n.deleted_at IS NULL
                AND n.is_read = FALSE
                AND n.reference_id IS NOT NULL
                AND n.created_at <= NOW() - (CAST(:days AS INTEGER) * INTERVAL '1 day')
                AND UPPER(TRIM(COALESCE(q.status, ''))) IN ('DONE', 'COMPLETED', 'CANCEL', 'CANCELLED', 'CANCELED')
                AND (q.archived_at IS NOT NULL OR q.permanently_hidden_at IS NOT NULL)
            ",
            "days" => (int)$policy["notification_archived_unread_days"],
        ],
    ];

    $result = [];
    foreach ($queries as $key => $query) {
        if ($dryRun) {
            $countSql = $key === "archived_unread"
                ? "
                  SELECT COUNT(*)
                  FROM notifications n
                  INNER JOIN queues q ON q.id = n.reference_id
                  WHERE n.deleted_at IS NULL
                    AND n.is_read = FALSE
                    AND n.reference_id IS NOT NULL
                    AND n.created_at <= NOW() - (CAST(:days AS INTEGER) * INTERVAL '1 day')
                    AND UPPER(TRIM(COALESCE(q.status, ''))) IN ('DONE', 'COMPLETED', 'CANCEL', 'CANCELLED', 'CANCELED')
                    AND (q.archived_at IS NOT NULL OR q.permanently_hidden_at IS NOT NULL)
                "
                : "SELECT COUNT(*) " . $query["sql"];
            $stmt = $pdo->prepare($countSql);
            $stmt->execute([":days" => $query["days"]]);
            $result[$key] = (int)$stmt->fetchColumn();
            continue;
        }

        $deleteSql = "DELETE " . $query["sql"];
        $stmt = $pdo->prepare($deleteSql);
        $stmt->execute([":days" => $query["days"]]);
        $result[$key] = $stmt->rowCount();
    }

    return $result;
}

function servitech_lifecycle_cleanup_temporary_auth_data(PDO $pdo, array $policy, bool $dryRun): array
{
    $result = ["remember_tokens" => 0, "login_attempts" => 0];

    if (servitech_lifecycle_table_exists($pdo, "remember_tokens")) {
        if ($dryRun) {
            $result["remember_tokens"] = (int)$pdo->query("SELECT COUNT(*) FROM remember_tokens WHERE expires_at <= NOW()")->fetchColumn();
        } else {
            $result["remember_tokens"] = $pdo->exec("DELETE FROM remember_tokens WHERE expires_at <= NOW()");
        }
    }

    if (servitech_lifecycle_table_exists($pdo, "login_attempts")) {
        $days = (int)$policy["login_attempt_days"];
        if ($dryRun) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE attempted_at < NOW() - (CAST(:days AS INTEGER) * INTERVAL '1 day')");
            $stmt->execute([":days" => $days]);
            $result["login_attempts"] = (int)$stmt->fetchColumn();
        } else {
            $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE attempted_at < NOW() - (CAST(:days AS INTEGER) * INTERVAL '1 day')");
            $stmt->execute([":days" => $days]);
            $result["login_attempts"] = $stmt->rowCount();
        }
    }

    return $result;
}

function servitech_lifecycle_run(PDO $pdo, string $mode, bool $dryRun = false): array
{
    $policy = servitech_lifecycle_policy();
    $mode = in_array($mode, [SERVITECH_LIFECYCLE_MODE_FULL, SERVITECH_LIFECYCLE_MODE_UPLOADS], true)
        ? $mode
        : SERVITECH_LIFECYCLE_MODE_FULL;
    $batchId = servitech_lifecycle_new_token();

    $report = [
        "mode" => $mode,
        "dry_run" => $dryRun,
        "batch_id" => $batchId,
        "policy" => $policy,
        "uploads" => servitech_lifecycle_cleanup_uploads($pdo, $policy, $dryRun),
    ];

    if ($mode === SERVITECH_LIFECYCLE_MODE_UPLOADS) {
        return $report;
    }

    $report["archived_closed_records"] = servitech_lifecycle_archive_closed_records($pdo, $policy, $dryRun, $batchId);
    $report["recycle_bin_hidden"] = servitech_lifecycle_age_recycle_bin($pdo, $policy, $dryRun);
    $report["notifications_deleted"] = servitech_lifecycle_cleanup_notifications($pdo, $policy, $dryRun);
    $report["temporary_auth_deleted"] = servitech_lifecycle_cleanup_temporary_auth_data($pdo, $policy, $dryRun);

    return $report;
}
