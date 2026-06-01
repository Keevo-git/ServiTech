<?php
require_once __DIR__ . "/admin_auth.php";
require_once __DIR__ . "/admin_db.php";
require_once __DIR__ . "/url.php";

header("Content-Type: application/json; charset=utf-8");

function payment_status_label($method, $paymentStatus = null, $detailsStatus = null): string {
  $method = strtolower(trim((string)$method));
  $status = strtoupper(trim((string)($paymentStatus ?? $detailsStatus ?? "")));

  if ($method === "gcash") {
    if (in_array($status, ["PENDING", "SUBMITTED", "PENDING VERIFICATION"], true)) {
      return "Payment Submitted";
    }
    if (in_array($status, ["VERIFIED", "PAID", "COMPLETE"], true)) {
      return "Verified / Paid";
    }
    if (in_array($status, ["DECLINED", "REJECTED", "FAILED"], true)) {
      return "Rejected";
    }
  }

  if ($method === "cash") {
    if ($status === "" || $status === "PAY AT STORE") {
      return "Pay at Store";
    }
    if (in_array($status, ["PENDING", "UNPAID"], true)) {
      return "Pending Payment";
    }
    if (in_array($status, ["PAID", "VERIFIED", "COMPLETE", "DONE"], true)) {
      return "Paid";
    }
  }

  return $status !== "" ? ucfirst(strtolower($status)) : "-";
}

try {
  $stmt = $pdo->prepare("
    SELECT q.id, q.queue_code, q.category, q.status, q.details, q.created_at, u.fullname,
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
    WHERE (
      LOWER(TRIM(q.category)) IN ('online_printorder', 'printing_online', 'xerox', 'rush-id', 'laminating')
      OR (
        LOWER(TRIM(q.category)) = 'printing'
        AND LOWER(TRIM(COALESCE(q.details->>'order_type', ''))) = 'online'
      )
      OR UPPER(TRIM(COALESCE(q.queue_code, ''))) LIKE 'OP%'
    )
      AND UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) = 'QUEUE'
    ORDER BY q.created_at ASC
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
      "payment_status" => payment_status_label($row["payment_method"], $row["payment_status"], $row["details_payment_status"]),
      "amount" => (float)($row["amount"] ?? 0)
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
