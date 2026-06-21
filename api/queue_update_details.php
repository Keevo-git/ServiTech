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

function queue_update_compare_value($value): string {
  if (is_array($value)) {
    ksort($value);
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: "";
  }
  if (is_bool($value)) return $value ? "1" : "0";
  if (is_numeric($value)) return (string)(float)$value;
  return trim((string)($value ?? ""));
}

function queue_update_money_label($value): string {
  return "PHP " . number_format(max(0, (float)$value), 2);
}

function queue_update_file_change_label(int $addedCount, int $removedCount): string {
  $parts = [];
  if ($addedCount > 0) {
    $parts[] = $addedCount . " added";
  }
  if ($removedCount > 0) {
    $parts[] = $removedCount . " removed";
  }
  return empty($parts) ? "" : "Attached Files (" . implode(", ", $parts) . ")";
}

function queue_update_change_summary(array $before, array $after, int $addedFileCount, int $removedFileCount): string {
  $fieldLabels = [
    "paper_size" => "Paper Size",
    "color_option" => "Color Option",
    "quantity" => "Quantity",
    "package_label" => "Package",
    "lamination_type" => "Lamination",
    "device_type" => "Device",
    "notes" => "Notes",
    "payment_method" => "Payment Method",
    "reference_number" => "GCash Reference",
  ];

  $changes = [];
  foreach ($fieldLabels as $key => $label) {
    if (queue_update_compare_value($before[$key] ?? null) !== queue_update_compare_value($after[$key] ?? null)) {
      $changes[] = $label;
    }
  }

  $fileChange = queue_update_file_change_label($addedFileCount, $removedFileCount);
  if ($fileChange !== "") {
    $changes[] = $fileChange;
  }

  $beforeEstimate = isset($before["estimated_total"]) && is_numeric($before["estimated_total"])
    ? (float)$before["estimated_total"]
    : null;
  $afterEstimate = isset($after["estimated_total"]) && is_numeric($after["estimated_total"])
    ? (float)$after["estimated_total"]
    : null;
  if ($afterEstimate !== null && ($beforeEstimate === null || abs($beforeEstimate - $afterEstimate) >= 0.01)) {
    $changes[] = "Price Estimate (" . queue_update_money_label($afterEstimate) . ")";
  }

  if (empty($changes)) {
    return "No field values changed; request was resubmitted.";
  }

  $visibleChanges = array_slice($changes, 0, 5);
  $remaining = count($changes) - count($visibleChanges);
  if ($remaining > 0) {
    $visibleChanges[] = "+" . $remaining . " more";
  }
  return "Changed: " . implode(", ", $visibleChanges) . ".";
}

