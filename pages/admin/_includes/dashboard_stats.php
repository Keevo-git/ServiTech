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

function fetch_admin_dashboard_stats(PDO $pdo): array
{
    // ✅ CUSTOMERS
    $customers = admin_dashboard_safe_count($pdo, "SELECT COUNT(*) FROM users");

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

    return [
        "customers" => $customers,
        "onlineOrders" => $onlineOrders,
        "activeQueue" => $activeQueue,
    ];
}
