<?php
require_once __DIR__ . "/app.php";

function servitech_remember_cookie_name(): string
{
    return "SERVITECHREMEMBER";
}

function servitech_remember_lifetime(): int
{
    $configured = getenv("REMEMBER_ME_LIFETIME_SECONDS");
    if (is_string($configured) && ctype_digit($configured) && (int)$configured > 0) {
        return min((int)$configured, 60 * 60 * 24 * 365);
    }

    return 60 * 60 * 24 * 30;
}

function servitech_remember_cookie_options(int $expires): array
{
    return [
        "expires" => $expires,
        "path" => servitech_cookie_path(),
        "secure" => servitech_request_is_https(),
        "httponly" => true,
        "samesite" => "Lax",
    ];
}

function servitech_remember_parse_cookie(?string $rawCookie = null): ?array
{
    $rawCookie = $rawCookie ?? (string)($_COOKIE[servitech_remember_cookie_name()] ?? "");
    if (!preg_match('/^([a-f0-9]{32})\.([A-Za-z0-9_-]{43})$/', $rawCookie, $matches)) {
        return null;
    }

    return [
        "selector" => $matches[1],
        "validator" => $matches[2],
        "raw" => $rawCookie,
    ];
}

function servitech_remember_clear_cookie(): void
{
    setcookie(
        servitech_remember_cookie_name(),
        "",
        servitech_remember_cookie_options(time() - 3600)
    );
    unset($_COOKIE[servitech_remember_cookie_name()]);
}

function servitech_remember_revoke_current(PDO $pdo): void
{
    $cookie = servitech_remember_parse_cookie();
    if ($cookie !== null) {
        $delete = $pdo->prepare("DELETE FROM remember_tokens WHERE selector = :selector");
        $delete->execute([":selector" => $cookie["selector"]]);
    }

    servitech_remember_clear_cookie();
}

function servitech_remember_revoke_all_for_user(PDO $pdo, int $userId): void
{
    if ($userId > 0) {
        $delete = $pdo->prepare("DELETE FROM remember_tokens WHERE user_id = :user_id");
        $delete->execute([":user_id" => $userId]);
    }
}

function servitech_remember_base64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), "+/", "-_"), "=");
}

function servitech_remember_issue(PDO $pdo, int $userId): void
{
    if ($userId <= 0) {
        throw new InvalidArgumentException("A valid user is required for remember-me.");
    }

    $existing = servitech_remember_parse_cookie();
    if ($existing !== null) {
        $delete = $pdo->prepare("DELETE FROM remember_tokens WHERE selector = :selector");
        $delete->execute([":selector" => $existing["selector"]]);
    }

    $selector = bin2hex(random_bytes(16));
    $validator = servitech_remember_base64url(random_bytes(32));
    $tokenHash = hash("sha256", $validator);
    $expiresAt = time() + servitech_remember_lifetime();

    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM remember_tokens WHERE expires_at <= NOW()");
        $insert = $pdo->prepare("
            INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at)
            VALUES (:user_id, :selector, :token_hash, TO_TIMESTAMP(:expires_at))
        ");
        $insert->execute([
            ":user_id" => $userId,
            ":selector" => $selector,
            ":token_hash" => $tokenHash,
            ":expires_at" => $expiresAt,
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $rawCookie = $selector . "." . $validator;
    setcookie(
        servitech_remember_cookie_name(),
        $rawCookie,
        servitech_remember_cookie_options($expiresAt)
    );
    $_COOKIE[servitech_remember_cookie_name()] = $rawCookie;
}

function servitech_remember_restore(PDO $pdo): bool
{
    $cookie = servitech_remember_parse_cookie();
    if ($cookie === null) {
        if (!empty($_COOKIE[servitech_remember_cookie_name()])) {
            servitech_remember_clear_cookie();
        }
        return false;
    }

    $select = $pdo->prepare("
        SELECT rt.user_id, rt.token_hash, EXTRACT(EPOCH FROM rt.expires_at)::bigint AS expires_at,
               u.email, COALESCE(NULLIF(LOWER(TRIM(u.role)), ''), 'customer') AS role
        FROM remember_tokens rt
        JOIN users u ON u.id = rt.user_id
        WHERE rt.selector = :selector
        LIMIT 1
    ");
    $select->execute([":selector" => $cookie["selector"]]);
    $token = $select->fetch(PDO::FETCH_ASSOC);

    $isValid = is_array($token)
        && (int)($token["expires_at"] ?? 0) > time()
        && hash_equals((string)($token["token_hash"] ?? ""), hash("sha256", $cookie["validator"]));

    if (!$isValid) {
        if (is_array($token)) {
            $delete = $pdo->prepare("DELETE FROM remember_tokens WHERE selector = :selector");
            $delete->execute([":selector" => $cookie["selector"]]);
        }
        servitech_remember_clear_cookie();
        return false;
    }

    session_regenerate_id(true);
    $role = servitech_normalize_role($token["role"] ?? "customer");
    $_SESSION["user_id"] = (int)$token["user_id"];
    $_SESSION["role"] = $role;
    $_SESSION["remember_me"] = true;
    $_SESSION["remember_selector"] = $cookie["selector"];
    if (in_array($role, ["admin", "super_admin"], true)) {
        $_SESSION["admin_logged_in"] = true;
        $_SESSION["admin_email"] = (string)($token["email"] ?? "");
    } else {
        unset($_SESSION["admin_logged_in"], $_SESSION["admin_email"]);
    }

    $touch = $pdo->prepare("UPDATE remember_tokens SET last_used_at = NOW() WHERE selector = :selector");
    $touch->execute([":selector" => $cookie["selector"]]);
    setcookie(
        servitech_remember_cookie_name(),
        $cookie["raw"],
        servitech_remember_cookie_options((int)$token["expires_at"])
    );

    return true;
}
