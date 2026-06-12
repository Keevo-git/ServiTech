<?php

const SERVITECH_PASSWORD_MIN_LENGTH = 8;
const SERVITECH_PASSWORD_MAX_BYTES = 72;
const SERVITECH_EMAIL_VERIFICATION_TTL_HOURS = 24;

function servitech_account_env(string $key, string $default = ""): string
{
    $value = getenv($key);
    if (!is_string($value) || trim($value) === "") {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? "";
    }

    return is_string($value) && trim($value) !== "" ? trim($value) : $default;
}

function servitech_account_env_bool(string $key, bool $default = false): bool
{
    $value = strtolower(servitech_account_env($key, $default ? "1" : "0"));
    return in_array($value, ["1", "true", "yes", "on"], true);
}

function servitech_account_env_int(string $key, int $default, int $minimum, int $maximum): int
{
    $value = servitech_account_env($key);
    if ($value === "" || !ctype_digit($value)) {
        return $default;
    }

    return max($minimum, min($maximum, (int)$value));
}

function servitech_password_validation_error(string $password): string
{
    if ($password === "") {
        return "Password is required.";
    }

    $characterLength = function_exists("mb_strlen") ? mb_strlen($password, "UTF-8") : strlen($password);
    if ($characterLength < SERVITECH_PASSWORD_MIN_LENGTH) {
        return "Password must be at least " . SERVITECH_PASSWORD_MIN_LENGTH . " characters.";
    }

    if (strlen($password) > SERVITECH_PASSWORD_MAX_BYTES) {
        return "Password must not exceed " . SERVITECH_PASSWORD_MAX_BYTES . " bytes.";
    }

    return "";
}

function servitech_account_consent_version(): string
{
    return servitech_account_env("AUTH_CONSENT_VERSION", "2026-06-12");
}

function servitech_account_email_verification_required(): bool
{
    return servitech_account_env_bool("AUTH_REQUIRE_EMAIL_VERIFICATION", false);
}

function servitech_account_public_url(string $path): string
{
    $baseUrl = rtrim(servitech_account_env("APP_PUBLIC_URL", "https://servitech.store"), "/");
    return $baseUrl . "/" . ltrim($path, "/");
}

function servitech_account_client_ip(): string
{
    $value = trim((string)($_SERVER["REMOTE_ADDR"] ?? ""));
    if ($value !== "" && filter_var($value, FILTER_VALIDATE_IP)) {
        return $value;
    }

    return "unknown";
}

function servitech_login_throttle_hash(string $value): string
{
    $secret = servitech_account_env("AUTH_LOGIN_THROTTLE_SECRET", "servitech-change-this-throttle-secret");
    return hash_hmac("sha256", $value, $secret);
}

function servitech_login_throttle_context(string $email): array
{
    $normalizedEmail = strtolower(trim($email));
    $ip = servitech_account_client_ip();

    return [
        "attempt_key" => servitech_login_throttle_hash($normalizedEmail . "|" . $ip),
        "email_hash" => servitech_login_throttle_hash($normalizedEmail),
        "ip_hash" => servitech_login_throttle_hash($ip),
    ];
}

function servitech_login_throttle_allows(PDO $pdo, string $email): bool
{
    $context = servitech_login_throttle_context($email);
    $windowSeconds = servitech_account_env_int("AUTH_LOGIN_WINDOW_SECONDS", 900, 60, 86400);
    $maxPairAttempts = servitech_account_env_int("AUTH_LOGIN_MAX_ATTEMPTS", 5, 2, 50);
    $maxEmailAttempts = servitech_account_env_int("AUTH_LOGIN_EMAIL_MAX_ATTEMPTS", 10, $maxPairAttempts, 100);

    $stmt = $pdo->prepare("
        SELECT
          COUNT(*) FILTER (WHERE attempt_key = :attempt_key) AS pair_attempts,
          COUNT(*) FILTER (WHERE email_hash = :email_hash) AS email_attempts
        FROM login_attempts
        WHERE attempted_at >= NOW() - (CAST(:window_seconds AS INTEGER) * INTERVAL '1 second')
    ");
    $stmt->execute([
        ":attempt_key" => $context["attempt_key"],
        ":email_hash" => $context["email_hash"],
        ":window_seconds" => $windowSeconds,
    ]);
    $attempts = $stmt->fetch() ?: [];

    return (int)($attempts["pair_attempts"] ?? 0) < $maxPairAttempts
        && (int)($attempts["email_attempts"] ?? 0) < $maxEmailAttempts;
}

function servitech_login_throttle_record_failure(PDO $pdo, string $email): void
{
    $context = servitech_login_throttle_context($email);

    $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL '1 day'");
    $stmt = $pdo->prepare("
        INSERT INTO login_attempts (attempt_key, email_hash, ip_hash)
        VALUES (:attempt_key, :email_hash, :ip_hash)
    ");
    $stmt->execute([
        ":attempt_key" => $context["attempt_key"],
        ":email_hash" => $context["email_hash"],
        ":ip_hash" => $context["ip_hash"],
    ]);
}

function servitech_login_throttle_clear(PDO $pdo, string $email): void
{
    $context = servitech_login_throttle_context($email);
    $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE email_hash = :email_hash");
    $stmt->execute([":email_hash" => $context["email_hash"]]);
}

function servitech_email_verification_token(): array
{
    $token = bin2hex(random_bytes(32));
    return [
        "token" => $token,
        "token_hash" => hash("sha256", $token),
    ];
}

function servitech_email_verification_url(string $token): string
{
    return servitech_account_public_url("/auth/verify_email.php?token=" . urlencode($token));
}
