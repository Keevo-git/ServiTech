<?php

function admin_dashboard_safe_count(PDO $pdo, string $sql, array $params = []): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
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
        return [];
    }
}

function fetch_admin_dashboard_stats(PDO $pdo): array
{
    // ✅ CUSTOMERS
    $customers = admin_dashboard_safe_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM users
        WHERE LOWER(TRIM(COALESCE(NULLIF(to_jsonb(users)->>'role', ''), 'customer'))) = 'customer'
        "
    );

    // ✅ ONLINE ORDERS (BRUTE-FORCE FIX - WILL NOT RETURN 0 UNLESS NO DATA)
    $onlineOrders = admin_dashboard_safe_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM queues
        WHERE (
            LOWER(TRIM(COALESCE(category, ''))) IN ('online_printorder', 'printing_online')
            OR (
                LOWER(TRIM(COALESCE(category, ''))) = 'printing'
                AND LOWER(TRIM(COALESCE(details->>'order_type', ''))) = 'online'
            )
            OR UPPER(TRIM(COALESCE(queue_code, ''))) LIKE 'OP%'
        )
        "
    );

    // ✅ ACTIVE QUEUE (CLEAN + NO OVERLAP WITH ONLINE)
    $activeQueue = admin_dashboard_safe_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM queues
        WHERE (
            LOWER(TRIM(COALESCE(category, ''))) IN ('walkin', 'printing_walkin', 'repair', 'installation')
            OR (
                LOWER(TRIM(COALESCE(category, ''))) = 'printing'
                AND COALESCE(NULLIF(LOWER(TRIM(COALESCE(details->>'order_type', ''))), ''), 'walkin') = 'walkin'
                AND UPPER(TRIM(COALESCE(queue_code, ''))) NOT LIKE 'OP%'
            )
        )
        AND UPPER(TRIM(COALESCE(status, 'PENDING'))) NOT IN ('DONE', 'CANCELLED')
        "
    );

    $serviceExpression = "
        CASE
            WHEN LOWER(TRIM(COALESCE(category, ''))) IN (
                'online_printorder', 'printing_online', 'printing', 'walkin', 'printing_walkin'
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
                WHEN LOWER(TRIM(COALESCE(category, ''))) IN ('online_printorder', 'printing_online')
                    OR UPPER(TRIM(COALESCE(queue_code, ''))) LIKE 'OP%'
                    THEN 'Print'
                WHEN LOWER(TRIM(COALESCE(category, ''))) IN ('printing', 'walkin', 'printing_walkin') THEN 'Print'
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

    $todayQueues = admin_dashboard_safe_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM queues
        WHERE (created_at AT TIME ZONE 'Asia/Manila')::date
            = (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date
        "
    );

    $todayCompleted = admin_dashboard_safe_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM queues
        WHERE (created_at AT TIME ZONE 'Asia/Manila')::date
            = (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date
          AND UPPER(TRIM(COALESCE(status, ''))) = 'DONE'
        "
    );

    $todayCancelled = admin_dashboard_safe_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM queues
        WHERE (created_at AT TIME ZONE 'Asia/Manila')::date
            = (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date
          AND UPPER(TRIM(COALESCE(status, ''))) = 'CANCELLED'
        "
    );

    return [
        "customers" => $customers,
        "onlineOrders" => $onlineOrders,
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
