<?php

function servitech_queue_payment_status(string $status): string {
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
  $status = servitech_queue_payment_status((string)($queue["status"] ?? "PENDING"));
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
      SELECT id, status
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

    $status = servitech_queue_payment_status((string)($queue["status"] ?? "PENDING"));
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
          updated_at = NOW()
      WHERE id = :id
    ");
    $update->execute([
      ":price" => $price,
      ":paid_amount" => $paidAmount,
      ":id" => $queueId,
    ]);

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
