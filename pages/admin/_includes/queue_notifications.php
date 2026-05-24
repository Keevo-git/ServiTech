<?php

function servitech_queue_status_label(string $status): string
{
    $status = strtoupper(trim($status));

    return match ($status) {
        "ONGOING" => "started",
        "FOR PICK-UP" => "ready for pick-up",
        "DONE" => "marked as done",
        "CANCELLED" => "cancelled",
        default => strtolower($status),
    };
}

function servitech_ensure_notifications_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id BIGSERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL,
            type TEXT NOT NULL DEFAULT 'admin_message',
            reference_id INTEGER NULL,
            message TEXT NOT NULL,
            is_read BOOLEAN NOT NULL DEFAULT FALSE,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    ");
}

function servitech_insert_queue_status_notification(PDO $pdo, array $queue, string $newStatus): void
{
    $userId = (int)($queue["user_id"] ?? 0);
    if ($userId <= 0) {
        return;
    }

    $queueId = (int)($queue["id"] ?? 0);
    $queueCode = trim((string)($queue["queue_code"] ?? ""));
    $category = trim((string)($queue["category"] ?? "admin_message"));
    $statusLabel = servitech_queue_status_label($newStatus);
    $message = "Queue {$queueCode}: Your request is now {$statusLabel}.";

    servitech_ensure_notifications_table($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, type, reference_id, message, is_read, created_at)
        VALUES (:user_id, :type, :reference_id, :message, FALSE, NOW())
    ");
    $stmt->execute([
        ":user_id" => $userId,
        ":type" => $category !== "" ? $category : "admin_message",
        ":reference_id" => $queueId > 0 ? $queueId : null,
        ":message" => $message,
    ]);
}