try {
  $queueId = (int)($data["queue_id"] ?? 0);
  if ($queueId <= 0) {
    throw new DomainException("Invalid queue/order ID.");
  }

  $pdo->beginTransaction();
  servitech_ensure_queue_lifecycle_schema($pdo);

  $stmt = $pdo->prepare("
    SELECT id, user_id, queue_code, category, status, details, customer_edit_required, send_back_message
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
  if (empty($queue["customer_edit_required"])) {
    throw new DomainException("This request can only be edited after an admin sends it back for editing.");
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
  $currentOrderType = strtolower(trim((string)($currentDetails["order_type"] ?? "")));
  $currentPaymentMethod = strtolower(trim((string)($currentDetails["payment_method"] ?? "")));
  $isDocumentPrintingPaymentFlow = $serviceKind === "document_printing"
    && ($category === "online_printorder"
      || $currentOrderType === "online"
      || in_array($currentPaymentMethod, ["cash", "gcash"], true));

  if ($serviceKind === "document_printing") {
    $details["service_label"] = "Document Printing";
    unset($details["order_type"]);
    $details["paper_size"] = trim((string)($data["paper_size"] ?? ""));
    $details["color_option"] = trim((string)($data["color_option"] ?? ""));
  } elseif ($serviceKind === "xerox") {
    $details["paper_size"] = trim((string)($data["paper_size"] ?? ""));
    $details["color_option"] = trim((string)($data["color_option"] ?? ($currentDetails["color_option"] ?? "")));
    unset($details["payment_method"], $details["reference_number"]);
  } elseif ($serviceKind === "rush_id") {
    $details["package_label"] = trim((string)($data["package_label"] ?? ""));
    unset($details["paper_size"], $details["color_option"], $details["payment_method"], $details["reference_number"]);
  } elseif ($serviceKind === "laminating") {
    $details["lamination_type"] = strtolower(trim((string)($data["lamination_type"] ?? "")));
    unset($details["paper_size"], $details["color_option"], $details["payment_method"], $details["reference_number"]);
  } elseif ($serviceKind === "scanning") {
    $details["paper_size"] = trim((string)($data["paper_size"] ?? ($currentDetails["paper_size"] ?? "")));
    unset($details["color_option"], $details["package_label"], $details["lamination_type"], $details["device_type"], $details["repair_type"], $details["installation_type"], $details["payment_method"], $details["reference_number"]);
  } elseif ($serviceKind === "repair") {
    $details["device_type"] = trim((string)($data["device_type"] ?? ""));
    $details["repair_type"] = trim((string)($data["repair_type"] ?? ""));
    unset($details["paper_size"], $details["color_option"], $details["package_label"], $details["lamination_type"], $details["installation_type"], $details["payment_method"], $details["reference_number"]);
  } elseif ($serviceKind === "installation") {
    $details["installation_type"] = trim((string)($data["installation_type"] ?? ""));
    unset($details["paper_size"], $details["color_option"], $details["package_label"], $details["lamination_type"], $details["device_type"], $details["repair_type"], $details["payment_method"], $details["reference_number"]);
  } else {
    throw new DomainException("Unsupported service.");
  }

  $catalogRuleId = isset($data["catalog_pricing_rule_id"]) ? max(0, (int)$data["catalog_pricing_rule_id"]) : 0;
  if ($catalogRuleId > 0) {
    $details["catalog_pricing_rule_id"] = $catalogRuleId;
  } else {
    unset($details["catalog_pricing_rule_id"]);
  }
  if ($serviceKind === "rush_id") {
    $details["catalog_addon_rule_ids"] = isset($data["catalog_addon_rule_ids"]) && is_array($data["catalog_addon_rule_ids"])
      ? array_values($data["catalog_addon_rule_ids"])
      : [];
  }

  $uploadedInput = isset($data["uploaded_files"]) && is_array($data["uploaded_files"]) ? $data["uploaded_files"] : [];
  $removedFileTokens = [];
  if (isset($data["removed_file_tokens"]) && is_array($data["removed_file_tokens"])) {
    foreach ($data["removed_file_tokens"] as $token) {
      $cleanToken = strtolower(trim((string)$token));
      if ($cleanToken !== "" && preg_match('/^[a-f0-9]{64}$/', $cleanToken)) {
        $removedFileTokens[$cleanToken] = true;
      }
    }
  }
  $removedFileIndexes = [];
  if (isset($data["removed_file_indexes"]) && is_array($data["removed_file_indexes"])) {
    foreach ($data["removed_file_indexes"] as $index) {
      if (is_numeric($index) && (int)$index >= 0) {
        $removedFileIndexes[(int)$index] = true;
      }
    }
  }

  $supportsUploads = in_array($serviceKind, ["document_printing", "rush_id"], true);
  if (!$supportsUploads && (!empty($uploadedInput) || !empty($removedFileTokens) || !empty($removedFileIndexes))) {
    throw new DomainException("This service does not support file attachments.");
  }

  $existingUploadedFiles = isset($currentDetails["uploaded_files"]) && is_array($currentDetails["uploaded_files"])
    ? array_values(array_filter($currentDetails["uploaded_files"], "is_array"))
    : [];
  $keptUploadedFiles = [];
  $removedUploadedFiles = [];
  foreach ($existingUploadedFiles as $index => $file) {
    $token = "";
    try {
      $token = servitech_upload_token_from_metadata($file);
    } catch (DomainException $e) {
      $token = "";
    }
    if ($token !== "" && isset($removedFileTokens[$token])) {
      $removedUploadedFiles[] = $file;
      continue;
    }
    if (isset($removedFileIndexes[$index])) {
      $removedUploadedFiles[] = $file;
      continue;
    }
    $keptUploadedFiles[] = $file;
  }
  $removedFileCount = max(0, count($existingUploadedFiles) - count($keptUploadedFiles));

  $resolvedUploadedFiles = [];
  if (!empty($uploadedInput)) {
    $resolvedUploadedFiles = servitech_upload_resolve_owned_metadata($pdo, $userId, $uploadedInput, true);
  }

  if ($supportsUploads) {
    $mergedUploadedFiles = array_values(array_merge($keptUploadedFiles, $resolvedUploadedFiles));
    if (empty($mergedUploadedFiles)) {
      throw new DomainException($serviceKind === "rush_id"
        ? "Upload at least one JPG, JPEG, or PNG photo for Rush ID."
        : "Upload at least one file before continuing.");
    }
    servitech_upload_assert_limits($mergedUploadedFiles);
    if ($serviceKind === "rush_id") {
      servitech_upload_assert_rush_id_uploaded_files($mergedUploadedFiles);
    }
    $details = servitech_upload_apply_metadata_to_details($details, $mergedUploadedFiles);
  }

  if ($isDocumentPrintingPaymentFlow) {
    $paymentMethod = strtolower(trim((string)($data["payment_method"] ?? ($currentDetails["payment_method"] ?? ""))));
    $referenceNumber = trim((string)($data["reference_number"] ?? ""));
    if (!in_array($paymentMethod, ["cash", "gcash"], true)) {
      throw new DomainException("Payment method is required for Document Print.");
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
  $changeSummary = queue_update_change_summary($currentDetails, $details, count($resolvedUploadedFiles), $removedFileCount);

  if ($isDocumentPrintingPaymentFlow) {
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

  if (!empty($resolvedUploadedFiles)) {
    servitech_upload_link_to_queue($pdo, $userId, $queueId, $resolvedUploadedFiles);
  }

  $history = $pdo->prepare("
    INSERT INTO queue_status_history (queue_id, category, old_status, new_status, admin_id, admin_name, notes, action_type)
    VALUES (:queue_id, :category, :old_status, :new_status, NULL, '', :notes, 'customer_resubmitted')
    RETURNING id
  ");
  $history->execute([
    ":queue_id" => $queueId,
    ":category" => $category,
    ":old_status" => $status,
    ":new_status" => $status,
    ":notes" => "Customer edited and resubmitted the request. {$changeSummary}",
  ]);
  $historyId = (int)($history->fetchColumn() ?: 0);

  $queueCode = trim((string)($queue["queue_code"] ?? ""));
  $customerToastMessage = "Queue {$queueCode} updated successfully. {$changeSummary}";
  $adminNotificationMessage = "Queue {$queueCode}: Customer edited and resubmitted the request. {$changeSummary}";
  servitech_notify_admins(
    $pdo,
    "admin_customer_resubmitted",
    $queueId,
    $adminNotificationMessage,
    "admin_customer_resubmitted:{$queueId}:{$historyId}",
    true
  );

  $pdo->commit();
  if (!empty($removedUploadedFiles)) {
    $removedCleanup = servitech_upload_delete_linked_files($pdo, $userId, $queueId, $removedUploadedFiles);
    if (!empty($removedCleanup["errors"])) {
      error_log("queue_update_details removed upload cleanup failed: " . implode(", ", $removedCleanup["errors"]));
    }
  }
  echo json_encode([
    "ok" => true,
    "queue_id" => $queueId,
    "queue_code" => $queueCode,
    "change_summary" => $changeSummary,
    "toast_message" => $customerToastMessage,
  ]);
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
