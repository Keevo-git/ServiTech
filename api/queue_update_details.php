<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/queue_helpers.php";
require_once __DIR__ . "/service_pricing.php";
require_once __DIR__ . "/queue_state_machine.php";
require_once __DIR__ . "/upload_helpers.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

$userId = (int)($_SESSION["user_id"] ?? 0);
if ($userId <= 0) {
  http_response_code(401);
  echo json_encode(["ok" => false, "error" => "Not logged in"]);
  exit();
}
if (!servitech_is_customer()) {
  http_response_code(403);
  echo json_encode(["ok" => false, "error" => "Customer access required"]);
  exit();
}

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
  http_response_code(422);
  echo json_encode(["ok" => false, "error" => "Invalid JSON"]);
  exit();
}

function queue_update_clean_details(array $details): array {
  foreach ($details as $key => $value) {
    if ($value === null) {
      unset($details[$key]);
      continue;
    }
    if (is_string($value) && trim($value) === "") {
      unset($details[$key]);
    }
  }
  return $details;
}

try {
  $queueId = (int)($data["queue_id"] ?? 0);
  if ($queueId <= 0) {
    throw new DomainException("Invalid queue/order ID.");
  }

  $pdo->beginTransaction();
  servitech_ensure_queue_lifecycle_schema($pdo);

  $stmt = $pdo->prepare("
    SELECT id, user_id, queue_code, category, status, details
    FROM queues
    WHERE id = :id AND user_id = :user_id
    LIMIT 1
    FOR UPDATE
  ");
  $stmt->execute([":id" => $queueId, ":user_id" => $userId]);
  $queue = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!is_array($queue)) {
    throw new DomainException("Queue/order not found.");
  }

  $status = servitech_queue_normalize_status((string)($queue["status"] ?? "PENDING"));
  if (!servitech_queue_is_customer_editable_status($status)) {
    throw new DomainException("Only Pending or Approved records can be edited.");
  }

  $currentDetails = [];
  if (is_string($queue["details"] ?? null) && trim((string)$queue["details"]) !== "") {
    $decoded = json_decode((string)$queue["details"], true);
    if (is_array($decoded)) $currentDetails = $decoded;
  } elseif (is_array($queue["details"] ?? null)) {
    $currentDetails = $queue["details"];
  }

  $category = strtolower(trim((string)($queue["category"] ?? "")));
  $details = $currentDetails;
  $details["service_label"] = trim((string)($currentDetails["service_label"] ?? ($data["service_label"] ?? "")));
  if ($details["service_label"] === "") {
    throw new DomainException("Service label is required.");
  }

  $quantity = max(1, (int)($data["quantity"] ?? ($currentDetails["quantity"] ?? 1)));
  $details["quantity"] = $quantity;
  $details["notes"] = trim((string)($data["notes"] ?? ""));

  $serviceKind = servitech_pricing_service_kind($category, (string)$details["service_label"]);
  if ($serviceKind === "document_printing") {
    $details["order_type"] = $category === "online_printorder" ? "online" : "walkin";
    $details["paper_size"] = trim((string)($data["paper_size"] ?? ""));
    $details["color_option"] = trim((string)($data["color_option"] ?? ""));
  } elseif ($serviceKind === "xerox") {
    $details["paper_size"] = trim((string)($data["paper_size"] ?? ""));
    unset($details["color_option"], $details["payment_method"], $details["reference_number"]);
  } elseif ($serviceKind === "rush_id") {
    $details["package_label"] = trim((string)($data["package_label"] ?? ""));
    unset($details["paper_size"], $details["color_option"], $details["payment_method"], $details["reference_number"]);
  } elseif ($serviceKind === "laminating") {
    $details["lamination_type"] = strtolower(trim((string)($data["lamination_type"] ?? "")));
    unset($details["paper_size"], $details["color_option"], $details["payment_method"], $details["reference_number"]);
  } elseif (in_array($serviceKind, ["repair", "installation"], true)) {
    $details["device_type"] = trim((string)($data["device_type"] ?? ""));
    unset($details["paper_size"], $details["color_option"], $details["package_label"], $details["lamination_type"], $details["payment_method"], $details["reference_number"]);
  } else {
    throw new DomainException("Unsupported service.");
  }

  $uploadedInput = isset($data["uploaded_files"]) && is_array($data["uploaded_files"]) ? $data["uploaded_files"] : [];
  if (!empty($uploadedInput)) {
    $resolvedUploadedFiles = servitech_upload_resolve_owned_metadata($pdo, $userId, $uploadedInput, true);
    if ($serviceKind === "rush_id") {
      servitech_upload_assert_rush_id_uploaded_files($resolvedUploadedFiles);
    }
    $details = servitech_upload_apply_metadata_to_details($details, $resolvedUploadedFiles);
  } elseif (in_array($serviceKind, ["document_printing", "rush_id"], true) && empty($details["uploaded_files"])) {
    throw new DomainException($serviceKind === "rush_id"
      ? "Upload at least one JPG, JPEG, or PNG photo for Rush ID."
      : "Upload at least one file before continuing.");
  }

  if ($category === "online_printorder") {
    $paymentMethod = strtolower(trim((string)($data["payment_method"] ?? ($currentDetails["payment_method"] ?? ""))));
    $referenceNumber = trim((string)($data["reference_number"] ?? ""));
    if (!in_array($paymentMethod, ["cash", "gcash"], true)) {
      throw new DomainException("Payment method is required for online print orders.");
    }
    if ($paymentMethod === "gcash" && $referenceNumber === "") {
      throw new DomainException("Reference number is required for GCash payments.");
    }
    if ($paymentMethod === "gcash" && !preg_match('/^\d{13}$/', $referenceNumber)) {
      throw new DomainException("GCash reference number must be exactly 13 digits.");
    }
    $details["payment_method"] = $paymentMethod;
    $details["reference_number"] = $paymentMethod === "gcash" ? $referenceNumber : null;
  }

  $details = queue_update_clean_details($details);
  $details = servitech_pricing_apply($pdo, $category, $details);
  $price = isset($details["estimated_total"]) ? max(0, (float)$details["estimated_total"]) : null;

  $update = $pdo->prepare("
    UPDATE queues
    SET details = :details::jsonb,
        price = :price,
        customer_edit_required = FALSE,
        send_back_message = '',
        send_back_at = NULL,
        send_back_by = NULL,
        updated_at = NOW()
    WHERE id = :id
  ");
  $update->execute([
    ":details" => json_encode($details, JSON_UNESCAPED_UNICODE),
    ":price" => $price,
    ":id" => $queueId,
  ]);

  if (!empty($uploadedInput)) {
    servitech_upload_link_to_queue($pdo, $userId, $queueId, (array)$details["uploaded_files"]);
  }

  if ($category === "online_printorder") {
    $paymentAmount = isset($details["estimated_total"]) ? max(0, (float)$details["estimated_total"]) : 0;
    $paymentMethod = (string)($details["payment_method"] ?? "cash");
    $referenceNumber = (string)($details["reference_number"] ?? "");
    $latestPayment = $pdo->prepare("SELECT id FROM payments WHERE queue_id = :queue_id ORDER BY id DESC LIMIT 1");
    $latestPayment->execute([":queue_id" => $queueId]);
    $paymentId = (int)($latestPayment->fetchColumn() ?: 0);

    if ($paymentId > 0) {
      $paymentUpdate = $pdo->prepare("
        UPDATE payments
        SET amount = :amount,
            payment_method = :payment_method,
            reference_number = :reference_number,
            updated_at = NOW()
        WHERE id = :id
      ");
      $paymentUpdate->execute([
        ":amount" => $paymentAmount,
        ":payment_method" => $paymentMethod,
        ":reference_number" => $paymentMethod === "gcash" ? $referenceNumber : null,
        ":id" => $paymentId,
      ]);
    } else {
      $paymentInsert = $pdo->prepare("
        INSERT INTO payments (queue_id, user_id, amount, payment_method, reference_number)
        VALUES (:queue_id, :user_id, :amount, :payment_method, :reference_number)
      ");
      $paymentInsert->execute([
        ":queue_id" => $queueId,
        ":user_id" => $userId,
        ":amount" => $paymentAmount,
        ":payment_method" => $paymentMethod,
        ":reference_number" => $paymentMethod === "gcash" ? $referenceNumber : null,
      ]);
    }
  }

  $history = $pdo->prepare("
    INSERT INTO queue_status_history (queue_id, category, old_status, new_status, admin_id, admin_name, notes, action_type)
    VALUES (:queue_id, :category, :old_status, :new_status, NULL, '', 'Customer edited and resubmitted the request.', 'customer_resubmitted')
    RETURNING id
  ");
  $history->execute([
    ":queue_id" => $queueId,
    ":category" => $category,
    ":old_status" => $status,
    ":new_status" => $status,
  ]);
  $historyId = (int)($history->fetchColumn() ?: 0);

  $queueCode = trim((string)($queue["queue_code"] ?? ""));
  servitech_notify_admins(
    $pdo,
    "admin_customer_resubmitted",
    $queueId,
    "Queue {$queueCode}: Customer edited and resubmitted the request.",
    "admin_customer_resubmitted:{$queueId}:{$historyId}",
    true
  );

  $pdo->commit();
  echo json_encode(["ok" => true, "queue_id" => $queueId, "queue_code" => $queueCode]);
} catch (DomainException $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(422);
  echo json_encode(["ok" => false, "error" => $e->getMessage()]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log("queue_update_details error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Unable to update this request right now."]);
}
