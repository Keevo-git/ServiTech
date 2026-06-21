<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/app.php";
require_once __DIR__ . "/../config/join_queue_flow.php";

servitech_enforce_csrf_token(false);

$userId = (int)($_SESSION["user_id"] ?? 0);
if ($userId <= 0) {
  header("Location: " . servitech_url("/auth/log_in.php"));
  exit();
}
if (!servitech_is_customer()) {
  http_response_code(403);
  exit("Customer access required.");
}

$queueId = (int)($_POST["queue_id"] ?? 0);
$referenceNumber = trim((string)($_POST["reference_number"] ?? ""));
$paymentUrl = servitech_url("/pages/customer/custo_service_payment.php?queue_id=" . $queueId);

function service_payment_fail(string $message, string $paymentUrl): void {
  $_SESSION["service_payment_flash_error"] = $message;
  header("Location: " . $paymentUrl);
  exit();
}

if ($queueId <= 0) service_payment_fail("The queue/order could not be found.", $paymentUrl);
if (!preg_match('/^\d{13}$/', $referenceNumber)) {
  service_payment_fail("Enter the 13-digit GCash reference number.", $paymentUrl);
}

try {
  $pdo->beginTransaction();
  $stmt = $pdo->prepare("
    SELECT q.id, q.queue_code, q.status, q.details, p.id AS payment_id,
      p.payment_method, p.status AS payment_status
    FROM queues q
    JOIN LATERAL (
      SELECT id, payment_method, status
      FROM payments
      WHERE queue_id = q.id
      ORDER BY id DESC
      LIMIT 1
    ) p ON TRUE
    WHERE q.id = :queue_id AND q.user_id = :user_id
    LIMIT 1
    FOR UPDATE OF q
  ");
  $stmt->execute([":queue_id" => $queueId, ":user_id" => $userId]);
  $queue = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!is_array($queue)) throw new DomainException("The queue/order could not be found.");
  if (strtolower(trim((string)$queue["payment_method"])) !== "gcash") {
    throw new DomainException("This queue/order does not use GCash.");
  }
  if (strtoupper(trim((string)$queue["status"])) !== "PENDING") {
    throw new DomainException("Payment details can only be submitted while the queue/order is pending.");
  }
  if (strtoupper(trim((string)$queue["payment_status"])) !== "PENDING") {
    throw new DomainException("This GCash payment has already been reviewed.");
  }

  $updatePayment = $pdo->prepare("
    UPDATE payments
    SET reference_number = :reference_number, status = 'PENDING', updated_at = NOW()
    WHERE id = :payment_id
  ");
  $updatePayment->execute([
    ":reference_number" => $referenceNumber,
    ":payment_id" => (int)$queue["payment_id"],
  ]);

  $details = is_array($queue["details"] ?? null)
    ? $queue["details"]
    : json_decode((string)($queue["details"] ?? "{}"), true);
  if (!is_array($details)) $details = [];
  $details["payment_method"] = "gcash";
  $details["reference_number"] = $referenceNumber;
  $updateQueue = $pdo->prepare("UPDATE queues SET details = :details::jsonb, updated_at = NOW() WHERE id = :queue_id");
  $updateQueue->execute([
    ":details" => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ":queue_id" => $queueId,
  ]);

  $pdo->commit();
  $queueCode = trim((string)$queue["queue_code"]);
  $_SESSION["service_payment_confirmation"] = [
    "queue_id" => $queueId,
    "queue_code" => $queueCode,
    "created_at" => time(),
  ];
  unset($_SESSION["service_payment_flash_error"], $_SESSION["service_payment_queue_id"], $_SESSION["service_payment_queue_code"]);
  servitech_mark_join_queue_completed($queueCode);
  header("Location: " . servitech_url("/pages/customer/custo_service_payment.php?queue_id={$queueId}&submitted=1"));
  exit();
} catch (DomainException $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  service_payment_fail($e->getMessage(), $paymentUrl);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log("service_payment_create error: " . $e->getMessage());
  service_payment_fail("Unable to save your GCash payment details right now. Please try again.", $paymentUrl);
}
