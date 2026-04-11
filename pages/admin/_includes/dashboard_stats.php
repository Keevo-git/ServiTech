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
    // CUSTOMERS (unchanged)
    $customers = admin_dashboard_safe_count($pdo, "SELECT COUNT(*) FROM users");

    // ✅ ONLINE ORDERS (FIXED - FLEXIBLE MATCHING)
    $onlineOrders = admin_dashboard_safe_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM queues
        WHERE 
            LOWER(TRIM(category)) = 'printing_online'
            OR (
                LOWER(TRIM(category)) = 'printing'
                AND LOWER(TRIM(COALESCE(\"type\", ''))) = 'online'
            )
            OR UPPER(TRIM(COALESCE(queue_code, ''))) LIKE 'OP%'
        "
    );

    // ✅ ACTIVE QUEUE (FIXED - NO OVERLAP)
    $activeQueue = admin_dashboard_safe_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM queues
        WHERE (
            LOWER(TRIM(category)) IN ('walkin', 'printing_walkin', 'repair', 'installation')
            OR (
                LOWER(TRIM(category)) = 'printing'
                AND (
                    \"type\" IS NULL
                    OR LOWER(TRIM(\"type\")) = 'walkin'
                )
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