<?php
require_once __DIR__ . "/session_check.php";

function servitech_employee_setup_columns_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $stmt = $pdo->query("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = ANY(current_schemas(false))
              AND table_name = 'users'
              AND column_name IN (
                'force_password_change',
                'profile_completed',
                'first_login_completed_at',
                'address',
                'emergency_contact_name',
                'emergency_contact_relationship',
                'emergency_contact_address',
                'emergency_contact_number'
              )
        ");
        $ready = (int)$stmt->fetchColumn() >= 8;
    } catch (Throwable $exception) {
        error_log("employee setup schema check failed: " . $exception->getMessage());
        $ready = false;
    }

    return $ready;
}

function servitech_employee_setup_status(PDO $pdo, ?int $userId = null): array
{
    $userId = $userId ?? (int)($_SESSION["user_id"] ?? 0);
    if ($userId <= 0 || !servitech_employee_setup_columns_ready($pdo)) {
        return [
            "required" => false,
            "force_password_change" => false,
            "profile_completed" => true,
        ];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(force_password_change, FALSE) AS force_password_change,
                   COALESCE(profile_completed, TRUE) AS profile_completed
            FROM users
            WHERE id = :id
              AND LOWER(TRIM(COALESCE(role, 'customer'))) = 'admin'
            LIMIT 1
        ");
        $stmt->execute([":id" => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [
                "required" => false,
                "force_password_change" => false,
                "profile_completed" => true,
            ];
        }

        $forcePasswordChange = filter_var($row["force_password_change"] ?? false, FILTER_VALIDATE_BOOLEAN);
        $profileCompleted = filter_var($row["profile_completed"] ?? true, FILTER_VALIDATE_BOOLEAN);
        return [
            "required" => $forcePasswordChange || !$profileCompleted,
            "force_password_change" => $forcePasswordChange,
            "profile_completed" => $profileCompleted,
        ];
    } catch (Throwable $exception) {
        error_log("employee setup status lookup failed: " . $exception->getMessage());
        return [
            "required" => false,
            "force_password_change" => false,
            "profile_completed" => true,
        ];
    }
}

function servitech_employee_setup_required(PDO $pdo, ?int $userId = null): bool
{
    return (bool)servitech_employee_setup_status($pdo, $userId)["required"];
}

function servitech_employee_setup_path(): string
{
    return "/pages/admin/admin_first_time_setup.php";
}
