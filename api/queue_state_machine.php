<?php
require_once __DIR__ . "/queue_helpers.php";
require_once __DIR__ . "/queue_payment.php";

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

function servitech_queue_is_online_print_order(array $queue): bool {
  $category = strtolower(trim((string)($queue["category"] ?? "")));
  $queueCode = strtoupper(trim((string)($queue["queue_code"] ?? "")));

  return in_array($category, ["online_printorder", "printing_online"], true)
    || str_starts_with($queueCode, "OP");
}

function servitech_queue_allowed_transitions(array $queue): array {
  $status = servitech_queue_normalize_status((string)($queue["status"] ?? "PENDING"));

  if (servitech_queue_is_online_print_order($queue)) {
    return match ($status) {
      "PENDING" => ["APPROVED", "CANCELLED"],
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

function servitech_queue_customer_subject(array $queue): string {
  $category = strtolower(trim((string)($queue["category"] ?? "")));

  if (servitech_queue_is_online_print_order($queue)) {
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
    "APPROVED" => "{$statusLead} Your payment has been approved and your order is waiting to be processed.",
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
}

function servitech_queue_is_customer_editable_status(string $status): bool {
  return in_array(servitech_queue_normalize_status($status), ["PENDING", "APPROVED"], true);
}

function servitech_send_queue_back_to_customer(PDO $pdo, int $queueId, int $adminId, string $message): array {
  $message = trim($message);
  if ($queueId <= 0) throw new DomainException("Invalid queue/order ID.");
  if ($message === "") throw new DomainException("Send-back message is required.");
  if (mb_strlen($message) > 1000) throw new DomainException("Send-back message cannot exceed 1000 characters.");

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
  if (strlen($notes) > 1000) {
    throw new DomainException("Status notes cannot exceed 1000 characters.");
  }

  $ownsTransaction = !$pdo->inTransaction();
  if ($ownsTransaction) $pdo->beginTransaction();

  try {
    servitech_ensure_queue_lifecycle_schema($pdo);
    $stmt = $pdo->prepare("
      SELECT q.id, q.user_id, q.queue_code, q.category, q.status, q.lifecycle_stage,
        q.details, q.price, q.paid_amount, p.amount AS payment_amount
      FROM queues q
      LEFT JOIN LATERAL (
        SELECT amount
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

    servitech_record_queue_status_history(
      $pdo,
      $queueId,
      (string)($queue["category"] ?? ""),
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

    if ($ownsTransaction) $pdo->commit();
    $queue["status"] = $newStatus;
    $queue["lifecycle_stage"] = $lifecycleStage;
    $queue["price"] = $payment["price"];
    $queue["paid_amount"] = $newStatus === "DONE" ? $payment["price"] : ($newStatus === "CANCELLED" ? 0 : $payment["paid_amount"]);
    return [
      "status" => $newStatus,
      "lifecycle_stage" => $lifecycleStage,
      "allowed_transitions" => servitech_queue_allowed_transitions($queue),
      "payment" => servitech_queue_payment_values($queue),
    ];
  } catch (Throwable $e) {
    if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}
