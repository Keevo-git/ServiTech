<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/app.php";

if (servitech_supabase_auth_enabled()) {
    header("Location: " . servitech_url("/auth/log_in.php?verification=disabled"));
    exit();
}

$token = trim((string)($_GET["token"] ?? ""));
if (!preg_match('/\A[a-f0-9]{64}\z/i', $token)) {
    header("Location: " . servitech_url("/auth/log_in.php?verification=invalid"));
    exit();
}

try {
    $stmt = $pdo->prepare("
        UPDATE users
        SET email_verified_at = NOW(),
            email_verification_token = NULL,
            email_verification_expires = NULL,
            updated_at = NOW()
        WHERE email_verification_token = :token_hash
          AND email_verification_expires > NOW()
          AND email_verified_at IS NULL
    ");
    $stmt->execute([":token_hash" => hash("sha256", $token)]);

    $result = $stmt->rowCount() === 1 ? "success" : "invalid";
    header("Location: " . servitech_url("/auth/log_in.php?verification=" . $result));
    exit();
} catch (Throwable $e) {
    error_log("email verification error: " . $e->getMessage());
    header("Location: " . servitech_url("/auth/log_in.php?verification=invalid"));
    exit();
}
