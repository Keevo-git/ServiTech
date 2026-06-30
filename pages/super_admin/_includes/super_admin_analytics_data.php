<?php

function super_analytics_normalize_status_sql(string $expression): string
{
    return "
        CASE UPPER(REGEXP_REPLACE(TRIM(COALESCE({$expression}, 'PENDING')), '[[:space:]_]+', ' ', 'g'))
            WHEN 'PENDING PAYMENT' THEN 'PENDING'
            WHEN 'FOR PICK UP' THEN 'FOR PICK-UP'
            WHEN 'FOR PICKUP' THEN 'FOR PICK-UP'
            WHEN 'COMPLETED' THEN 'DONE'
            WHEN 'CANCEL' THEN 'CANCELLED'
            WHEN 'CANCELED' THEN 'CANCELLED'
            ELSE UPPER(REGEXP_REPLACE(TRIM(COALESCE({$expression}, 'PENDING')), '[[:space:]_]+', ' ', 'g'))
        END
    ";
}

function super_analytics_service_label_sql(): string
{
    $raw = "COALESCE(
        NULLIF(TRIM(q.details->>'type_of_request'), ''),
        NULLIF(TRIM(q.details->>'service_name_snapshot'), ''),
        NULLIF(TRIM(q.details->>'catalog_service_name'), ''),
        NULLIF(TRIM(q.details->>'service_label'), ''),
        q.category,
        'Service Request'
    )";

    return "
        CASE
            WHEN LOWER({$raw}) IN ('document printing', 'document print', 'online print order') THEN 'Document Printing'
            WHEN LOWER({$raw}) IN ('rush id', 'rush-id') THEN 'Rush ID'
            WHEN LOWER({$raw}) IN ('laminating', 'lamination') THEN 'Lamination'
            WHEN LOWER({$raw}) IN ('xerox', 'photocopy') THEN 'Photocopy'
            WHEN LOWER({$raw}) LIKE '%repair%' THEN 'Repair'
            WHEN LOWER({$raw}) LIKE '%install%' THEN 'Installation'
            ELSE {$raw}
        END
    ";
}

function super_analytics_schema_ready(PDO $pdo): bool
{
    $queueColumns = [
        "queue_code", "status", "category", "details", "created_at", "completed_at", "closed_at",
        "deleted_at", "permanently_hidden_at", "archived_at", "request_created_at", "pending_at",
        "approved_at", "ongoing_at", "for_pickup_at", "done_at", "cancelled_at", "request_source",
    ];
    $placeholders = implode(",", array_fill(0, count($queueColumns), "?"));
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT column_name)
        FROM information_schema.columns
        WHERE table_schema = ANY(current_schemas(false))
          AND table_name = 'queues'
          AND column_name IN ({$placeholders})
    ");
    $stmt->execute($queueColumns);
    if ((int)$stmt->fetchColumn() !== count($queueColumns)) {
        return false;
    }

    foreach (["queue_status_events", "analytics_cycles", "analytics_monthly_snapshots", "analytics_export_logs"] as $table) {
        $stmt = $pdo->prepare("SELECT to_regclass(:table_name) IS NOT NULL");
        $stmt->execute([":table_name" => "public." . $table]);
        if (!(bool)$stmt->fetchColumn()) {
            return false;
        }
    }

    return true;
}

function super_analytics_clean_filter(array $source): array
{
    $datePattern = '/^\d{4}-\d{2}-\d{2}$/';
    $start = trim((string)($source["start_date"] ?? ""));
    $end = trim((string)($source["end_date"] ?? ""));
    if (!preg_match($datePattern, $start)) {
        $start = "";
    }
    if (!preg_match($datePattern, $end)) {
        $end = "";
    }

    return [
        "start_date" => $start,
        "end_date" => $end,
        "service_type" => trim((string)($source["service_type"] ?? "")),
        "status" => strtoupper(trim((string)($source["status"] ?? ""))),
        "payment_method" => strtolower(trim((string)($source["payment_method"] ?? ""))),
        "request_source" => strtolower(trim((string)($source["request_source"] ?? ""))),
        "cycle_id" => max(0, (int)($source["cycle_id"] ?? 0)),
    ];
}

