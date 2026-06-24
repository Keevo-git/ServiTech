<?php

try {
    require_once __DIR__ . "/../config/db.php";
} catch (Throwable $exception) {
    echo "SKIP: Notification database integration test unavailable (database connection failed).\n";
    exit(0);
}
require_once __DIR__ . "/../api/queue_helpers.php";
require_once __DIR__ . "/../pages/admin/_includes/admin_notification_center.php";
ob_start();
require_once __DIR__ . "/../components/header.php";
ob_end_clean();

$failures = [];

function notification_integration_assert(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

$adminId = (int)($pdo->query("
    SELECT id FROM users
    WHERE LOWER(TRIM(COALESCE(role, 'customer'))) = 'admin'
    ORDER BY id ASC LIMIT 1
")->fetchColumn() ?: 0);
$customerId = (int)($pdo->query("
    SELECT id FROM users
    WHERE LOWER(TRIM(COALESCE(role, 'customer'))) = 'customer'
    ORDER BY id ASC LIMIT 1
")->fetchColumn() ?: 0);

notification_integration_assert($adminId > 0, "An admin account is required for notification integration verification.");
notification_integration_assert($customerId > 0, "A customer account is required for notification integration verification.");

if (!$failures) {
    $token = bin2hex(random_bytes(8));
    $customerEventKey = "audit_customer_status:" . $token;
    $adminEventKey = "audit_admin_new_order:" . $token;

    $pdo->beginTransaction();
    try {
        servitech_add_notification(
            $pdo,
            $customerId,
            "status_update",
            null,
            "Notification audit: customer status updated.",
            $customerEventKey,
            true
        );
        servitech_add_notification(
            $pdo,
            $customerId,
            "status_update",
            null,
            "Notification audit: customer status updated.",
            $customerEventKey,
            true
        );
        servitech_notify_admins(
            $pdo,
            "admin_new_order",
            null,
            "Notification audit: new order submitted.",
            $adminEventKey,
            true
        );
        servitech_notify_admins(
            $pdo,
            "admin_new_order",
            null,
            "Notification audit: new order submitted.",
            $adminEventKey,
            true
        );

        $customerStmt = $pdo->prepare("
            SELECT id, user_id, is_read, deleted_at
            FROM notifications WHERE event_key = :event_key
        ");
        $customerStmt->execute([":event_key" => $customerEventKey]);
        $customerRows = $customerStmt->fetchAll(PDO::FETCH_ASSOC);
        notification_integration_assert(count($customerRows) === 1, "Customer event-key deduplication must create exactly one notification.");
        notification_integration_assert((int)($customerRows[0]["user_id"] ?? 0) === $customerId, "Customer notification must not leak to another user.");

        $customerData = servitech_notification_fetch_all($pdo, $customerId);
        $matchingCustomerRows = array_values(array_filter(
            $customerData,
            static fn(array $row): bool => ($row["message"] ?? "") === "Notification audit: customer status updated."
        ));
        notification_integration_assert(count($matchingCustomerRows) === 1, "The customer panel snapshot must include the new event exactly once.");
        notification_integration_assert(
            !servitech_notification_bool($matchingCustomerRows[0]["is_read"] ?? true),
            "The new customer notification must appear as unread."
        );

        $adminStmt = $pdo->prepare("
            SELECT n.id, n.user_id, u.role, n.is_read, n.deleted_at
            FROM notifications n JOIN users u ON u.id = n.user_id
            WHERE n.event_key = :event_key
        ");
        $adminStmt->execute([":event_key" => $adminEventKey]);
        $adminRows = $adminStmt->fetchAll(PDO::FETCH_ASSOC);
        notification_integration_assert(count($adminRows) === 1, "Admin event-key deduplication must create exactly one notification.");
        notification_integration_assert(strtolower(trim((string)($adminRows[0]["role"] ?? ""))) === "admin", "Admin notification must target an admin account.");

        $adminData = admin_notification_center_data($pdo);
        $matchingAdminRows = array_values(array_filter(
            $adminData["notifications"] ?? [],
            static fn(array $row): bool => ($row["event_key"] ?? "") === $adminEventKey
        ));
        notification_integration_assert(count($matchingAdminRows) === 1, "The admin notification snapshot must include the new event exactly once.");
        notification_integration_assert(
            $matchingAdminRows && admin_notification_event_category($matchingAdminRows[0]) === "new-orders",
            "The admin snapshot must classify a new-order event correctly."
        );

        ob_start();
        admin_notification_render_items($matchingAdminRows);
        $rendered = (string)ob_get_clean();
        notification_integration_assert(str_contains($rendered, "Notification audit: new order submitted."), "The live admin panel renderer must include the latest message.");
        notification_integration_assert(substr_count($rendered, "admin-notification-item") > 0, "The live admin panel renderer must emit a notification item.");

        $customerNotificationId = (int)($customerRows[0]["id"] ?? 0);
        $adminNotificationId = (int)($adminRows[0]["id"] ?? 0);
        $stateUpdate = $pdo->prepare("
            UPDATE notifications SET is_read = TRUE
            WHERE id IN (:customer_id, :admin_id)
        ");
        $stateUpdate->execute([
            ":customer_id" => $customerNotificationId,
            ":admin_id" => $adminNotificationId,
        ]);
        $readCheck = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE id IN (:customer_id, :admin_id) AND is_read = TRUE");
        $readCheck->execute([
            ":customer_id" => $customerNotificationId,
            ":admin_id" => $adminNotificationId,
        ]);
        notification_integration_assert((int)$readCheck->fetchColumn() === 2, "Customer and admin read state must persist.");
        $readCustomerData = servitech_notification_fetch_all($pdo, $customerId);
        $readCustomerMatch = array_values(array_filter(
            $readCustomerData,
            static fn(array $row): bool => (int)($row["id"] ?? 0) === $customerNotificationId
        ));
        notification_integration_assert(
            count($readCustomerMatch) === 1 && servitech_notification_bool($readCustomerMatch[0]["is_read"] ?? false),
            "The customer panel snapshot must reflect mark-as-read state."
        );

        $deleteUpdate = $pdo->prepare("
            UPDATE notifications SET deleted_at = NOW()
            WHERE id IN (:customer_id, :admin_id)
        ");
        $deleteUpdate->execute([
            ":customer_id" => $customerNotificationId,
            ":admin_id" => $adminNotificationId,
        ]);
        $deleteCheck = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE id IN (:customer_id, :admin_id) AND deleted_at IS NOT NULL");
        $deleteCheck->execute([
            ":customer_id" => $customerNotificationId,
            ":admin_id" => $adminNotificationId,
        ]);
        notification_integration_assert((int)$deleteCheck->fetchColumn() === 2, "Customer and admin soft-delete state must persist.");
        $deletedCustomerData = servitech_notification_fetch_all($pdo, $customerId);
        notification_integration_assert(
            !array_filter(
                $deletedCustomerData,
                static fn(array $row): bool => (int)($row["id"] ?? 0) === $customerNotificationId
            ),
            "The customer panel snapshot must exclude a soft-deleted notification."
        );
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Notification realtime integration tests passed.\n";
