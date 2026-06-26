<?php

if (!function_exists("servitech_notify_admin_new_customer")) {
    function servitech_notify_admin_new_customer(
        PDO $pdo,
        int $customerId,
        string $fullname,
        string $email
    ): bool {
        if ($customerId <= 0) {
            return false;
        }

        $fullname = trim($fullname);
        $email = strtolower(trim($email));
        $customerLabel = $fullname !== "" ? $fullname : ($email !== "" ? $email : "Customer #" . $customerId);
        $message = "A new customer has registered: " . $customerLabel;
        if ($email !== "") {
            $message .= ". Email: " . $email;
        }

        try {
            $stmt = $pdo->prepare("
                WITH admin_target AS (
                    SELECT id
                    FROM users
                    WHERE LOWER(TRIM(COALESCE(NULLIF(to_jsonb(users)->>'role', ''), 'customer'))) IN ('admin', 'super_admin')
                    ORDER BY id ASC
                    LIMIT 1
                )
                INSERT INTO notifications (
                    user_id, type, reference_id, message, event_key, is_read, created_at
                )
                SELECT
                    admin_target.id,
                    'new_customer_registration',
                    NULL,
                    :message,
                    :event_key,
                    FALSE,
                    NOW()
                FROM admin_target
                ON CONFLICT DO NOTHING
            ");
            $stmt->execute([
                ":message" => $message,
                ":event_key" => "new_customer_registration:" . $customerId,
            ]);
            return true;
        } catch (Throwable $exception) {
            error_log("new customer admin notification error: " . $exception->getMessage());
            return false;
        }
    }
}
