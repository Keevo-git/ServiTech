<?php
require_once __DIR__ . "/queue_helpers.php";
require_once __DIR__ . "/queue_payment.php";
require_once __DIR__ . "/../config/activity_log.php";
require_once __DIR__ . "/../config/input_limits.php";

function servitech_queue_normalize_status(string $status): string {
  $status = strtoupper(trim($status));
  $status = preg_replace('/[\s_]+/', ' ', $status);

  return match ($status) {
    "", "PENDING PAYMENT" => "PENDING",
    "FOR PICK UP", "FOR PICKUP" => "FOR PICK-UP",
    "COMPLETED" => "DONE",
    "CANCELED" => "CANCELLED",
    default => $status,
  };
}

function servitech_queue_is_legacy_print_order(array $queue): bool {
  $category = strtolower(trim((string)($queue["category"] ?? "")));
  $queueCode = strtoupper(trim((string)($queue["queue_code"] ?? "")));

  return in_array($category, ["online_printorder", "printing_online"], true)
    || str_starts_with($queueCode, "OP");
}

function servitech_queue_details_array($details): array {
  if (is_array($details)) return $details;
  $decoded = json_decode((string)$details, true);
  return is_array($decoded) ? $decoded : [];
}

function servitech_queue_payment_method(array $queue): string {
  $details = servitech_queue_details_array($queue["details"] ?? null);
  return strtolower(trim((string)($queue["payment_method"] ?? ($details["payment_method"] ?? ""))));
}

function servitech_queue_is_online_payment_method(array $queue): bool {
  $method = preg_replace('/[\s-]+/', '_', servitech_queue_payment_method($queue));
  return in_array($method, ["gcash", "online", "online_payment"], true);
}

function servitech_queue_payment_reference(array $queue): string {
  $details = servitech_queue_details_array($queue["details"] ?? null);
  return trim((string)($queue["reference_number"] ?? ($queue["payment_reference_number"] ?? ($details["reference_number"] ?? ""))));
}

function servitech_queue_payment_review_status(string $status): string {
  $status = strtoupper(trim($status));
  return preg_replace('/[\s_]+/', ' ', $status);
}

function servitech_queue_payment_status_waits_for_review(string $status): bool {
  return in_array(servitech_queue_payment_review_status($status), [
    "",
    "PENDING",
    "PENDING REVIEW",
    "WAITING FOR ADMIN REVIEW",
    "WAITING ADMIN REVIEW",
    "AWAITING REVIEW",
    "AWAITING ADMIN REVIEW",
    "FOR REVIEW",
  ], true);
}

function servitech_queue_requires_gcash_review(array $queue): bool {
  $paymentStatus = (string)($queue["payment_status"] ?? "PENDING");
  return servitech_queue_is_online_payment_method($queue)
    && servitech_queue_payment_status_waits_for_review($paymentStatus)
    && servitech_queue_payment_reference($queue) !== "";
}

function servitech_queue_allowed_transitions(array $queue): array {
  $status = servitech_queue_normalize_status((string)($queue["status"] ?? "PENDING"));

  if (servitech_queue_is_legacy_print_order($queue) || servitech_queue_is_online_payment_method($queue)) {
    return match ($status) {
      "PENDING" => servitech_queue_requires_gcash_review($queue) || !servitech_queue_is_online_payment_method($queue)
        ? ["APPROVED", "CANCELLED"]
        : ["CANCELLED"],
      "APPROVED" => ["ONGOING"],
      "ONGOING" => ["FOR PICK-UP"],
      "FOR PICK-UP" => ["DONE"],
      default => [],
    };
  }

  return match ($status) {
    "PENDING" => ["ONGOING", "CANCELLED"],
    "ONGOING" => ["FOR PICK-UP", "DONE", "CANCELLED"],
    "FOR PICK-UP" => ["DONE"],
    default => [],
  };
}

function servitech_queue_transition_error(array $queue, string $newStatus): string {
  $current = servitech_queue_normalize_status((string)($queue["status"] ?? "PENDING"));
  if ($newStatus === "CANCELLED") {
    return match ($current) {
      "APPROVED" => "This order cannot be cancelled after it has been approved.",
      "FOR PICK-UP" => "This order cannot be cancelled after it is ready for pick-up.",
      "DONE" => "This order is already completed and can no longer be cancelled.",
      "CANCELLED" => "This order has already been cancelled.",
      default => "This order can no longer be cancelled at its current stage.",
    };
  }
  if (in_array($current, ["DONE", "CANCELLED"], true)) {
    return "This order is finalized and its status can no longer be changed.";
  }
  return "Invalid status transition from {$current} to {$newStatus}.";
}

