<?php

/**
 * Admin analytics source of truth.
 *
 * Every request metric starts from the same visible_managed CTE:
 * - only records represented by Queue/Order Management are eligible;
 * - recycled and permanently hidden records are always excluded;
 * - legacy status/category spellings are normalized once.
 */

function admin_dashboard_sql_alias(string $tableAlias): string
{
    $tableAlias = rtrim(trim($tableAlias), ".");
    if ($tableAlias === "" || !preg_match('/^[a-z_][a-z0-9_]*$/i', $tableAlias)) {
        throw new InvalidArgumentException("Invalid dashboard SQL alias.");
    }
    return $tableAlias;
}

function admin_dashboard_visibility_predicate(string $tableAlias = "q"): string
{
    $q = admin_dashboard_sql_alias($tableAlias);
    return "{$q}.deleted_at IS NULL AND {$q}.permanently_hidden_at IS NULL";
}

function admin_dashboard_printing_scope_predicate(string $tableAlias = "q"): string
{
    $q = admin_dashboard_sql_alias($tableAlias);
    return "
      (
        LOWER(TRIM(COALESCE({$q}.category, ''))) IN (
          'online_printorder', 'printing_online', 'printing', 'walkin', 'printing_walkin',
          'xerox', 'photocopy', 'rush-id', 'laminating', 'scanning'
        )
        OR UPPER(TRIM(COALESCE({$q}.queue_code, ''))) LIKE 'OP%'
      )
    ";
}

function admin_dashboard_managed_scope_predicate(string $tableAlias = "q"): string
{
    $q = admin_dashboard_sql_alias($tableAlias);
    $printing = admin_dashboard_printing_scope_predicate($q);
    return "
      (
        {$printing}
        OR LOWER(TRIM(COALESCE({$q}.category, ''))) IN ('repair', 'installation')
      )
    ";
}

function admin_dashboard_manila_day_predicate(string $timestampExpression): string
{
    if (!preg_match('/^[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*$/i', $timestampExpression)) {
        throw new InvalidArgumentException("Invalid dashboard timestamp expression.");
    }
    return "({$timestampExpression} AT TIME ZONE 'Asia/Manila')::date = (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date";
}

function admin_dashboard_normalized_status_expression(string $tableAlias = "q"): string
{
    $q = admin_dashboard_sql_alias($tableAlias);
    return "
      CASE UPPER(REGEXP_REPLACE(TRIM(COALESCE({$q}.status, 'PENDING')), '[[:space:]_]+', ' ', 'g'))
        WHEN 'PENDING PAYMENT' THEN 'PENDING'
        WHEN 'FOR PICK UP' THEN 'FOR PICK-UP'
        WHEN 'FOR PICKUP' THEN 'FOR PICK-UP'
        WHEN 'COMPLETED' THEN 'DONE'
        WHEN 'CANCEL' THEN 'CANCELLED'
        WHEN 'CANCELED' THEN 'CANCELLED'
        ELSE UPPER(REGEXP_REPLACE(TRIM(COALESCE({$q}.status, 'PENDING')), '[[:space:]_]+', ' ', 'g'))
      END
    ";
}

function admin_dashboard_category_expression(string $tableAlias = "q"): string
{
    $q = admin_dashboard_sql_alias($tableAlias);
    $printing = admin_dashboard_printing_scope_predicate($q);
    return "
      CASE
        WHEN {$printing} THEN 'Print'
        WHEN LOWER(TRIM(COALESCE({$q}.category, ''))) = 'repair' THEN 'Repair'
        WHEN LOWER(TRIM(COALESCE({$q}.category, ''))) = 'installation' THEN 'Installation'
        ELSE 'Other'
      END
    ";
}

