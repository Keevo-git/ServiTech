<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/app.php";
require_once __DIR__ . "/../config/join_queue_flow.php";
require_once __DIR__ . "/../config/store_availability.php";
require_once __DIR__ . "/queue_helpers.php";
require_once __DIR__ . "/queue_state_machine.php";
require_once __DIR__ . "/upload_helpers.php";

servitech_enforce_csrf_token(false);

function service_payment_wants_json(): bool {
  $accept = strtolower((string)($_SERVER["HTTP_ACCEPT"] ?? ""));
  $requestedWith = strtolower((string)($_SERVER["HTTP_X_REQUESTED_WITH"] ?? ""));
  return str_contains($accept, "application/json") || $requestedWith === "xmlhttprequest";
}

function service_payment_json_response(array $payload, int $status = 200): void {
  http_response_code($status);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode($payload);
  exit();
}

$userId = (int)($_SESSION["user_id"] ?? 0);
if ($userId <= 0) {
  header("Location: " . servitech_url("/auth/log_in.php"));
  exit();
}
if (!servitech_is_customer()) {
  http_response_code(403);
  exit("Customer access required.");
}
if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
  http_response_code(405);
  exit("Method not allowed.");
}

$draftToken = trim((string)($_POST["draft_token"] ?? ""));
$queueId = (int)($_POST["queue_id"] ?? 0);
$referenceNumber = trim((string)($_POST["reference_number"] ?? ""));
$draft = servitech_service_payment_draft();

function service_payment_fail(string $message, string $paymentUrl): void {
  if (service_payment_wants_json()) {
    service_payment_json_response(["ok" => false, "error" => $message, "redirect_url" => $paymentUrl], 422);
  }
  $_SESSION["service_payment_flash_error"] = $message;
  header("Location: " . $paymentUrl);
  exit();
}

function service_payment_success(int $queueId, string $queueCode, string $serviceName, string $paymentUrl): void {
  $_SESSION["service_payment_confirmation"] = [
    "queue_id" => $queueId,
    "queue_code" => $queueCode,
    "created_at" => time(),
  ];
  servitech_mark_join_queue_completed($queueCode);

  if (service_payment_wants_json()) {
    service_payment_json_response([
      "ok" => true,
      "queue_id" => $queueId,
      "queue_code" => $queueCode,
      "service_name" => $serviceName,
      "message" => "Your queue has been submitted successfully. Your GCash payment is now waiting for admin review.",
    ]);
  }

  header("Location: " . $paymentUrl . (str_contains($paymentUrl, "?") ? "&" : "?") . "submitted=1");
  exit();
}

$paymentUrl = is_array($draft)
  ? servitech_service_payment_draft_url($draft)
  : servitech_url("/pages/customer/custo_service_payment.php?queue_id=" . $queueId);

if (!preg_match('/^\d{13}$/', $referenceNumber)) {
  service_payment_fail("Please enter a valid 13-digit GCash reference number.", $paymentUrl);
}

