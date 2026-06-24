<?php

function admin_dashboard_safe_count(PDO $pdo, string $sql, array $params = []): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log("admin dashboard count query error: " . $e->getMessage());
        return 0;
    }
}

function admin_dashboard_fetch_rows(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("admin dashboard rows query error: " . $e->getMessage());
        return [];
    }
}

function admin_dashboard_live_order_predicate(PDO $pdo, string $tableAlias = ""): string
{
    if (function_exists("admin_order_soft_delete_column_ready")) {
        $columnsReady = admin_order_soft_delete_column_ready($pdo);
    } else {
        try {
            $stmt = $pdo->query("
              SELECT COUNT(DISTINCT column_name)
              FROM information_schema.columns
              WHERE table_schema = ANY(current_schemas(false))
                AND table_name = 'queues'
                AND column_name IN ('deleted_at', 'permanently_hidden_at')
            ");
            $columnsReady = (int)$stmt->fetchColumn() === 2;
        } catch (Throwable $exception) {
            error_log("admin dashboard recycle schema check failed: " . $exception->getMessage());
            $columnsReady = false;
        }
    }

    if (!$columnsReady) {
        return "1 = 1";
    }

    $prefix = $tableAlias !== "" ? rtrim($tableAlias, ".") . "." : "";
    return "{$prefix}deleted_at IS NULL AND {$prefix}permanently_hidden_at IS NULL";
}

function admin_dashboard_sql_alias(string $tableAlias): string
{
    $tableAlias = rtrim(trim($tableAlias), ".");
    if ($tableAlias === "" || !preg_match('/^[a-z_][a-z0-9_]*$/i', $tableAlias)) {
        throw new InvalidArgumentException("Invalid dashboard SQL alias.");
    }
    return $tableAlias;
}

function admin_dashboard_printing_scope_predicate(string $tableAlias = "q"): string
{
    $q = admin_dashboard_sql_alias($tableAlias);
    return "
      (
        LOWER(TRIM(COALESCE({$q}.category, ''))) IN (
          'online_printorder', 'printing_online', 'printing', 'walkin', 'printing_walkin',
          'xerox', 'rush-id', 'laminating'
        )
        OR UPPER(TRIM(COALESCE({$q}.queue_code, ''))) LIKE 'OP%'
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

function fetch_admin_dashboard_stats(PDO $pdo): array
{
    $liveOrderPredicate = admin_dashboard_live_order_predicate($pdo, "q");
    $printingScopePredicate = admin_dashboard_printing_scope_predicate("q");
    $todayCreatedPredicate = admin_dashboard_manila_day_predicate("q.created_at");
    $todayCompletedPredicate = admin_dashboard_manila_day_predicate("q.completed_at");
    $todayClosedPredicate = admin_dashboard_manila_day_predicate("q.closed_at");

    // Registered customer accounts are independent of order recycle state.
    $customers = admin_dashboard_safe_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM users
        WHERE LOWER(TRIM(COALESCE(NULLIF(to_jsonb(users)->>'role', ''), 'customer'))) = 'customer'
        "
    );

    // Exactly matches the visible Print tab in Order Management.
    $printingOrders = admin_dashboard_safe_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM queues q
        WHERE UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) = 'ORDER'
        AND {$printingScopePredicate}
        AND {$liveOrderPredicate}
        "
    );

    // Union of active records visible across all Queue Management tabs.
    $activeQueue = admin_dashboard_safe_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM queues q
        WHERE (
            {$printingScopePredicate}
            OR LOWER(TRIM(COALESCE(q.category, ''))) IN ('repair', 'installation')
        )
        AND UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) = 'QUEUE'
        AND UPPER(TRIM(COALESCE(q.status, 'PENDING'))) NOT IN (
            'DONE', 'COMPLETED', 'CANCEL', 'CANCELLED', 'CANCELED'
        )
        AND {$liveOrderPredicate}
        "
    );

    $serviceExpression = "
        CASE
            WHEN LOWER(TRIM(COALESCE(category, ''))) IN (
                'online_printorder', 'printing_online', 'printing', 'walkin', 'printing_walkin',
                'xerox', 'rush-id', 'laminating'
            )
                OR UPPER(TRIM(COALESCE(queue_code, ''))) LIKE 'OP%'
                THEN 'Print'
            ELSE COALESCE(
                NULLIF(TRIM(details->>'service_name_snapshot'), ''),
                NULLIF(TRIM(details->>'service_label'), ''),
                CASE
                WHEN LOWER(TRIM(COALESCE(category, ''))) = 'repair' THEN 'Repair Service'
                WHEN LOWER(TRIM(COALESCE(category, ''))) = 'installation' THEN 'Installation Service'
                ELSE 'Other Service'
                END
            )
        END
    ";

    // Historical reporting intentionally retains recycled records.
    $mostRequested = admin_dashboard_fetch_rows(
        $pdo,
        "
        SELECT {$serviceExpression} AS label, COUNT(*) AS total
        FROM queues
        GROUP BY label
        ORDER BY total DESC, label ASC
        LIMIT 5
        "
    );

    $serviceMix = admin_dashboard_fetch_rows(
        $pdo,
        "
        SELECT
            CASE
                WHEN LOWER(TRIM(COALESCE(category, ''))) IN (
                    'online_printorder', 'printing_online', 'printing', 'walkin', 'printing_walkin',
                    'xerox', 'rush-id', 'laminating'
                )
                    OR UPPER(TRIM(COALESCE(queue_code, ''))) LIKE 'OP%'
                    THEN 'Print'
                WHEN LOWER(TRIM(COALESCE(category, ''))) = 'repair' THEN 'Repair'
                WHEN LOWER(TRIM(COALESCE(category, ''))) = 'installation' THEN 'Installation'
                ELSE 'Other'
            END AS label,
            COUNT(*) AS total
        FROM queues
        GROUP BY label
        ORDER BY total DESC, label ASC
        "
    );

    // Today's figures describe activity/events, so they also retain recycled records.
    $todayQueues = admin_dashboard_safe_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM queues q
        WHERE {$todayCreatedPredicate}
        "
    );

    $todayCompleted = admin_dashboard_safe_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM queues q
        WHERE {$todayCompletedPredicate}
          AND UPPER(TRIM(COALESCE(q.status, ''))) IN ('DONE', 'COMPLETED')
        "
    );

    $todayCancelled = admin_dashboard_safe_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM queues q
        WHERE {$todayClosedPredicate}
          AND UPPER(TRIM(COALESCE(q.status, ''))) IN ('CANCEL', 'CANCELLED', 'CANCELED')
        "
    );

    return [
        "customers" => $customers,
        "printingOrders" => $printingOrders,
        "onlineOrders" => $printingOrders,
        "activeQueue" => $activeQueue,
        "analytics" => [
            "mostRequested" => $mostRequested,
            "serviceMix" => $serviceMix,
            "today" => [
                "queues" => $todayQueues,
                "completed" => $todayCompleted,
                "cancelled" => $todayCancelled,
            ],
        ],
    ];
}
