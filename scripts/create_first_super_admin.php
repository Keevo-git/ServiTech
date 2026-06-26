<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/account.php";
require_once __DIR__ . "/../config/session_check.php";

if (PHP_SAPI !== "cli") {
    fwrite(STDERR, "This setup script can only be run from the command line.\n");
    exit(1);
}

$allowAdditional = in_array("--allow-additional", $argv, true);

function setup_prompt(string $label, bool $required = true): string
{
    do {
        fwrite(STDOUT, $label . ": ");
        $value = trim((string)fgets(STDIN));
    } while ($required && $value === "");

    return $value;
}

function setup_schema_ready(PDO $pdo): bool
{
    $required = [
        "account_status",
        "force_password_change",
        "created_by",
        "email_verified_at",
        "password_hash",
        "role",
    ];
    $placeholders = implode(",", array_fill(0, count($required), "?"));
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT column_name)
        FROM information_schema.columns
        WHERE table_schema = ANY(current_schemas(false))
          AND table_name = 'users'
          AND column_name IN ({$placeholders})
    ");
    $stmt->execute($required);
    return (int)$stmt->fetchColumn() === count($required);
}

if (servitech_supabase_auth_enabled() && !$allowAdditional) {
    fwrite(STDERR, "Supabase Auth is enabled. This script creates a local users-table password account only.\n");
    fwrite(STDERR, "Create/link the owner in Supabase Auth first, or run only in local password-auth mode.\n");
    exit(1);
}

if (!setup_schema_ready($pdo)) {
    fwrite(STDERR, "Run database/migrations/20260626_add_super_admin_roles_and_activity_logs.sql first.\n");
    exit(1);
}

$countStmt = $pdo->query("
    SELECT COUNT(*)
    FROM users
    WHERE LOWER(TRIM(COALESCE(role, 'customer'))) = 'super_admin'
      AND LOWER(TRIM(COALESCE(account_status, 'active'))) = 'active'
");
$activeSuperAdmins = (int)$countStmt->fetchColumn();
if ($activeSuperAdmins > 0 && !$allowAdditional) {
    fwrite(STDERR, "An active Super Admin already exists. Use Staff Account Management to add more.\n");
    fwrite(STDERR, "If you intentionally need another from CLI, rerun with --allow-additional.\n");
    exit(1);
}

fwrite(STDOUT, "Create a new ServiTech Super Admin account\n");
fwrite(STDOUT, "Password input may be visible in this terminal. It will not be stored in plain text.\n\n");

$fullname = setup_prompt("Full name");
$email = strtolower(setup_prompt("Email"));
$contact = setup_prompt("Contact number (optional)", false);
$password = setup_prompt("Temporary password");
$confirmPassword = setup_prompt("Confirm temporary password");

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email address.\n");
    exit(1);
}

if ($password !== $confirmPassword) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}

$passwordError = servitech_password_validation_error($password);
if ($passwordError !== "") {
    fwrite(STDERR, $passwordError . "\n");
    exit(1);
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO users (
            fullname, email, contact, password_hash, role, account_status,
            force_password_change, email_verified_at, created_at, updated_at
        ) VALUES (
            :fullname, :email, :contact, :password_hash, 'super_admin', 'active',
            FALSE, NOW(), NOW(), NOW()
        )
        RETURNING id
    ");
    $stmt->execute([
        ":fullname" => $fullname,
        ":email" => $email,
        ":contact" => $contact !== "" ? $contact : null,
        ":password_hash" => password_hash($password, PASSWORD_DEFAULT),
    ]);

    $newId = (int)$stmt->fetchColumn();
    fwrite(STDOUT, "Super Admin account created successfully. User ID: {$newId}\n");
    fwrite(STDOUT, "Login email: {$email}\n");
} catch (PDOException $exception) {
    $message = strtolower($exception->getMessage());
    if (str_contains($message, "unique") || str_contains($message, "duplicate")) {
        fwrite(STDERR, "That email already exists. Use a different email or promote/reset the existing account.\n");
    } else {
        fwrite(STDERR, "Unable to create Super Admin account: " . $exception->getMessage() . "\n");
    }
    exit(1);
}
