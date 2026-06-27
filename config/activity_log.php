<?php
require_once __DIR__ . "/session_check.php";

if (!function_exists("servitech_activity_request_ip")) {
    function servitech_activity_request_ip(): string
    {
        $candidates = [
            $_SERVER["HTTP_CF_CONNECTING_IP"] ?? "",
            $_SERVER["HTTP_X_FORWARDED_FOR"] ?? "",
            $_SERVER["REMOTE_ADDR"] ?? "",
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === "") {
                continue;
            }
            $ip = trim(explode(",", $candidate)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return "";
    }
}

if (!function_exists("servitech_activity_actor_snapshot")) {
    function servitech_activity_actor_snapshot(PDO $pdo, ?int $actorId = null): array
    {
        $actorId = $actorId ?? (int)($_SESSION["user_id"] ?? 0);
        $role = servitech_current_role();
        $name = "";

        if ($actorId > 0) {
            try {
                $stmt = $pdo->prepare("
                    SELECT COALESCE(NULLIF(fullname, ''), email, '') AS actor_name,
                           COALESCE(NULLIF(LOWER(TRIM(role)), ''), 'customer') AS role
                    FROM users
                    WHERE id = :id
                    LIMIT 1
                ");
                $stmt->execute([":id" => $actorId]);
                $actor = $stmt->fetch(PDO::FETCH_ASSOC);
                if (is_array($actor)) {
                    $name = trim((string)($actor["actor_name"] ?? ""));
                    $role = servitech_normalize_role($actor["role"] ?? $role);
                }
            } catch (Throwable $exception) {
                error_log("activity actor lookup failed: " . $exception->getMessage());
            }
        }

        return [
            "id" => $actorId > 0 ? $actorId : null,
            "name" => $name,
            "role" => $role,
        ];
    }
}

if (!function_exists("servitech_activity_log_table_ready")) {
    function servitech_activity_log_table_ready(PDO $pdo): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }

        try {
            $stmt = $pdo->query("
                SELECT EXISTS (
                    SELECT 1
                    FROM information_schema.tables
                    WHERE table_schema = ANY(current_schemas(false))
                      AND table_name = 'activity_logs'
                )
            ");
            $ready = (bool)$stmt->fetchColumn();
        } catch (Throwable $exception) {
            error_log("activity log schema check failed: " . $exception->getMessage());
            $ready = false;
        }

        return $ready;
    }
}

if (!function_exists("servitech_activity_success_action_types")) {
    function servitech_activity_success_action_types(): array
    {
        return [
            "super_admin_login_success",
            "admin_login_success",
            "logout",
            "order_status_update",
            "order_mark_done",
            "order_cancel",
            "order_reject",
            "order_report_export",
            "queue_send_back",
            "queue_status_update",
            "queue_currently_serving_update",
            "queue_update",
            "customer_message_send",
            "payment_approve",
            "payment_reject",
            "payment_update",
            "employee_first_time_setup_complete",
            "admin_password_change",
        ];
    }
}

if (!function_exists("servitech_activity_security_action_types")) {
    function servitech_activity_security_action_types(): array
    {
        return [
            "unauthorized_access",
            "super_admin_wrong_role_login",
            "admin_wrong_role_login",
            "customer_wrong_role_login",
            "employee_login_before_email_verification",
            "repeated_failed_login",
        ];
    }
}

if (!function_exists("servitech_activity_allowed_action_types")) {
    function servitech_activity_allowed_action_types(bool $includeSecurity = true): array
    {
        $actions = servitech_activity_success_action_types();
        if ($includeSecurity) {
            $actions = array_merge($actions, servitech_activity_security_action_types());
        }

        return array_values(array_unique($actions));
    }
}

if (!function_exists("servitech_activity_should_store_event")) {
    function servitech_activity_should_store_event(string $actionType, string $status): bool
    {
        $actionType = trim($actionType);
        $status = strtolower(trim($status)) === "failed" ? "failed" : "success";

        if ($status === "failed") {
            return in_array($actionType, servitech_activity_security_action_types(), true);
        }

        return in_array($actionType, servitech_activity_success_action_types(), true);
    }
}

if (!function_exists("servitech_activity_log")) {
    function servitech_activity_log(PDO $pdo, array $event): void
    {
        if (!servitech_activity_log_table_ready($pdo)) {
            return;
        }

        $actor = servitech_activity_actor_snapshot(
            $pdo,
            isset($event["actor_id"]) ? (int)$event["actor_id"] : null
        );

        $actionType = trim((string)($event["action_type"] ?? ""));
        $module = trim((string)($event["module"] ?? ""));
        $description = trim((string)($event["description"] ?? ""));
        if ($actionType === "" || $module === "" || $description === "") {
            return;
        }
        $status = trim((string)($event["status"] ?? "success")) === "failed" ? "failed" : "success";
        if (!servitech_activity_should_store_event($actionType, $status)) {
            return;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO activity_logs (
                    user_id, user_name, role, action_type, target_module,
                    target_record_id, old_value, new_value, description,
                    ip_address, user_agent, status, created_at
                ) VALUES (
                    :user_id, :user_name, :role, :action_type, :target_module,
                    :target_record_id, CAST(:old_value AS jsonb), CAST(:new_value AS jsonb),
                    :description, :ip_address, :user_agent, :status, NOW()
                )
            ");
            $stmt->execute([
                ":user_id" => $actor["id"],
                ":user_name" => (string)($event["actor_name"] ?? $actor["name"]),
                ":role" => servitech_normalize_role($event["role"] ?? $actor["role"]),
                ":action_type" => $actionType,
                ":target_module" => $module,
                ":target_record_id" => trim((string)($event["target_record_id"] ?? "")) ?: null,
                ":old_value" => json_encode($event["old_value"] ?? null, JSON_UNESCAPED_SLASHES),
                ":new_value" => json_encode($event["new_value"] ?? null, JSON_UNESCAPED_SLASHES),
                ":description" => $description,
                ":ip_address" => servitech_activity_request_ip() ?: null,
                ":user_agent" => substr((string)($_SERVER["HTTP_USER_AGENT"] ?? ""), 0, 500),
                ":status" => $status,
            ]);
        } catch (Throwable $exception) {
            error_log("activity log insert failed: " . $exception->getMessage());
        }
    }
}