function super_analytics_current_month_cycle(): array
{
    $today = new DateTimeImmutable("now", new DateTimeZone("Asia/Manila"));
    $start = $today->modify("first day of this month")->setTime(0, 0);
    $end = $today->modify("last day of this month")->setTime(0, 0);
    return [
        "id" => 0,
        "cycle_key" => $start->format("Y-m"),
        "start_date" => $start->format("Y-m-d"),
        "end_date" => $end->format("Y-m-d"),
        "status" => "active",
        "snapshot_created_at" => null,
    ];
}

function super_analytics_fetch_cycle(PDO $pdo, array $filters): array
{
    if ((int)($filters["cycle_id"] ?? 0) > 0) {
        $stmt = $pdo->prepare("SELECT * FROM analytics_cycles WHERE id = :id LIMIT 1");
        $stmt->execute([":id" => (int)$filters["cycle_id"]]);
        $cycle = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($cycle)) {
            return $cycle;
        }
    }

    $stmt = $pdo->query("SELECT * FROM analytics_cycles WHERE status = 'active' ORDER BY start_date DESC, id DESC LIMIT 1");
    $cycle = $stmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($cycle)) {
        return $cycle;
    }

    $cycle = super_analytics_current_month_cycle();
    $insert = $pdo->prepare("
        INSERT INTO analytics_cycles (cycle_key, start_date, end_date, status)
        VALUES (:cycle_key, :start_date, :end_date, 'active')
        ON CONFLICT (cycle_key) DO UPDATE SET status = 'active', updated_at = NOW()
        RETURNING *
    ");
    $insert->execute([
        ":cycle_key" => $cycle["cycle_key"],
        ":start_date" => $cycle["start_date"],
        ":end_date" => $cycle["end_date"],
    ]);
    $created = $insert->fetch(PDO::FETCH_ASSOC);
    return is_array($created) ? $created : $cycle;
}

function super_analytics_cycle_days_remaining(array $cycle): int
{
    try {
        $today = new DateTimeImmutable("today", new DateTimeZone("Asia/Manila"));
        $end = new DateTimeImmutable((string)$cycle["end_date"], new DateTimeZone("Asia/Manila"));
        return max(0, (int)$today->diff($end)->format("%r%a"));
    } catch (Throwable) {
        return 0;
    }
}

function super_analytics_cycle_warning_level(int $daysRemaining): string
{
    if (in_array($daysRemaining, [7, 3, 1, 0], true)) {
        return $daysRemaining === 0 ? "reset-day" : "near-reset";
    }
    return $daysRemaining < 7 ? "near-reset" : "";
}

