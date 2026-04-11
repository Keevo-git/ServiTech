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

function admin_dashboard_text_sql(string $column): string
{
    return "LOWER(TRIM(COALESCE({$column}, '')))";
}

function admin_dashboard_status_sql(string $column): string
{
    return "UPPER(TRIM(COALESCE({$column}, 'PENDING')))";
}

function fetch_admin_dashboard_stats(PDO $pdo): array
{
    $categorySql = admin_dashboard_text_sql("category");
    $typeSql = admin_dashboard_text_sql("\"type\"");
    $statusSql = admin_dashboard_status_sql("status");
    $queueCodeSql = "UPPER(TRIM(COALESCE(queue_code, '')))";

    $customers = admin_dashboard_safe_count($pdo, "SELECT COUNT(*) FROM users");

    $onlineOrders = admin_dashboard_safe_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM queues
        WHERE {$categorySql} = :online_category
          AND (
            {$typeSql} = :online_type
            OR {$queueCodeSql} LIKE :online_prefix
          )
          AND {$statusSql} != :cancelled_status
        ",
        [
            ":online_category" => "printing",
            ":online_type" => "online",
            ":online_prefix" => "OP%",
            ":cancelled_status" => "CANCELLED",
        ]
    );

    $activeQueue = admin_dashboard_safe_count(
        $pdo,
        "
        SELECT COUNT(*)
        FROM queues
        WHERE {$categorySql} IN ('printing_walkin', 'repair', 'installation')
          AND {$statusSql} NOT IN ('DONE', 'CANCELLED')
        "
    );

    return [
        "customers" => $customers,
        "onlineOrders" => $onlineOrders,
        "activeQueue" => $activeQueue,
    ];
}