if ($draftToken !== "") {
  if (!is_array($draft) || !servitech_service_payment_draft_matches($draftToken, $draft)) {
    service_payment_fail("Your payment session has expired. Please start the queue request again.", servitech_url("/pages/customer/customer_dash.php"));
  }

  $category = strtolower(trim((string)($draft["category"] ?? "")));
  $prefix = strtoupper(trim((string)($draft["prefix"] ?? "")));
  $details = is_array($draft["details"] ?? null) ? $draft["details"] : [];
  $serviceLabel = trim((string)($details["service_label"] ?? ($draft["service_label"] ?? "Service")));
  $serviceKind = trim((string)($draft["service_kind"] ?? ""));
  if ($category === "" || $prefix === "" || $serviceLabel === "" || empty($details)) {
    service_payment_fail("Your payment draft is incomplete. Please start the queue request again.", servitech_url("/pages/customer/customer_dash.php"));
  }
  if (!isset($details["estimated_total"]) || !is_numeric($details["estimated_total"])) {
    service_payment_fail("This GCash payment does not have a valid total amount.", $paymentUrl);
  }

  try {
    servitech_store_assert_queue_available($pdo, $category, $serviceLabel);
    $pdo->beginTransaction();

    $uploadedFiles = (array)($details["uploaded_files"] ?? []);
    if ($uploadedFiles !== []) {
      $uploadedFiles = servitech_upload_resolve_owned_metadata($pdo, $userId, $uploadedFiles);
      if ($serviceKind === "rush_id") servitech_upload_assert_rush_id_uploaded_files($uploadedFiles);
      $details = servitech_upload_apply_metadata_to_details($details, $uploadedFiles);
    }
    $details["payment_method"] = "gcash";
    $details["reference_number"] = $referenceNumber;

    $queueIdentity = servitech_generate_queue_identity($pdo, $prefix);
    $queueCode = $queueIdentity["queue_code"];
    if (!servitech_queue_code_matches_category($queueCode, $category)) {
      throw new RuntimeException("Queue prefix/category mapping mismatch.");
    }

    $queueStmt = $pdo->prepare("
      INSERT INTO queues (user_id, queue_code, category, status, lifecycle_stage, queue_cycle_date, daily_sequence, details, price)
      VALUES (:user_id, :queue_code, :category, 'PENDING', 'QUEUE', :queue_cycle_date, :daily_sequence, :details::jsonb, :price)
      RETURNING id
    ");
    $queueStmt->execute([
      ":user_id" => $userId,
      ":queue_code" => $queueCode,
      ":category" => $category,
      ":queue_cycle_date" => $queueIdentity["queue_cycle_date"],
      ":daily_sequence" => $queueIdentity["daily_sequence"],
      ":details" => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      ":price" => max(0, (float)$details["estimated_total"]),
    ]);
    $queueId = (int)($queueStmt->fetchColumn() ?: 0);
    if ($queueId <= 0) throw new RuntimeException("Queue was not created.");

    servitech_record_queue_initial_status($pdo, $queueId, $category);
    servitech_upload_link_to_queue($pdo, $userId, $queueId, $uploadedFiles);

    $paymentStmt = $pdo->prepare("
      INSERT INTO payments (queue_id, user_id, amount, payment_method, reference_number, status)
      VALUES (:queue_id, :user_id, :amount, 'gcash', :reference_number, 'PENDING')
    ");
    $paymentStmt->execute([
      ":queue_id" => $queueId,
      ":user_id" => $userId,
      ":amount" => max(0, (float)$details["estimated_total"]),
      ":reference_number" => $referenceNumber,
    ]);

    servitech_add_notification(
      $pdo,
      $userId,
      $category,
      $queueId,
      "Queue {$queueCode}: GCash payment details submitted. Waiting for admin review.",
      "customer_new_gcash_queue:{$queueId}",
      true
    );
    servitech_notify_admins(
      $pdo,
      "admin_new_order_payment_review",
      $queueId,
      "Queue {$queueCode}: New {$serviceLabel} order submitted. GCash payment: Review the order and update its status.",
      "admin_new_order_payment_review:{$queueId}",
      true
    );

    $pdo->commit();
    service_payment_success($queueId, $queueCode, $serviceLabel, servitech_url("/pages/customer/custo_service_payment.php?queue_id={$queueId}"));
  } catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    service_payment_fail($e->getMessage(), $paymentUrl);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("service_payment_create draft finalization error: " . $e->getMessage());
    service_payment_fail("Unable to submit your GCash payment right now. Please try again.", $paymentUrl);
  }
}

// Compatibility path for GCash queues created by an older release before draft-first submission.
if ($queueId <= 0) {
  service_payment_fail("No pending GCash payment was found.", servitech_url("/pages/customer/customer_dash.php"));
}

try {
  $pdo->beginTransaction();
  $stmt = $pdo->prepare("
    SELECT q.id, q.queue_code, q.status, q.details, p.id AS payment_id,
      p.payment_method, p.reference_number, p.status AS payment_status
    FROM queues q
    JOIN LATERAL (
      SELECT id, payment_method, reference_number, status
      FROM payments WHERE queue_id = q.id ORDER BY id DESC LIMIT 1
    ) p ON TRUE
    WHERE q.id = :queue_id AND q.user_id = :user_id
    LIMIT 1
    FOR UPDATE OF q
  ");
  $stmt->execute([":queue_id" => $queueId, ":user_id" => $userId]);
  $queue = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!is_array($queue)) throw new DomainException("The queue/order could not be found.");
  if (strtolower(trim((string)$queue["payment_method"])) !== "gcash") throw new DomainException("This queue/order does not use GCash.");
  if (strtoupper(trim((string)$queue["status"])) !== "PENDING") throw new DomainException("Payment details can only be submitted while the queue/order is pending.");
  if (strtoupper(trim((string)$queue["payment_status"])) !== "PENDING") throw new DomainException("This GCash payment has already been reviewed.");
  if (trim((string)($queue["reference_number"] ?? "")) !== "") throw new DomainException("This GCash payment has already been submitted for review.");

  $updatePayment = $pdo->prepare("UPDATE payments SET reference_number = :reference_number, status = 'PENDING', updated_at = NOW() WHERE id = :payment_id");
  $updatePayment->execute([":reference_number" => $referenceNumber, ":payment_id" => (int)$queue["payment_id"]]);
  $details = is_array($queue["details"] ?? null) ? $queue["details"] : json_decode((string)($queue["details"] ?? "{}"), true);
  if (!is_array($details)) $details = [];
  $details["payment_method"] = "gcash";
  $details["reference_number"] = $referenceNumber;
  $updateQueue = $pdo->prepare("UPDATE queues SET details = :details::jsonb, updated_at = NOW() WHERE id = :queue_id");
  $updateQueue->execute([":details" => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ":queue_id" => $queueId]);
  $pdo->commit();

  $queueCode = trim((string)$queue["queue_code"]);
  $legacyServiceName = trim((string)($details["service_label"] ?? ($details["catalog_service_name"] ?? "Service")));
  service_payment_success($queueId, $queueCode, $legacyServiceName !== "" ? $legacyServiceName : "Service", servitech_url("/pages/customer/custo_service_payment.php?queue_id={$queueId}"));
} catch (DomainException $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  service_payment_fail($e->getMessage(), $paymentUrl);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log("service_payment_create legacy error: " . $e->getMessage());
  service_payment_fail("Unable to save your GCash payment details right now. Please try again.", $paymentUrl);
}