function servitech_queue_actor_name(PDO $pdo, ?int $adminId): string {
  if (!$adminId || $adminId <= 0) return "";
  $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(fullname, ''), email, '') FROM users WHERE id = :id LIMIT 1");
  $stmt->execute([":id" => $adminId]);
  return trim((string)($stmt->fetchColumn() ?: ""));
}

function servitech_queue_analytics_schema_ready(PDO $pdo): bool {
  static $ready = null;
  if ($ready !== null) return $ready;

  try {
    $queueColumns = [
      "request_created_at", "pending_at", "approved_at", "ongoing_at",
      "for_pickup_at", "done_at", "cancelled_at", "request_source",
    ];
    $placeholders = implode(",", array_fill(0, count($queueColumns), "?"));
    $stmt = $pdo->prepare("
      SELECT COUNT(DISTINCT column_name)
      FROM information_schema.columns
      WHERE table_schema = ANY(current_schemas(false))
        AND table_name = 'queues'
        AND column_name IN ({$placeholders})
    ");
    $stmt->execute($queueColumns);
    if ((int)$stmt->fetchColumn() !== count($queueColumns)) {
      $ready = false;
      return false;
    }

    $eventColumns = [
      "queue_id", "queue_code", "transition_no", "previous_status", "status",
      "entered_at", "exited_at", "duration_minutes", "updated_by", "remarks",
    ];
    $placeholders = implode(",", array_fill(0, count($eventColumns), "?"));
    $stmt = $pdo->prepare("
      SELECT COUNT(DISTINCT column_name)
      FROM information_schema.columns
      WHERE table_schema = ANY(current_schemas(false))
        AND table_name = 'queue_status_events'
        AND column_name IN ({$placeholders})
    ");
    $stmt->execute($eventColumns);
    $ready = (int)$stmt->fetchColumn() === count($eventColumns);
    return $ready;
  } catch (Throwable $exception) {
    error_log("queue analytics schema check failed: " . $exception->getMessage());
    $ready = false;
    return false;
  }
}

function servitech_queue_analytics_service_type(array $queue): string {
  $details = servitech_queue_details_array($queue["details"] ?? null);
  return trim((string)(
    $details["type_of_request"]
    ?? $details["service_name_snapshot"]
    ?? $details["catalog_service_name"]
    ?? $details["service_label"]
    ?? $queue["category"]
    ?? "Service Request"
  ));
}

function servitech_queue_analytics_request_source(array $queue): string {
  $source = strtolower(trim((string)($queue["request_source"] ?? "")));
  if ($source !== "") return $source;
  $category = strtolower(trim((string)($queue["category"] ?? "")));
  return str_contains($category, "walk") ? "walk-in" : "online";
}

function servitech_queue_analytics_payment_method(array $queue): string {
  return strtolower(trim((string)($queue["payment_method"] ?? servitech_queue_payment_method($queue))));
}

function servitech_queue_analytics_customer_name(array $queue): string {
  $details = servitech_queue_details_array($queue["details"] ?? null);
  return trim((string)(
    $details["customer_name_snapshot"]
    ?? $queue["customer_name"]
    ?? $queue["fullname"]
    ?? ""
  ));
}

function servitech_record_queue_analytics_initial_status(PDO $pdo, int $queueId, string $category): void {
  if (!servitech_queue_analytics_schema_ready($pdo)) return;

  try {
    $stmt = $pdo->prepare("
      SELECT q.id, q.user_id, q.queue_code, q.category, q.status, q.details, q.created_at, q.request_source,
             COALESCE(NULLIF(TRIM(u.fullname), ''), NULLIF(TRIM(u.email), ''), '') AS customer_name,
             p.payment_method
      FROM queues q
      LEFT JOIN users u ON u.id = q.user_id
      LEFT JOIN LATERAL (
        SELECT payment_method
        FROM payments
        WHERE queue_id = q.id
        ORDER BY id DESC
        LIMIT 1
      ) p ON TRUE
      WHERE q.id = :id
      LIMIT 1
    ");
    $stmt->execute([":id" => $queueId]);
    $queue = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($queue)) return;

    $source = servitech_queue_analytics_request_source($queue);
    $update = $pdo->prepare("
      UPDATE queues
      SET request_created_at = COALESCE(request_created_at, created_at, NOW()),
          pending_at = COALESCE(pending_at, request_created_at, created_at, NOW()),
          request_source = CASE WHEN NULLIF(TRIM(request_source), '') IS NULL THEN :request_source ELSE request_source END,
          updated_at = NOW()
      WHERE id = :id
    ");
    $update->execute([
      ":request_source" => $source,
      ":id" => $queueId,
    ]);

    $event = $pdo->prepare("
      INSERT INTO queue_status_events (
        queue_id, queue_code, customer_name_snapshot, service_type, payment_method,
        transition_no, previous_status, status, entered_at, exited_at, duration_minutes,
        next_status, updated_by, updated_by_name, remarks, created_at, updated_at
      )
      VALUES (
        :queue_id, :queue_code, :customer_name, :service_type, :payment_method,
        1, NULL, 'PENDING', COALESCE(:entered_at, NOW()), NULL, NULL,
        NULL, NULL, '', :remarks, NOW(), NOW()
      )
      ON CONFLICT (queue_id, transition_no, status, entered_at)
      DO NOTHING
    ");
    $event->execute([
      ":queue_id" => $queueId,
      ":queue_code" => trim((string)$queue["queue_code"]),
      ":customer_name" => servitech_queue_analytics_customer_name($queue),
      ":service_type" => servitech_queue_analytics_service_type($queue),
      ":payment_method" => servitech_queue_analytics_payment_method($queue),
      ":entered_at" => (string)($queue["created_at"] ?? ""),
      ":remarks" => "Request joined the queue.",
    ]);
  } catch (Throwable $exception) {
    error_log("queue analytics initial status failed: " . $exception->getMessage());
  }
}

function servitech_record_queue_analytics_transition(
  PDO $pdo,
  array $queue,
  string $oldStatus,
  string $newStatus,
  ?int $adminId,
  string $remarks = ""
): void {
  if (!servitech_queue_analytics_schema_ready($pdo)) return;

  $oldStatus = servitech_queue_normalize_status($oldStatus);
  $newStatus = servitech_queue_normalize_status($newStatus);
  $queueId = (int)($queue["id"] ?? 0);
  if ($queueId <= 0 || $oldStatus === $newStatus) return;

  try {
    $actorName = servitech_queue_actor_name($pdo, $adminId);
    $customerName = servitech_queue_analytics_customer_name($queue);
    $serviceType = servitech_queue_analytics_service_type($queue);
    $paymentMethod = servitech_queue_analytics_payment_method($queue);
    $queueCode = trim((string)($queue["queue_code"] ?? ""));

    $close = $pdo->prepare("
      UPDATE queue_status_events
      SET exited_at = COALESCE(exited_at, NOW()),
          duration_minutes = COALESCE(duration_minutes, ROUND((EXTRACT(EPOCH FROM (NOW() - entered_at)) / 60.0)::numeric, 2)),
          customer_name_snapshot = :customer_name,
          service_type = :service_type,
          payment_method = :payment_method,
          next_status = :next_status,
          updated_by = :updated_by,
          updated_by_name = :updated_by_name,
          updated_at = NOW()
      WHERE id = (
        SELECT id
        FROM queue_status_events
        WHERE queue_id = :queue_id
          AND status = :old_status
          AND exited_at IS NULL
        ORDER BY entered_at DESC, transition_no DESC, id DESC
        LIMIT 1
      )
    ");
    $close->execute([
      ":next_status" => $newStatus,
      ":customer_name" => $customerName,
      ":service_type" => $serviceType,
      ":payment_method" => $paymentMethod,
      ":updated_by" => $adminId !== null && $adminId > 0 ? $adminId : null,
      ":updated_by_name" => $actorName,
      ":queue_id" => $queueId,
      ":old_status" => $oldStatus,
    ]);

    if ($close->rowCount() === 0) {
      $fallback = $pdo->prepare("
        INSERT INTO queue_status_events (
          queue_id, queue_code, customer_name_snapshot, service_type, payment_method,
          transition_no, previous_status, status, entered_at, exited_at, duration_minutes,
          next_status, updated_by, updated_by_name, remarks, created_at, updated_at
        )
        VALUES (
          :queue_id, :queue_code, :customer_name, :service_type, :payment_method,
          GREATEST(1, COALESCE((SELECT MAX(transition_no) FROM queue_status_events WHERE queue_id = :queue_id_for_max), 0) + 1),
          NULL, :old_status, COALESCE(:entered_at, NOW()), NOW(),
          ROUND((EXTRACT(EPOCH FROM (NOW() - COALESCE(CAST(:entered_at_for_duration AS timestamptz), NOW()))) / 60.0)::numeric, 2),
          :new_status, :updated_by, :updated_by_name, 'Backfilled open status during transition.', NOW(), NOW()
        )
        ON CONFLICT (queue_id, transition_no, status, entered_at)
        DO UPDATE SET exited_at = EXCLUDED.exited_at,
                      duration_minutes = EXCLUDED.duration_minutes,
                      next_status = EXCLUDED.next_status,
                      updated_by = EXCLUDED.updated_by,
                      updated_by_name = EXCLUDED.updated_by_name,
                      updated_at = NOW()
      ");
      $fallbackEnteredAt = trim((string)($queue["request_created_at"] ?? $queue["created_at"] ?? ""));
      $fallbackEnteredAt = $fallbackEnteredAt !== "" ? $fallbackEnteredAt : null;
      $fallback->execute([
        ":queue_id" => $queueId,
        ":queue_code" => $queueCode,
        ":customer_name" => $customerName,
        ":service_type" => $serviceType,
        ":payment_method" => $paymentMethod,
        ":queue_id_for_max" => $queueId,
        ":old_status" => $oldStatus,
        ":entered_at" => $fallbackEnteredAt,
        ":entered_at_for_duration" => $fallbackEnteredAt,
        ":new_status" => $newStatus,
        ":updated_by" => $adminId !== null && $adminId > 0 ? $adminId : null,
        ":updated_by_name" => $actorName,
      ]);
    }

    $transitionNoStmt = $pdo->prepare("SELECT COALESCE(MAX(transition_no), 0) + 1 FROM queue_status_events WHERE queue_id = :queue_id");
    $transitionNoStmt->execute([":queue_id" => $queueId]);
    $transitionNo = max(1, (int)$transitionNoStmt->fetchColumn());

    $insert = $pdo->prepare("
      INSERT INTO queue_status_events (
        queue_id, queue_code, customer_name_snapshot, service_type, payment_method,
        transition_no, previous_status, status, entered_at, exited_at, duration_minutes,
        next_status, updated_by, updated_by_name, remarks, created_at, updated_at
      )
      VALUES (
        :queue_id, :queue_code, :customer_name, :service_type, :payment_method,
        :transition_no, :previous_status, :status, NOW(), NULL, NULL,
        NULL, :updated_by, :updated_by_name, :remarks, NOW(), NOW()
      )
      ON CONFLICT (queue_id, transition_no, status, entered_at)
      DO NOTHING
    ");
    $insert->execute([
      ":queue_id" => $queueId,
      ":queue_code" => $queueCode,
      ":customer_name" => $customerName,
      ":service_type" => $serviceType,
      ":payment_method" => $paymentMethod,
      ":transition_no" => $transitionNo,
      ":previous_status" => $oldStatus,
      ":status" => $newStatus,
      ":updated_by" => $adminId !== null && $adminId > 0 ? $adminId : null,
      ":updated_by_name" => $actorName,
      ":remarks" => trim($remarks),
    ]);

    $detailsPatch = [];
    if ($newStatus === "DONE") {
      $detailsPatch["completion_flag"] = 1;
    }
    if ($newStatus === "CANCELLED") {
      $detailsPatch["completion_flag"] = 0;
      $detailsPatch["cancellation_reason"] = trim($remarks);
    }
    $detailsPatchSql = $detailsPatch
      ? ", details = COALESCE(details, '{}'::jsonb) || CAST(:details_patch AS jsonb)"
      : "";

    $timestampUpdate = $pdo->prepare("
      UPDATE queues
      SET request_created_at = COALESCE(request_created_at, created_at, NOW()),
          pending_at = COALESCE(pending_at, request_created_at, created_at, NOW()),
          approved_at = CASE WHEN :status_for_approved = 'APPROVED' THEN COALESCE(approved_at, NOW()) ELSE approved_at END,
          ongoing_at = CASE WHEN :status_for_ongoing = 'ONGOING' THEN COALESCE(ongoing_at, NOW()) ELSE ongoing_at END,
          for_pickup_at = CASE WHEN :status_for_pickup = 'FOR PICK-UP' THEN COALESCE(for_pickup_at, NOW()) ELSE for_pickup_at END,
          done_at = CASE WHEN :status_for_done = 'DONE' THEN COALESCE(done_at, NOW()) ELSE done_at END,
          cancelled_at = CASE WHEN :status_for_cancelled = 'CANCELLED' THEN COALESCE(cancelled_at, NOW()) ELSE cancelled_at END,
          request_source = CASE WHEN NULLIF(TRIM(request_source), '') IS NULL THEN :request_source ELSE request_source END
          {$detailsPatchSql},
          updated_at = NOW()
      WHERE id = :queue_id
    ");
    $params = [
      ":status_for_approved" => $newStatus,
      ":status_for_ongoing" => $newStatus,
      ":status_for_pickup" => $newStatus,
      ":status_for_done" => $newStatus,
      ":status_for_cancelled" => $newStatus,
      ":request_source" => servitech_queue_analytics_request_source($queue),
      ":queue_id" => $queueId,
    ];
    if ($detailsPatch) {
      $params[":details_patch"] = json_encode($detailsPatch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $timestampUpdate->execute($params);
  } catch (Throwable $exception) {
    error_log("queue analytics transition failed: " . $exception->getMessage());
  }
}

function servitech_queue_customer_subject(array $queue): string {
  $details = servitech_queue_details_array($queue["details"] ?? null);
  $serviceLabel = trim((string)($details["service_label"] ?? ""));
  if ($serviceLabel !== "") return $serviceLabel . " order";
  $category = strtolower(trim((string)($queue["category"] ?? "")));

  if (servitech_queue_is_legacy_print_order($queue)) {
    return "print order";
  }

  return match ($category) {
    "printing", "printing_online", "online_printorder" => "print request",
    "repair" => "repair request",
    "installation" => "installation request",
    default => "service request",
  };
}

function servitech_queue_customer_status_message(array $queue, string $newStatus, string $notes = ""): string {
  $newStatus = servitech_queue_normalize_status($newStatus);
  $queueCode = trim((string)($queue["queue_code"] ?? ""));
  $subject = servitech_queue_customer_subject($queue);
  $statusLead = "Your {$subject} (Queue ID: {$queueCode}) is now {$newStatus}.";

  return match ($newStatus) {
    "APPROVED" => servitech_queue_is_online_payment_method($queue)
      ? "Your " . (servitech_queue_payment_method($queue) === "gcash" ? "GCash" : "online") . " payment for Queue {$queueCode} has been approved. Your {$subject} is waiting to be processed."
      : "{$statusLead} Your payment has been approved and your order is waiting to be processed.",
    "ONGOING" => "{$statusLead} Your request is now being processed.",
    "FOR PICK-UP" => "{$statusLead} Your request is ready for pick-up.",
    "DONE" => "{$statusLead} Your service request has been completed.",
    "CANCELLED" => "{$statusLead} Reason: {$notes}",
    default => $statusLead,
  };
}

function servitech_record_queue_status_history(
  PDO $pdo,
  int $queueId,
  string $category,
  ?string $oldStatus,
  string $newStatus,
  ?int $adminId,
  string $notes = "",
  string $actionType = "status_change"
): void {
  $stmt = $pdo->prepare("
    INSERT INTO queue_status_history (queue_id, category, old_status, new_status, admin_id, admin_name, notes, action_type)
    VALUES (:queue_id, :category, :old_status, :new_status, :admin_id, :admin_name, :notes, :action_type)
  ");
  $stmt->execute([
    ":queue_id" => $queueId,
    ":category" => trim($category),
    ":old_status" => $oldStatus !== null ? servitech_queue_normalize_status($oldStatus) : null,
    ":new_status" => servitech_queue_normalize_status($newStatus),
    ":admin_id" => $adminId !== null && $adminId > 0 ? $adminId : null,
    ":admin_name" => servitech_queue_actor_name($pdo, $adminId),
    ":notes" => trim($notes),
    ":action_type" => trim($actionType) !== "" ? trim($actionType) : "status_change",
  ]);
}

function servitech_record_queue_initial_status(PDO $pdo, int $queueId, string $category): void {
  servitech_record_queue_status_history($pdo, $queueId, $category, null, "PENDING", null, "Queue created.");
  servitech_record_queue_analytics_initial_status($pdo, $queueId, $category);
}

function servitech_queue_is_customer_editable_status(string $status): bool {
  return in_array(servitech_queue_normalize_status($status), ["PENDING", "APPROVED"], true);
}

function servitech_send_queue_back_to_customer(PDO $pdo, int $queueId, int $adminId, string $message): array {
  $message = trim($message);
  if ($queueId <= 0) throw new DomainException("Invalid queue/order ID.");
  if ($message === "") throw new DomainException("Send-back message is required.");
  if (servitech_text_length($message) > SERVITECH_LIMIT_SEND_BACK_REASON) throw new DomainException("Send-back message cannot exceed " . SERVITECH_LIMIT_SEND_BACK_REASON . " characters.");

  $ownsTransaction = !$pdo->inTransaction();
  if ($ownsTransaction) $pdo->beginTransaction();

  try {
    servitech_ensure_queue_lifecycle_schema($pdo);
    $stmt = $pdo->prepare("
      SELECT id, user_id, queue_code, category, status, lifecycle_stage, customer_edit_required
      FROM queues
      WHERE id = :id
      LIMIT 1
      FOR UPDATE
    ");
    $stmt->execute([":id" => $queueId]);
    $queue = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($queue)) throw new DomainException("Queue/order not found.");

    $currentStatus = servitech_queue_normalize_status((string)($queue["status"] ?? "PENDING"));
    if (!servitech_queue_is_customer_editable_status($currentStatus)) {
      throw new DomainException("Only Pending or Approved records can be sent back for customer editing.");
    }
    if (!empty($queue["customer_edit_required"])) {
      throw new DomainException("This record is already waiting for customer edits.");
    }

    $update = $pdo->prepare("
      UPDATE queues
      SET customer_edit_required = TRUE,
          send_back_message = :message,
          send_back_at = NOW(),
          send_back_by = :admin_id,
          updated_at = NOW()
      WHERE id = :id
    ");
    $update->execute([
      ":message" => $message,
      ":admin_id" => $adminId > 0 ? $adminId : null,
      ":id" => $queueId,
    ]);

    $history = $pdo->prepare("
      INSERT INTO queue_status_history (queue_id, category, old_status, new_status, admin_id, admin_name, notes, action_type)
      VALUES (:queue_id, :category, :old_status, :new_status, :admin_id, :admin_name, :notes, 'send_back')
      RETURNING id
    ");
    $history->execute([
      ":queue_id" => $queueId,
      ":category" => trim((string)($queue["category"] ?? "")),
      ":old_status" => $currentStatus,
      ":new_status" => $currentStatus,
      ":admin_id" => $adminId > 0 ? $adminId : null,
      ":admin_name" => servitech_queue_actor_name($pdo, $adminId),
      ":notes" => $message,
    ]);
    $historyId = (int)($history->fetchColumn() ?: 0);

    $queueCode = trim((string)($queue["queue_code"] ?? ""));
    servitech_add_notification(
      $pdo,
      (int)$queue["user_id"],
      "send_back",
      $queueId,
      "Your order/request has been sent back for editing. Message: {$message}",
      "customer_send_back:{$queueId}:{$historyId}",
      true
    );

    $actorName = servitech_queue_actor_name($pdo, $adminId);
    servitech_activity_log($pdo, [
      "actor_id" => $adminId,
      "action_type" => "queue_send_back",
      "module" => "queue_management",
      "target_record_id" => $queueCode,
      "new_value" => ["message" => $message],
      "description" => trim(($actorName !== "" ? "Admin {$actorName}" : "Admin") . " sent Queue {$queueCode} back to the customer for editing."),
    ]);

    if ($ownsTransaction) $pdo->commit();
    return [
      "status" => $currentStatus,
      "queue_code" => $queueCode,
      "customer_edit_required" => true,
      "send_back_message" => $message,
    ];
  } catch (Throwable $e) {
    if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function servitech_queue_lifecycle_stage_after_transition(string $currentLifecycleStage, string $newStatus): string {
  $currentLifecycleStage = strtoupper(trim($currentLifecycleStage));
  $newStatus = servitech_queue_normalize_status($newStatus);

  if (in_array($newStatus, ["DONE", "CANCELLED"], true)) {
    return "ORDER";
  }

  return $currentLifecycleStage === "ORDER" ? "ORDER" : "QUEUE";
}

function servitech_transition_queue_status(PDO $pdo, int $queueId, string $requestedStatus, int $adminId, string $notes = ""): array {
  $newStatus = servitech_queue_normalize_status($requestedStatus);
  $notes = trim($notes);
  if ($queueId <= 0) throw new DomainException("Invalid queue/order ID.");
  if (!in_array($newStatus, ["APPROVED", "ONGOING", "FOR PICK-UP", "DONE", "CANCELLED"], true)) {
    throw new DomainException("Invalid status requested.");
  }
  if ($newStatus === "CANCELLED" && $notes === "") {
    throw new DomainException("Cancellation reason is required.");
  }
  if (servitech_text_length($notes) > SERVITECH_LIMIT_STATUS_NOTES) {
    throw new DomainException("Status notes cannot exceed " . SERVITECH_LIMIT_STATUS_NOTES . " characters.");
  }

  $ownsTransaction = !$pdo->inTransaction();
  if ($ownsTransaction) $pdo->beginTransaction();

  try {
    servitech_ensure_queue_lifecycle_schema($pdo);
    $stmt = $pdo->prepare("
      SELECT q.id, q.user_id, q.queue_code, q.category, q.status, q.lifecycle_stage,
        q.details, q.created_at, q.price, q.paid_amount, p.id AS payment_id,
        p.amount AS payment_amount, p.payment_method, p.reference_number,
        p.status AS payment_status
      FROM queues q
      LEFT JOIN LATERAL (
        SELECT id, amount, payment_method, reference_number, status
        FROM payments
        WHERE queue_id = q.id
        ORDER BY id DESC
        LIMIT 1
      ) p ON TRUE
      WHERE q.id = :id
      LIMIT 1
      FOR UPDATE OF q
    ");
    $stmt->execute([":id" => $queueId]);
    $queue = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($queue)) throw new DomainException("Queue/order not found.");

    $currentStatus = servitech_queue_normalize_status((string)($queue["status"] ?? "PENDING"));
    $lifecycleStage = servitech_queue_lifecycle_stage_after_transition(
      (string)($queue["lifecycle_stage"] ?? "QUEUE"),
      $newStatus
    );
    if (!in_array($newStatus, servitech_queue_allowed_transitions($queue), true)) {
      throw new DomainException(servitech_queue_transition_error($queue, $newStatus));
    }

    $payment = servitech_queue_payment_values($queue);
    $clearCustomerEdit = servitech_queue_is_customer_editable_status($newStatus) ? 0 : 1;
    $update = $pdo->prepare("
      UPDATE queues
      SET status = :status,
          lifecycle_stage = :lifecycle_stage,
          customer_edit_required = CASE WHEN :clear_customer_edit = 1 THEN FALSE ELSE customer_edit_required END,
          send_back_message = CASE WHEN :clear_customer_edit = 1 THEN '' ELSE send_back_message END,
          send_back_at = CASE WHEN :clear_customer_edit = 1 THEN NULL ELSE send_back_at END,
          send_back_by = CASE WHEN :clear_customer_edit = 1 THEN NULL ELSE send_back_by END,
          completed_at = CASE WHEN :completed_status = 'DONE' THEN COALESCE(completed_at, NOW()) ELSE completed_at END,
          closed_at = CASE
            WHEN :closed_status IN ('DONE', 'CANCELLED') THEN COALESCE(closed_at, NOW())
            ELSE closed_at
          END,
          price = CASE
            WHEN :sync_payment = 1 THEN COALESCE(price, :resolved_price_for_price)
            ELSE price
          END,
          paid_amount = CASE
            WHEN :paid_status = 'DONE' THEN COALESCE(price, :resolved_price_for_paid)
            WHEN :cancelled_status = 'CANCELLED' THEN 0
            ELSE paid_amount
          END,
          updated_at = NOW()
      WHERE id = :id
    ");
    $update->execute([
      ":status" => $newStatus,
      ":lifecycle_stage" => $lifecycleStage,
      ":clear_customer_edit" => $clearCustomerEdit,
      ":completed_status" => $newStatus,
      ":closed_status" => $newStatus,
      ":sync_payment" => in_array($newStatus, ["DONE", "CANCELLED"], true) ? 1 : 0,
      ":resolved_price_for_price" => $payment["price"],
      ":paid_status" => $newStatus,
      ":resolved_price_for_paid" => $payment["price"],
      ":cancelled_status" => $newStatus,
      ":id" => $queueId,
    ]);

    if (!empty($queue["payment_id"])) {
      $paymentStatus = match ($newStatus) {
        "APPROVED" => servitech_queue_is_online_payment_method($queue) ? "APPROVED" : null,
        "DONE" => "PAID",
        "CANCELLED" => "CANCELLED",
        default => null,
      };
      if ($paymentStatus !== null) {
        $paymentUpdate = $pdo->prepare("
          UPDATE payments
          SET status = :status, updated_at = NOW()
          WHERE id = :payment_id
        ");
        $paymentUpdate->execute([
          ":status" => $paymentStatus,
          ":payment_id" => (int)$queue["payment_id"],
        ]);
        $queue["payment_status"] = $paymentStatus;
      }
    }

    servitech_record_queue_status_history(
      $pdo,
      $queueId,
      (string)($queue["category"] ?? ""),
      $currentStatus,
      $newStatus,
      $adminId,
      $notes
    );
    servitech_record_queue_analytics_transition(
      $pdo,
      $queue,
      $currentStatus,
      $newStatus,
      $adminId,
      $notes
    );

    $queueCode = trim((string)($queue["queue_code"] ?? ""));
    $customerNotificationMessage = servitech_queue_customer_status_message($queue, $newStatus, $notes);
    $customerNotificationEventKey = "customer_status_change:{$queueId}:{$currentStatus}:{$newStatus}";
    servitech_add_notification(
      $pdo,
      (int)$queue["user_id"],
      $newStatus === "CANCELLED" ? "queue_cancelled" : "status_update",
      $queueId,
      $customerNotificationMessage,
      $customerNotificationEventKey,
      true
    );

    if ($newStatus === "CANCELLED") {
      servitech_notify_admins(
        $pdo,
        "admin_cancelled",
        $queueId,
        "Queue {$queueCode}: Order/request cancelled. Reason: {$notes}",
        "admin_cancelled:admin:{$queueId}:{$currentStatus}",
        true
      );
    }

    $actorName = servitech_queue_actor_name($pdo, $adminId);
    $actorLabel = $actorName !== "" ? "Admin {$actorName}" : "Admin";
    $actionType = match ($newStatus) {
      "DONE" => "order_mark_done",
      "APPROVED" => servitech_queue_is_online_payment_method($queue) ? "payment_approve" : "order_status_update",
      "CANCELLED" => "order_cancel",
      default => "order_status_update",
    };
    $module = strtoupper((string)($queue["lifecycle_stage"] ?? "QUEUE")) === "ORDER" ? "order_management" : "queue_management";
    $statusPhrase = match ($newStatus) {
      "DONE" => "marked Order {$queueCode} as Done",
      "APPROVED" => servitech_queue_is_online_payment_method($queue)
        ? "approved payment for Queue {$queueCode}"
        : "updated Queue {$queueCode} to Approved",
      "CANCELLED" => "cancelled Order {$queueCode}",
      default => "updated Queue {$queueCode} from {$currentStatus} to {$newStatus}",
    };
    servitech_activity_log($pdo, [
      "actor_id" => $adminId,
      "action_type" => $actionType,
      "module" => $module,
      "target_record_id" => $queueCode,
      "old_value" => ["status" => $currentStatus],
      "new_value" => ["status" => $newStatus],
      "description" => "{$actorLabel} {$statusPhrase}.",
    ]);

    if ($ownsTransaction) $pdo->commit();

    $queue["status"] = $newStatus;
    $queue["lifecycle_stage"] = $lifecycleStage;
    $queue["price"] = $payment["price"];
    $queue["paid_amount"] = $newStatus === "DONE" ? $payment["price"] : ($newStatus === "CANCELLED" ? 0 : $payment["paid_amount"]);
    $allowedTransitions = servitech_queue_allowed_transitions($queue);

    return [
      "status" => $newStatus,
      "lifecycle_stage" => $lifecycleStage,
      "allowed_transitions" => $allowedTransitions,
      "allowedStatuses" => $allowedTransitions,
      "payment" => servitech_queue_payment_values($queue),
      "payment_status" => (string)($queue["payment_status"] ?? ""),
    ];
  } catch (Throwable $e) {
    if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}
