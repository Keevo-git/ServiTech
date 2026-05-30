<?php
require_once __DIR__ . "/admin_auth.php";
require_once __DIR__ . "/admin_db.php";

header("Content-Type: application/json; charset=utf-8");

try {
  $stmt = $pdo->query("
    SELECT COUNT(*)
    FROM queues q
    LEFT JOIN LATERAL (
      SELECT payment_method, reference_number, status
      FROM payments
      WHERE queue_id = q.id
      ORDER BY id DESC
      LIMIT 1
    ) p ON TRUE
    WHERE UPPER(TRIM(COALESCE(q.status, 'PENDING'))) NOT IN ('DONE', 'CANCELLED', 'CANCELED')
      AND (
        jsonb_typeof(q.details::jsonb->'uploaded_files') = 'array'
        OR NULLIF(TRIM(COALESCE(q.details->>'file_name', '')), '') IS NOT NULL
        OR (
            LOWER(TRIM(COALESCE(p.payment_method, q.details->>'payment_method', ''))) = 'gcash'
            AND NULLIF(TRIM(COALESCE(p.reference_number, q.details->>'reference_number', '')), '') IS NOT NULL
            AND UPPER(TRIM(COALESCE(p.status, q.details->>'payment_status', 'PENDING'))) IN ('PENDING', 'SUBMITTED')
        )
        OR q.created_at >= (NOW() - INTERVAL '1 day')
      )
  ");
  $count = max(0, (int)$stmt->fetchColumn());

  echo json_encode(["ok" => true, "count" => $count]);
} catch (Throwable $e) {
  error_log("admin_notification_count error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(["ok" => false, "count" => 0, "error" => "Error fetching count"]);
}
?>