function super_analytics_cycle_export_status(PDO $pdo, int $cycleId): array
{
    if ($cycleId <= 0) {
        return ["exported" => false, "exported_at" => null, "export_type" => ""];
    }

    $stmt = $pdo->prepare("
        SELECT export_type, exported_at
        FROM analytics_export_logs
        WHERE cycle_id = :cycle_id
        ORDER BY exported_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([":cycle_id" => $cycleId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ["exported" => false, "exported_at" => null, "export_type" => ""];
    }

    return [
        "exported" => true,
        "exported_at" => $row["exported_at"] ?? null,
        "export_type" => (string)($row["export_type"] ?? ""),
    ];
}

function super_analytics_previous_cycles(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT id, cycle_key, start_date, end_date, status, snapshot_created_at
        FROM analytics_cycles
        ORDER BY start_date DESC, id DESC
        LIMIT 36
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function super_analytics_base_sql(array $filters, array &$params): string
{
    $status = super_analytics_normalize_status_sql("q.status");
    $service = super_analytics_service_label_sql();
    $where = ["1 = 1"];
    $params = [];

    if (!empty($filters["cycle_start_date"]) && !empty($filters["cycle_end_date"])) {
        $where[] = "request_created_at >= (CAST(:cycle_start_date AS date)::timestamp AT TIME ZONE 'Asia/Manila')";
        $where[] = "request_created_at < ((CAST(:cycle_end_date AS date) + INTERVAL '1 day')::timestamp AT TIME ZONE 'Asia/Manila')";
        $params[":cycle_start_date"] = $filters["cycle_start_date"];
        $params[":cycle_end_date"] = $filters["cycle_end_date"];
    }

    if ($filters["start_date"] !== "") {
        $where[] = "request_created_at >= (CAST(:start_date AS date)::timestamp AT TIME ZONE 'Asia/Manila')";
        $params[":start_date"] = $filters["start_date"];
    }
    if ($filters["end_date"] !== "") {
        $where[] = "request_created_at < ((CAST(:end_date AS date) + INTERVAL '1 day')::timestamp AT TIME ZONE 'Asia/Manila')";
        $params[":end_date"] = $filters["end_date"];
    }
    if ($filters["service_type"] !== "") {
        $where[] = "service_label = :service_type";
        $params[":service_type"] = $filters["service_type"];
    }
    if ($filters["status"] !== "") {
        $where[] = "status_group = :status";
        $params[":status"] = $filters["status"];
    }
    if ($filters["payment_method"] !== "") {
        $where[] = "payment_method = :payment_method";
        $params[":payment_method"] = $filters["payment_method"];
    }
    if ($filters["request_source"] !== "") {
        $where[] = "request_source = :request_source";
        $params[":request_source"] = $filters["request_source"];
    }

    $filterSql = implode("\n          AND ", $where);

    return "
        WITH analytics_raw AS (
            SELECT
                q.id,
                q.queue_code,
                COALESCE(NULLIF(TRIM(q.details->>'customer_name_snapshot'), ''), NULLIF(TRIM(u.fullname), ''), 'Customer') AS customer_name,
                {$service} AS service_label,
                LOWER(TRIM(COALESCE(p.payment_method, q.details->>'payment_method', ''))) AS payment_method,
                LOWER(TRIM(COALESCE(NULLIF(q.request_source, ''), 'online'))) AS request_source,
                {$status} AS status_group,
                COALESCE(q.request_created_at, q.created_at) AS request_created_at,
                q.pending_at,
                q.approved_at,
                q.ongoing_at,
                q.for_pickup_at,
                COALESCE(q.done_at, q.completed_at) AS done_at,
                CASE
                    WHEN LOWER(TRIM(COALESCE(p.payment_method, q.details->>'payment_method', ''))) = 'gcash'
                         AND q.approved_at IS NOT NULL
                    THEN EXTRACT(EPOCH FROM (q.approved_at - COALESCE(q.request_created_at, q.created_at))) / 60.0
                    WHEN q.ongoing_at IS NOT NULL
                    THEN EXTRACT(EPOCH FROM (q.ongoing_at - COALESCE(q.request_created_at, q.created_at))) / 60.0
                    ELSE NULL
                END AS queue_waiting_minutes,
                CASE
                    WHEN q.ongoing_at IS NOT NULL AND COALESCE(q.done_at, q.completed_at) IS NOT NULL
                    THEN EXTRACT(EPOCH FROM (COALESCE(q.done_at, q.completed_at) - q.ongoing_at)) / 60.0
                    ELSE NULL
                END AS service_processing_minutes
            FROM queues q
            LEFT JOIN users u ON u.id = q.user_id
            LEFT JOIN LATERAL (
                SELECT payment_method, reference_number, status
                FROM payments
                WHERE queue_id = q.id
                ORDER BY id DESC
                LIMIT 1
            ) p ON TRUE
            WHERE q.deleted_at IS NULL
              AND q.permanently_hidden_at IS NULL
              AND q.archived_at IS NULL
        ),
        analytics_base AS (
            SELECT *
            FROM analytics_raw
            WHERE {$filterSql}
        )
    ";
}

function super_analytics_fetch_all(PDO $pdo, string $sql, array $params): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function super_analytics_fetch_one(PDO $pdo, string $sql, array $params): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function super_analytics_options(PDO $pdo): array
{
    $filters = super_analytics_clean_filter([]);
    $cte = super_analytics_base_sql($filters, $params);

    return [
        "services" => array_map(static fn($row): string => (string)$row["value"], super_analytics_fetch_all($pdo, "{$cte} SELECT DISTINCT service_label AS value FROM analytics_raw WHERE service_label <> '' ORDER BY service_label", [])),
        "statuses" => array_map(static fn($row): string => (string)$row["value"], super_analytics_fetch_all($pdo, "{$cte} SELECT DISTINCT status_group AS value FROM analytics_raw WHERE status_group <> '' ORDER BY status_group", [])),
        "payment_methods" => array_map(static fn($row): string => (string)$row["value"], super_analytics_fetch_all($pdo, "{$cte} SELECT DISTINCT payment_method AS value FROM analytics_raw WHERE payment_method <> '' ORDER BY payment_method", [])),
        "request_sources" => array_map(static fn($row): string => (string)$row["value"], super_analytics_fetch_all($pdo, "{$cte} SELECT DISTINCT request_source AS value FROM analytics_raw WHERE request_source <> '' ORDER BY request_source", [])),
        "cycles" => super_analytics_previous_cycles($pdo),
    ];
}

function super_analytics_fetch(PDO $pdo, array $filters): array
{
    $filters = super_analytics_clean_filter($filters);
    $cycle = super_analytics_fetch_cycle($pdo, $filters);
    $filters["cycle_id"] = (int)($cycle["id"] ?? 0);
    $filters["cycle_start_date"] = (string)($cycle["start_date"] ?? "");
    $filters["cycle_end_date"] = (string)($cycle["end_date"] ?? "");
    $cte = super_analytics_base_sql($filters, $params);

    $summary = super_analytics_fetch_one($pdo, "{$cte}
        SELECT
            COUNT(*) AS total_requests,
            ROUND(COALESCE(AVG(queue_waiting_minutes), 0)::numeric, 2) AS avg_queue_waiting_minutes,
            ROUND(COALESCE(AVG(service_processing_minutes), 0)::numeric, 2) AS avg_service_processing_minutes,
            COUNT(*) FILTER (WHERE status_group = 'DONE') AS completed_requests,
            COUNT(*) FILTER (WHERE status_group = 'CANCELLED') AS cancelled_requests,
            ROUND(
                CASE WHEN COUNT(*) = 0 THEN 0
                     ELSE (COUNT(*) FILTER (WHERE status_group = 'DONE')::numeric / COUNT(*)::numeric) * 100
                END,
                2
            ) AS completion_rate
        FROM analytics_base
    ", $params);

    $longest = super_analytics_fetch_one($pdo, "{$cte}
        SELECT queue_code, customer_name, service_label, payment_method, status_group,
               ROUND(queue_waiting_minutes::numeric, 2) AS queue_waiting_minutes,
               request_created_at
        FROM analytics_base
        WHERE queue_waiting_minutes IS NOT NULL
        ORDER BY queue_waiting_minutes DESC, request_created_at ASC
        LIMIT 1
    ", $params);

    $statusRows = super_analytics_fetch_all($pdo, "{$cte}
        SELECT status_group, COUNT(*) AS total
        FROM analytics_base
        GROUP BY status_group
        ORDER BY status_group
    ", $params);

    $statusDistribution = [
        "PENDING" => 0,
        "APPROVED" => 0,
        "ONGOING" => 0,
        "FOR PICK-UP" => 0,
        "DONE" => 0,
        "CANCELLED" => 0,
    ];
    foreach ($statusRows as $row) {
        $statusDistribution[(string)$row["status_group"]] = (int)$row["total"];
    }

    $statusDurations = super_analytics_fetch_all($pdo, "{$cte}
        SELECT
            e.status,
            ROUND(AVG(COALESCE(e.duration_minutes, EXTRACT(EPOCH FROM (e.exited_at - e.entered_at)) / 60.0))::numeric, 2) AS avg_minutes
        FROM queue_status_events e
        INNER JOIN analytics_base b ON b.id = e.queue_id
        WHERE e.status IN ('PENDING', 'APPROVED', 'ONGOING', 'FOR PICK-UP', 'DONE', 'CANCELLED')
          AND COALESCE(e.duration_minutes, EXTRACT(EPOCH FROM (e.exited_at - e.entered_at)) / 60.0) IS NOT NULL
        GROUP BY e.status
        ORDER BY CASE e.status
            WHEN 'PENDING' THEN 1 WHEN 'APPROVED' THEN 2 WHEN 'ONGOING' THEN 3
            WHEN 'FOR PICK-UP' THEN 4 WHEN 'DONE' THEN 5 WHEN 'CANCELLED' THEN 6 ELSE 7
        END
    ", $params);

    $byService = super_analytics_fetch_all($pdo, "{$cte}
        SELECT service_label, COUNT(*) AS total
        FROM analytics_base
        GROUP BY service_label
        ORDER BY total DESC, service_label ASC
    ", $params);

    $periods = [];
    foreach (["day" => "YYYY-MM-DD", "week" => "IYYY-\"W\"IW", "month" => "YYYY-MM"] as $period => $format) {
        $periods[$period] = super_analytics_fetch_all($pdo, "{$cte}
            SELECT TO_CHAR(DATE_TRUNC('{$period}', request_created_at AT TIME ZONE 'Asia/Manila'), '{$format}') AS period_label,
                   COUNT(*) AS total
            FROM analytics_base
            GROUP BY period_label
            ORDER BY period_label ASC
        ", $params);
    }

    $completedVsCancelled = super_analytics_fetch_all($pdo, "{$cte}
        SELECT status_group, COUNT(*) AS total
        FROM analytics_base
        WHERE status_group IN ('DONE', 'CANCELLED')
        GROUP BY status_group
        ORDER BY status_group
    ", $params);

    $serviceCompletion = super_analytics_fetch_all($pdo, "{$cte}
        SELECT
            service_label,
            COUNT(*) AS total,
            COUNT(*) FILTER (WHERE status_group = 'DONE') AS completed,
            COUNT(*) FILTER (WHERE status_group = 'CANCELLED') AS cancelled,
            ROUND(
                CASE WHEN COUNT(*) = 0 THEN 0
                     ELSE (COUNT(*) FILTER (WHERE status_group = 'DONE')::numeric / COUNT(*)::numeric) * 100
                END,
                2
            ) AS completion_percentage
        FROM analytics_base
        GROUP BY service_label
        ORDER BY total DESC, service_label ASC
    ", $params);

    $history = super_analytics_fetch_all($pdo, "{$cte}
        SELECT e.queue_code, e.customer_name_snapshot, e.service_type, e.status, e.entered_at,
               e.exited_at, ROUND(e.duration_minutes::numeric, 2) AS duration_minutes,
               e.next_status, e.remarks
        FROM queue_status_events e
        INNER JOIN analytics_base b ON b.id = e.queue_id
        ORDER BY e.entered_at DESC, e.queue_code ASC, e.transition_no ASC
        LIMIT 500
    ", $params);

    $mostRequested = $byService[0] ?? ["service_label" => "-", "total" => 0];

    return [
        "filters" => $filters,
        "cycle" => $cycle,
        "cycle_days_remaining" => super_analytics_cycle_days_remaining($cycle),
        "cycle_warning_level" => super_analytics_cycle_warning_level(super_analytics_cycle_days_remaining($cycle)),
        "cycle_export_status" => super_analytics_cycle_export_status($pdo, (int)($cycle["id"] ?? 0)),
        "summary" => $summary,
        "longest_waiting_request" => $longest,
        "status_distribution" => $statusDistribution,
        "status_durations" => $statusDurations,
        "requests_by_service" => $byService,
        "requests_by_period" => $periods,
        "completed_vs_cancelled" => $completedVsCancelled,
        "service_completion" => $serviceCompletion,
        "history" => $history,
        "most_requested_service" => $mostRequested,
        "options" => super_analytics_options($pdo),
    ];
}

function super_analytics_record_export(PDO $pdo, array $analytics, string $exportType, ?int $exportedBy, array $filters, int $rowCount): void
{
    $cycleId = (int)($analytics["cycle"]["id"] ?? 0);
    if ($cycleId <= 0) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO analytics_export_logs (cycle_id, export_type, exported_by, filters, row_count)
        VALUES (:cycle_id, :export_type, :exported_by, :filters::jsonb, :row_count)
    ");
    $stmt->execute([
        ":cycle_id" => $cycleId,
        ":export_type" => $exportType,
        ":exported_by" => $exportedBy !== null && $exportedBy > 0 ? $exportedBy : null,
        ":filters" => json_encode($filters, JSON_UNESCAPED_SLASHES),
        ":row_count" => max(0, $rowCount),
    ]);
}

function super_analytics_csv_rows(array $analytics): array
{
    $rows = [[
        "Queue ID", "Customer Name", "Service Type", "Status", "Entered At",
        "Exited At", "Duration Min", "Next Status", "Remarks",
    ]];

    foreach ($analytics["history"] as $event) {
        $rows[] = [
            (string)($event["queue_code"] ?? ""),
            (string)($event["customer_name_snapshot"] ?? ""),
            (string)($event["service_type"] ?? ""),
            (string)($event["status"] ?? ""),
            (string)($event["entered_at"] ?? ""),
            (string)($event["exited_at"] ?? ""),
            (string)($event["duration_minutes"] ?? ""),
            (string)($event["next_status"] ?? ""),
            (string)($event["remarks"] ?? ""),
        ];
    }

    return $rows;
}
