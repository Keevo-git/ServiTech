<?php
require_once __DIR__ . "/admin_auth.php";
require_once __DIR__ . "/admin_db.php";
require_once __DIR__ . "/queue_files.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$scope = strtolower(trim((string)($_GET["scope"] ?? "")));
$orderRecyclePredicate = admin_order_soft_delete_column_ready($pdo) ? "AND q.deleted_at IS NULL AND q.permanently_hidden_at IS NULL" : "";
$predicates = [
    "queue_printing" => "
        UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) = 'QUEUE'
        AND (
            LOWER(TRIM(COALESCE(q.category, ''))) IN (
                'online_printorder', 'printing_online', 'printing', 'walkin', 'printing_walkin',
                'xerox', 'rush-id', 'laminating'
            )
            OR UPPER(TRIM(COALESCE(q.queue_code, ''))) LIKE 'OP%'
        )
    ",
    "queue_online" => "
        UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) = 'QUEUE'
        AND (
            LOWER(TRIM(COALESCE(q.category, ''))) IN ('online_printorder', 'printing_online', 'xerox', 'rush-id', 'laminating')
            OR (
                LOWER(TRIM(COALESCE(q.category, ''))) = 'printing'
                AND LOWER(TRIM(COALESCE(q.details->>'order_type', ''))) = 'online'
            )
            OR UPPER(TRIM(COALESCE(q.queue_code, ''))) LIKE 'OP%'
        )
    ",
    "queue_walkin" => "
        UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) = 'QUEUE'
        AND (
            LOWER(TRIM(COALESCE(q.category, ''))) IN ('walkin', 'printing_walkin')
            OR (
                LOWER(TRIM(COALESCE(q.category, ''))) = 'printing'
                AND COALESCE(NULLIF(LOWER(TRIM(COALESCE(q.details->>'order_type', ''))), ''), 'walkin') = 'walkin'
            )
        )
    ",
    "queue_repair" => "
        UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) = 'QUEUE'
        AND LOWER(TRIM(COALESCE(q.category, ''))) = 'repair'
    ",
    "queue_installation" => "
        UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) = 'QUEUE'
        AND LOWER(TRIM(COALESCE(q.category, ''))) = 'installation'
    ",
    "order_printing" => "
        UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) = 'ORDER'
        {$orderRecyclePredicate}
        AND (
            LOWER(TRIM(COALESCE(q.category, ''))) IN (
                'online_printorder', 'printing_online', 'printing', 'walkin', 'printing_walkin'
            )
            OR UPPER(TRIM(COALESCE(q.queue_code, ''))) LIKE 'OP%'
        )
    ",
    "order_online" => "
        UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) = 'ORDER'
        {$orderRecyclePredicate}
        AND (
            LOWER(TRIM(COALESCE(q.category, ''))) IN ('online_printorder', 'printing_online')
            OR (
                LOWER(TRIM(COALESCE(q.category, ''))) = 'printing'
                AND LOWER(TRIM(COALESCE(q.details->>'order_type', ''))) = 'online'
            )
            OR UPPER(TRIM(COALESCE(q.queue_code, ''))) LIKE 'OP%'
        )
    ",
    "order_walkin" => "
        UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) = 'ORDER'
        {$orderRecyclePredicate}
        AND (
            LOWER(TRIM(COALESCE(q.category, ''))) IN ('walkin', 'printing_walkin')
            OR (
                LOWER(TRIM(COALESCE(q.category, ''))) = 'printing'
                AND COALESCE(NULLIF(LOWER(TRIM(COALESCE(q.details->>'order_type', ''))), ''), 'walkin') = 'walkin'
                AND UPPER(TRIM(COALESCE(q.queue_code, ''))) NOT LIKE 'OP%'
            )
        )
    ",
    "order_repair" => "
        UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) = 'ORDER'
        {$orderRecyclePredicate}
        AND LOWER(TRIM(COALESCE(q.category, ''))) = 'repair'
    ",
    "order_installation" => "
        UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) = 'ORDER'
        {$orderRecyclePredicate}
        AND LOWER(TRIM(COALESCE(q.category, ''))) = 'installation'
    ",
];

if (!isset($predicates[$scope])) {
    http_response_code(422);
    echo json_encode(["ok" => false, "error" => "Invalid realtime scope."]);
    exit();
}

try {
    $stmt = $pdo->query("
        SELECT
            q.id,
            q.queue_code,
            q.status,
            q.lifecycle_stage,
            q.completed_at,
            q.updated_at,
            q.price,
            q.paid_amount,
            q.details::text AS details,
            u.fullname,
            u.email AS customer_email,
            COALESCE(NULLIF(to_jsonb(u)->>'contact', ''), NULLIF(to_jsonb(u)->>'contacts', '')) AS customer_phone,
            p.id AS payment_id,
            p.payment_method,
            p.reference_number,
            p.amount
        FROM queues q
        JOIN users u ON u.id = q.user_id
        LEFT JOIN LATERAL (
            SELECT id, payment_method, reference_number, amount
            FROM payments
            WHERE queue_id = q.id
            ORDER BY id DESC
            LIMIT 1
        ) p ON TRUE
        WHERE {$predicates[$scope]}
        ORDER BY q.id ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $signatureJson = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $records = array_map(static function (array $row): array {
        return [
            "id" => (int)($row["id"] ?? 0),
            "status" => strtoupper(trim((string)($row["status"] ?? "PENDING"))),
            "customer" => strtolower(trim((string)($row["fullname"] ?? ""))),
            "customer_email" => strtolower(trim((string)($row["customer_email"] ?? ""))),
            "customer_phone" => strtolower(trim((string)($row["customer_phone"] ?? ""))),
        ];
    }, $rows);

    echo json_encode([
        "ok" => true,
        "scope" => $scope,
        "signature" => hash("sha256", is_string($signatureJson) ? $signatureJson : "[]"),
        "records" => $records,
        "notification_count" => admin_queue_notification_count($pdo),
    ]);
} catch (Throwable $exception) {
    error_log("admin realtime snapshot error: " . $exception->getMessage());
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "Unable to refresh admin data."]);
}
