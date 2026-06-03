<?php
require_once __DIR__ . "/queue_helpers.php";

function servitech_queue_payment_queue_status(string $status): string {
  $status = strtoupper(trim($status));
  $status = preg_replace('/[\s_]+/', ' ', $status);

  return match ($status) {
    "FOR PICK UP", "FOR PICKUP" => "FOR PICK-UP",
    "COMPLETED" => "DONE",
    "CANCELED" => "CANCELLED",
    default => $status,
  };
}

function servitech_queue_payment_details($details): array {
  if (is_array($details)) {
    return $details;
  }

  if (is_string($details) && trim($details) !== "") {
    $decoded = json_decode($details, true);
    return is_array($decoded) ? $decoded : [];
  }

  return [];
}

function servitech_queue_payment_candidate($value): ?float {
  if (!is_numeric($value)) {
    return null;
  }

  $number = round((float)$value, 2);
  return $number >= 0 ? $number : null;
}

function servitech_queue_payment_price(array $queue): float {
  $details = servitech_queue_payment_details($queue["details"] ?? null);
  foreach ([
    $queue["price"] ?? null,
    $details["estimated_total"] ?? null,
    $queue["payment_amount"] ?? ($queue["amount"] ?? null),
  ] as $candidate) {
    $number = servitech_queue_payment_candidate($candidate);
    if ($number !== null) {
      return $number;
    }
  }

  return 0.0;
}

function servitech_queue_payment_values(array $queue): array {
  $status = servitech_queue_payment_queue_status((string)($queue["status"] ?? "PENDING"));
  $price = servitech_queue_payment_price($queue);
  $paidAmount = servitech_queue_payment_candidate($queue["paid_amount"] ?? null) ?? 0.0;
  $paidAmount = min($price, $paidAmount);

  if ($status === "DONE") {
    $paidAmount = $price;
  } elseif ($status === "CANCELLED") {
    $paidAmount = 0.0;
  }

  return [
    "price" => round($price, 2),
    "paid_amount" => round($paidAmount, 2),
    "paid_pending" => $status === "CANCELLED" ? 0.0 : round(max(0, $price - $paidAmount), 2),
  ];
}

function servitech_queue_payment_input($value, string $label): float {
  $raw = trim((string)$value);
  if ($raw === "" || !preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/', $raw)) {
    throw new DomainException("{$label} must be a valid non-negative amount with up to two decimal places.");
  }

  $amount = round((float)$raw, 2);
  if ($amount > 9999999999.99) {
    throw new DomainException("{$label} is too large.");
  }

  return $amount;
}

function servitech_update_queue_payment(PDO $pdo, int $queueId, $priceInput, $paidAmountInput): array {
  if ($queueId <= 0) {
    throw new DomainException("Invalid queue/order ID.");
  }

  $price = servitech_queue_payment_input($priceInput, "Price");
  $paidAmount = servitech_queue_payment_input($paidAmountInput, "Paid amount");
  $ownsTransaction = !$pdo->inTransaction();
  if ($ownsTransaction) {
    $pdo->beginTransaction();
  }

  try {
    $stmt = $pdo->prepare("
      SELECT id, user_id, queue_code, category, status, price
      FROM queues
      WHERE id = :id
      LIMIT 1
      FOR UPDATE
    ");
    $stmt->execute([":id" => $queueId]);
    $queue = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($queue)) {
      throw new DomainException("Queue/order not found.");
    }

    $status = servitech_queue_payment_queue_status((string)($queue["status"] ?? "PENDING"));
    $previousPrice = servitech_queue_payment_candidate($queue["price"] ?? null);
    $priceChanged = $previousPrice === null || abs($previousPrice - $price) >= 0.005;
    if ($status === "DONE") {
      $paidAmount = $price;
    } elseif ($status === "CANCELLED") {
      $paidAmount = 0.0;
    } elseif ($paidAmount > $price) {
      throw new DomainException("Paid amount cannot exceed the price.");
    }

    $update = $pdo->prepare("
      UPDATE queues
      SET price = :price,
          paid_amount = :paid_amount,
          updated_at = clock_timestamp()
      WHERE id = :id
      RETURNING updated_at
    ");
    $update->execute([
      ":price" => $price,
      ":paid_amount" => $paidAmount,
      ":id" => $queueId,
    ]);
    $updatedAt = trim((string)($update->fetchColumn() ?: ""));

    if ($priceChanged) {
      $queueCode = trim((string)($queue["queue_code"] ?? ""));
      $formattedPrice = number_format($price, 2, ".", ",");
      $formattedPreviousPrice = $previousPrice === null
        ? "Not previously set"
        : "PHP " . number_format($previousPrice, 2, ".", ",");
      $eventKey = "price_update:{$queueId}:" . ($updatedAt !== "" ? $updatedAt : microtime(true));
      servitech_add_notification(
        $pdo,
        (int)($queue["user_id"] ?? 0),
        "price_update",
        $queueId,
        "Your request with Queue ID {$queueCode} has a price update. Previous price: {$formattedPreviousPrice}. New price: PHP {$formattedPrice}. Please review the new amount in your service status.",
        $eventKey
      );
    }

    if ($ownsTransaction) {
      $pdo->commit();
    }

    return servitech_queue_payment_values([
      "status" => $status,
      "price" => $price,
      "paid_amount" => $paidAmount,
    ]);
  } catch (Throwable $e) {
    if ($ownsTransaction && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}
