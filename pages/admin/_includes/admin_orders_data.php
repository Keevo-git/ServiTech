<?php
require_once __DIR__ . "/admin_auth.php";
require_once __DIR__ . "/admin_db.php";
require_once __DIR__ . "/url.php";

header("Content-Type: application/json; charset=utf-8");

function payment_status_label($method, $queueStatus): string {
  $method = strtolower(trim((string)$method));
  $status = strtoupper(trim((string)$queueStatus));

  if (in_array($status, ["CANCELLED", "CANCELED"], true)) {
    return "Cancelled";
  }

  if ($method === "gcash") {
    return $status === "PENDING" ? "Payment Submitted" : "Accepted";
  }

  if ($method === "cash") {
    return in_array($status, ["ONGOING", "FOR PICK-UP", "DONE"], true) ? "Paid" : "Pay at Store";
  }

  return "-";
}

try {
  $view = strtolower(trim((string)($_GET["view"] ?? "online")));
  if (!in_array($view, ["online", "walkin"], true)) {
    $view = "online";
  }

  if ($view === "walkin") {
    $stmt = $pdo->prepare("
      SELECT q.id, q.queue_code, q.status, q.details, q.price, q.paid_amount, q.created_at, q.completed_at, u.fullname
      FROM queues q
      JOIN users u ON u.id = q.user_id
      WHERE UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) = 'ORDER'
        AND (
          LOWER(TRIM(COALESCE(q.category, ''))) IN ('walkin', 'printing_walkin')
          OR (
            LOWER(TRIM(COALESCE(q.category, ''))) = 'printing'
            AND COALESCE(NULLIF(LOWER(TRIM(COALESCE(q.details->>'order_type', ''))), ''), 'walkin') = 'walkin'
            AND UPPER(TRIM(COALESCE(q.queue_code, ''))) NOT LIKE 'OP%'
          )
        )
      ORDER BY
        CASE
          WHEN UPPER(TRIM(COALESCE(q.status, ''))) IN ('DONE', 'CANCEL', 'CANCELLED', 'CANCELED') THEN 1
          ELSE 0
        END,
        q.created_at ASC,
        q.id ASC
    ");
  } else {
    $stmt = $pdo->prepare("
      SELECT q.id, q.queue_code, q.status, q.details, q.price, q.paid_amount, q.created_at, q.completed_at, u.fullname,
        p.payment_method, p.reference_number, p.status AS payment_status, p.amount,
        q.details->>'estimated_total' AS details_total,
        q.details->>'payment_status' AS details_payment_status
      FROM queues q
      JOIN users u ON u.id = q.user_id
      LEFT JOIN LATERAL (
        SELECT payment_method, reference_number, status, amount
        FROM payments
        WHERE queue_id = q.id
        ORDER BY id DESC
        LIMIT 1
      ) p ON TRUE
      WHERE UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) = 'ORDER'
        AND (
          LOWER(TRIM(COALESCE(q.category, ''))) IN ('online_printorder', 'printing_online')
          OR (
            LOWER(TRIM(COALESCE(q.category, ''))) = 'printing'
            AND LOWER(TRIM(COALESCE(q.details->>'order_type', ''))) = 'online'
          )
          OR UPPER(TRIM(COALESCE(q.queue_code, ''))) LIKE 'OP%'
        )
      ORDER BY
        CASE
          WHEN UPPER(TRIM(COALESCE(q.status, ''))) IN ('DONE', 'CANCEL', 'CANCELLED', 'CANCELED') THEN 1
          ELSE 0
        END,
        q.created_at ASC,
        q.id ASC
    ");
  }

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
      "payment_method" => isset($row["payment_method"]) ? (string)$row["payment_method"] : "",
      "reference_number" => isset($row["reference_number"]) ? (string)$row["reference_number"] : "",
      "payment_status" => isset($row["payment_method"]) ? payment_status_label($row["payment_method"], $row["status"]) : "",
      "amount" => isset($row["amount"]) ? (float)$row["amount"] : 0,
      "price" => isset($row["price"]) && $row["price"] !== null ? (float)$row["price"] : null,
      "paid_amount" => (float)($row["paid_amount"] ?? 0)
    ];
  }

  echo json_encode([
    "ok" => true,
    "rows" => $formatted,
    "count" => count($formatted),
    "view" => $view
  ]);
} catch (Throwable $e) {
  error_log("admin_orders_data error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Database error"]);
}
?>