function admin_dashboard_service_expression(string $tableAlias = "q"): string
{
    $q = admin_dashboard_sql_alias($tableAlias);
    $rawService = "COALESCE(
      NULLIF(TRIM({$q}.details->>'service_name_snapshot'), ''),
      NULLIF(TRIM({$q}.details->>'catalog_service_name'), ''),
      NULLIF(TRIM({$q}.details->>'service_label'), ''),
      CASE
        WHEN LOWER(TRIM(COALESCE({$q}.category, ''))) IN ('repair') THEN 'Unspecified Repair'
        WHEN LOWER(TRIM(COALESCE({$q}.category, ''))) IN ('installation') THEN 'Unspecified Installation'
        ELSE 'Document Print'
      END
    )";
    $withoutLegacyPrice = "TRIM(REGEXP_REPLACE({$rawService}, '[[:space:]]+[—–-][[:space:]]+\\(?₱.*$', '', 'i'))";

    return "
      CASE
        WHEN LOWER({$withoutLegacyPrice}) IN (
          'document printing', 'document print', 'online print order',
          'walk-in document printing', 'walk-in document print', 'walk-in printing',
          'walkin printing', 'print walk-in'
        )
          OR (
            LOWER({$withoutLegacyPrice}) LIKE '%document%'
            AND LOWER({$withoutLegacyPrice}) LIKE '%print%'
          )
          OR LOWER({$withoutLegacyPrice}) LIKE '%print order%'
          THEN 'Document Print'
        WHEN LOWER({$withoutLegacyPrice}) IN ('xerox', 'photocopy') THEN 'Photocopy'
        WHEN LOWER({$withoutLegacyPrice}) IN ('rush id', 'rush-id') THEN 'Rush ID'
        WHEN LOWER({$withoutLegacyPrice}) = 'laminating' THEN 'Laminating'
        WHEN LOWER({$withoutLegacyPrice}) = 'scanning' THEN 'Scanning'
        ELSE {$withoutLegacyPrice}
      END
    ";
}

function admin_dashboard_visible_managed_cte(): string
{
    $visibility = admin_dashboard_visibility_predicate("q");
    $managedScope = admin_dashboard_managed_scope_predicate("q");
    $status = admin_dashboard_normalized_status_expression("q");
    $category = admin_dashboard_category_expression("q");
    $service = admin_dashboard_service_expression("q");

    return "
      WITH visible_managed AS (
        SELECT
          q.id,
          q.created_at,
          q.completed_at,
          q.closed_at,
          UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) AS lifecycle_stage,
          {$status} AS status_group,
          {$category} AS category_group,
          {$service} AS service_label
        FROM queues q
        WHERE {$visibility}
          AND {$managedScope}
      )
    ";
}

