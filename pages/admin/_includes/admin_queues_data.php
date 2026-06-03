<?php
require_once __DIR__ . "/admin_auth.php";
require_once __DIR__ . "/admin_db.php";
require_once __DIR__ . "/url.php";

header("Content-Type: application/json; charset=utf-8");

try {
  $stmt = $pdo->prepare("
    SELECT q.id, q.queue_code, q.category, q.status, q.details, q.price, q.paid_amount, q.created_at, u.fullname,
      p.payment_method, p.reference_number, p.amount,
      q.details->>'estimated_total' AS details_total
    FROM queues q
    JOIN users u ON u.id = q.user_id
    LEFT JOIN LATERAL (
      SELECT payment_method, reference_number, amount
      FROM payments
      WHERE queue_id = q.id
      ORDER BY id DESC
      LIMIT 1
    ) p ON TRUE
    WHERE (
      LOWER(TRIM(q.category)) IN ('online_printorder', 'printing_online', 'xerox', 'rush-id', 'laminating')
      OR (
        LOWER(TRIM(q.category)) = 'printing'
        AND LOWER(TRIM(COALESCE(q.details->>'order_type', ''))) = 'online'
      )
      OR UPPER(TRIM(COALESCE(q.queue_code, ''))) LIKE 'OP%'
    )
      AND UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) = 'QUEUE'
    ORDER BY q.created_at ASC, q.id ASC
  ");
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $formatted = [];
  foreach ($rows as $row) {
    $details = is_string($row["details"]) ? json_decode($row["details"], true) : [];
    $formatted[] = [
      "id" => (int)$row["id"],
      "queue_code" => (string)($row["queue_code"] ?? ""),
      "fullname" => (string)($row["fullname"] ?? ""),
      "status" => (string)($row["status"] ?? "PENDING"),
      "payment_method" => (string)($row["payment_method"] ?? ""),
      "reference_number" => (string)($row["reference_number"] ?? ""),
      "amount" => (float)($row["amount"] ?? 0),
      "price" => $row["price"] !== null ? (float)$row["price"] : null,
      "paid_amount" => (float)($row["paid_amount"] ?? 0)
    ];
  }

  echo json_encode([
    "ok" => true,
    "rows" => $formatted,
    "count" => count($formatted)
  ]);
} catch (Throwable $e) {
  error_log("admin_queues_data error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Database error"]);
}
?>