function admin_dashboard_schema_ready(PDO $pdo): bool
{
    $required = [
        "status", "category", "details", "created_at", "completed_at", "closed_at",
        "lifecycle_stage", "deleted_at", "permanently_hidden_at",
    ];
    $placeholders = implode(",", array_fill(0, count($required), "?"));
    $stmt = $pdo->prepare("
      SELECT COUNT(DISTINCT column_name)
      FROM information_schema.columns
      WHERE table_schema = ANY(current_schemas(false))
        AND table_name = 'queues'
        AND column_name IN ({$placeholders})
    ");
    $stmt->execute($required);
    return (int)$stmt->fetchColumn() === count($required);
}

function admin_dashboard_empty_stats(string $error = ""): array
{
    return [
        "available" => $error === "",
        "error" => $error,
        "activeRequests" => 0,
        "activeQueue" => 0,
        "visibleOrders" => 0,
        "analytics" => [
            "status" => ["pending" => 0, "approved" => 0, "ongoing" => 0, "forPickup" => 0],
            "today" => ["newRequests" => 0, "completed" => 0, "cancelled" => 0],
            "categoryMix" => [
                ["label" => "Print", "total" => 0],
                ["label" => "Repair", "total" => 0],
                ["label" => "Installation", "total" => 0],
            ],
            "topServices" => [],
            "topServicesPeriodDays" => 30,
        ],
    ];
}

function fetch_admin_dashboard_stats(PDO $pdo): array
{
    try {
        if (!admin_dashboard_schema_ready($pdo)) {
            throw new RuntimeException("Required queue analytics migrations are not installed.");
        }

        $cte = admin_dashboard_visible_managed_cte();
        $activeStatuses = "('PENDING', 'APPROVED', 'ONGOING', 'FOR PICK-UP')";
        $todayCreated = admin_dashboard_manila_day_predicate("v.created_at");
        $todayCompleted = admin_dashboard_manila_day_predicate("v.completed_at");
        $todayClosed = admin_dashboard_manila_day_predicate("v.closed_at");

        $pdo->beginTransaction();
        $pdo->exec("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY");

        $summaryStmt = $pdo->query("
          {$cte}
          SELECT
            COUNT(*) FILTER (WHERE v.status_group IN {$activeStatuses}) AS active_requests,
            COUNT(*) FILTER (
              WHERE v.lifecycle_stage = 'QUEUE' AND v.status_group IN {$activeStatuses}
            ) AS active_queue,
            COUNT(*) FILTER (WHERE v.lifecycle_stage = 'ORDER') AS visible_orders,
            COUNT(*) FILTER (WHERE v.status_group = 'PENDING') AS pending,
            COUNT(*) FILTER (WHERE v.status_group = 'APPROVED') AS approved,
            COUNT(*) FILTER (WHERE v.status_group = 'ONGOING') AS ongoing,
            COUNT(*) FILTER (WHERE v.status_group = 'FOR PICK-UP') AS for_pickup,
            COUNT(*) FILTER (WHERE {$todayCreated}) AS new_today,
            COUNT(*) FILTER (WHERE v.status_group = 'DONE' AND {$todayCompleted}) AS completed_today,
            COUNT(*) FILTER (WHERE v.status_group = 'CANCELLED' AND {$todayClosed}) AS cancelled_today
          FROM visible_managed v
        ");
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $categoryStmt = $pdo->query("
          {$cte}
          SELECT v.category_group AS label, COUNT(*) AS total
          FROM visible_managed v
          WHERE v.status_group IN {$activeStatuses}
          GROUP BY v.category_group
          ORDER BY
            CASE v.category_group WHEN 'Print' THEN 1 WHEN 'Repair' THEN 2 WHEN 'Installation' THEN 3 ELSE 4 END
        ");
        $categoryRows = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

        $topServicesStmt = $pdo->query("
          {$cte}
          SELECT v.service_label AS label, COUNT(*) AS total
          FROM visible_managed v
          WHERE v.created_at >= (
            ((CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date - 29)
            AT TIME ZONE 'Asia/Manila'
          )
          GROUP BY v.service_label
          ORDER BY total DESC, v.service_label ASC
          LIMIT 5
        ");
        $topServices = $topServicesStmt->fetchAll(PDO::FETCH_ASSOC);

        $pdo->commit();

        $categoryTotals = ["Print" => 0, "Repair" => 0, "Installation" => 0];
        foreach ($categoryRows as $row) {
            $label = trim((string)($row["label"] ?? "Other"));
            $categoryTotals[$label] = (int)($row["total"] ?? 0);
        }
        $categoryMix = [];
        foreach ($categoryTotals as $label => $total) {
            if ($label === "Other" && $total === 0) {
                continue;
            }
            $categoryMix[] = ["label" => $label, "total" => $total];
        }

        return [
            "available" => true,
            "error" => "",
            "generatedAt" => (new DateTimeImmutable("now", new DateTimeZone("Asia/Manila")))->format(DATE_ATOM),
            "activeRequests" => (int)($summary["active_requests"] ?? 0),
            "activeQueue" => (int)($summary["active_queue"] ?? 0),
            "visibleOrders" => (int)($summary["visible_orders"] ?? 0),
            "analytics" => [
                "status" => [
                    "pending" => (int)($summary["pending"] ?? 0),
                    "approved" => (int)($summary["approved"] ?? 0),
                    "ongoing" => (int)($summary["ongoing"] ?? 0),
                    "forPickup" => (int)($summary["for_pickup"] ?? 0),
                ],
                "today" => [
                    "newRequests" => (int)($summary["new_today"] ?? 0),
                    "completed" => (int)($summary["completed_today"] ?? 0),
                    "cancelled" => (int)($summary["cancelled_today"] ?? 0),
                ],
                "categoryMix" => $categoryMix,
                "topServices" => array_map(static fn(array $row): array => [
                    "label" => trim((string)($row["label"] ?? "Service")),
                    "total" => (int)($row["total"] ?? 0),
                ], $topServices),
                "topServicesPeriodDays" => 30,
            ],
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("admin dashboard analytics error: " . $exception->getMessage());
        return admin_dashboard_empty_stats("Analytics are temporarily unavailable. No fallback totals are being shown.");
    }
}
