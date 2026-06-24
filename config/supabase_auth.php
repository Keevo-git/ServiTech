<?php

function servitech_supabase_load_dotenv(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $path = dirname(__DIR__) . "/.env";
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    foreach (@file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim((string)$line);
        if ($line === "" || $line[0] === "#" || $line[0] === ";") {
            continue;
        }
        $separator = strpos($line, "=");
        if ($separator === false) {
            continue;
        }
        $key = trim(substr($line, 0, $separator));
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) || getenv($key) !== false) {
            continue;
        }
        $value = trim(substr($line, $separator + 1));
        if (
            strlen($value) >= 2
            && (($value[0] === '"' && substr($value, -1) === '"')
                || ($value[0] === "'" && substr($value, -1) === "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        @putenv($key . "=" . $value);
    }
}

function servitech_supabase_env(string $key, string $default = ""): string
{
    servitech_supabase_load_dotenv();
    $values = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
    if (function_exists("apache_getenv")) {
        $values[] = apache_getenv($key, true);
        $values[] = apache_getenv($key);
    }
    foreach ($values as $value) {
        if (is_string($value) && trim($value) !== "") {
            return trim($value);
        }
    }
    return $default;
}

function servitech_supabase_env_bool(string $key, bool $default = false): bool
{
    return in_array(
        strtolower(servitech_supabase_env($key, $default ? "1" : "0")),
        ["1", "true", "yes", "on"],
        true
    );
}

function servitech_supabase_auth_enabled(): bool
{
    return servitech_supabase_env_bool("SUPABASE_AUTH_ENABLED", false);
}

function servitech_supabase_auth_configured(): bool
{
    if (
        servitech_supabase_env("SUPABASE_URL") === ""
        || servitech_supabase_env("SUPABASE_ANON_KEY") === ""
    ) {
        return false;
    }
    return true;
}

function servitech_supabase_auth_request(
    string $path,
    string $method = "GET",
    ?array $body = null,
    string $bearer = ""
): array {
    if (!function_exists("curl_init")) {
        throw new RuntimeException("PHP cURL is required for Supabase Auth.");
    }

    $baseUrl = rtrim(servitech_supabase_env("SUPABASE_URL"), "/");
    $apiKey = servitech_supabase_env("SUPABASE_ANON_KEY");
    if ($baseUrl === "" || $apiKey === "") {
        throw new RuntimeException("Supabase Auth configuration is incomplete.");
    }

    $curl = curl_init($baseUrl . "/auth/v1/" . ltrim($path, "/"));
    if ($curl === false) {
        throw new RuntimeException("Unable to initialize Supabase Auth request.");
    }

    $headers = [
        "Accept: application/json",
        "Content-Type: application/json",
        "apikey: " . $apiKey,
        "Authorization: Bearer " . ($bearer !== "" ? $bearer : $apiKey),
    ];
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    }

    $raw = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if (!is_string($raw)) {
        throw new RuntimeException("Supabase Auth request failed: " . $curlError);
    }
    $decoded = json_decode($raw, true);
    $payload = is_array($decoded) ? $decoded : [];
    if ($status < 200 || $status >= 300) {
        $message = trim((string)(
            $payload["msg"]
            ?? $payload["message"]
            ?? $payload["error_description"]
            ?? $payload["error"]
            ?? "Supabase Auth request failed."
        ));
        throw new DomainException($message !== "" ? $message : "Supabase Auth request failed.", $status);
    }
    return $payload;
}

function servitech_supabase_sign_up(string $email, string $password, array $metadata): array
{
    return servitech_supabase_auth_request("signup", "POST", [
        "email" => $email,
        "password" => $password,
        "data" => $metadata,
    ]);
}

function servitech_supabase_sign_in(string $email, string $password): array
{
    return servitech_supabase_auth_request("token?grant_type=password", "POST", [
        "email" => $email,
        "password" => $password,
    ]);
}

function servitech_supabase_sign_in_with_google_token(string $idToken): array
{
    return servitech_supabase_auth_request("token?grant_type=id_token", "POST", [
        "provider" => "google",
        "id_token" => $idToken,
    ]);
}

function servitech_supabase_refresh(string $refreshToken): array
{
    return servitech_supabase_auth_request("token?grant_type=refresh_token", "POST", [
        "refresh_token" => $refreshToken,
    ]);
}

function servitech_supabase_update_user(string $accessToken, array $updates): array
{
    return servitech_supabase_auth_request("user", "PUT", $updates, $accessToken);
}

function servitech_supabase_get_user(string $accessToken): array
{
    return servitech_supabase_auth_request("user", "GET", null, $accessToken);
}

function servitech_supabase_mfa_enroll_totp(string $accessToken, string $friendlyName): array
{
    return servitech_supabase_auth_request("factors", "POST", [
        "factor_type" => "totp",
        "friendly_name" => $friendlyName,
    ], $accessToken);
}

function servitech_supabase_mfa_challenge(string $accessToken, string $factorId): array
{
    return servitech_supabase_auth_request(
        "factors/" . rawurlencode($factorId) . "/challenge",
        "POST",
        [],
        $accessToken
    );
}

function servitech_supabase_mfa_verify(
    string $accessToken,
    string $factorId,
    string $challengeId,
    string $code
): array {
    return servitech_supabase_auth_request(
        "factors/" . rawurlencode($factorId) . "/verify",
        "POST",
        ["challenge_id" => $challengeId, "code" => $code],
        $accessToken
    );
}

function servitech_supabase_send_recovery(string $email, string $redirectUrl): array
{
    return servitech_supabase_auth_request("recover", "POST", [
        "email" => $email,
        "redirect_to" => $redirectUrl,
    ]);
}

function servitech_supabase_logout_token(string $accessToken): void
{
    try {
        servitech_supabase_auth_request("logout", "POST", [], $accessToken);
    } catch (Throwable $exception) {
        error_log("Supabase logout warning: " . $exception->getMessage());
    }
}

function servitech_supabase_jwt_claims(string $token): array
{
    $parts = explode(".", $token);
    if (count($parts) !== 3) {
        return [];
    }
    $payload = strtr($parts[1], "-_", "+/");
    $payload .= str_repeat("=", (4 - strlen($payload) % 4) % 4);
    $decoded = base64_decode($payload, true);
    if (!is_string($decoded)) {
        return [];
    }
    $claims = json_decode($decoded, true);
    return is_array($claims) ? $claims : [];
}

function servitech_supabase_session_aal(): string
{
    $claims = is_array($_SESSION["supabase_claims"] ?? null)
        ? $_SESSION["supabase_claims"]
        : [];
    $aal = strtolower(trim((string)($claims["aal"] ?? "aal1")));
    return in_array($aal, ["aal1", "aal2"], true) ? $aal : "aal1";
}

function servitech_supabase_admin_mfa_required(): bool
{
    return servitech_supabase_env_bool("AUTH_REQUIRE_ADMIN_MFA", true);
}

function servitech_supabase_admin_mfa_enrollment_allowed(): bool
{
    return servitech_supabase_env_bool("AUTH_ALLOW_ADMIN_MFA_ENROLLMENT", false);
}

function servitech_supabase_profile_rebind_seconds(): int
{
    $configured = servitech_supabase_env("AUTH_PROFILE_REBIND_SECONDS", "300");
    return ctype_digit($configured) ? max(30, (int)$configured) : 300;
}

function servitech_supabase_store_auth_session(array $authResponse): array
{
    $accessToken = trim((string)($authResponse["access_token"] ?? ""));
    $refreshToken = trim((string)($authResponse["refresh_token"] ?? ""));
    $user = is_array($authResponse["user"] ?? null) ? $authResponse["user"] : [];
    $claims = servitech_supabase_jwt_claims($accessToken);
    $authUserId = trim((string)($user["id"] ?? $claims["sub"] ?? ""));

    if ($accessToken === "" || $refreshToken === "" || !preg_match('/^[0-9a-f-]{36}$/i', $authUserId)) {
        throw new RuntimeException(
            "Supabase did not return an active session. Confirm that email confirmation is disabled for this testing phase."
        );
    }

    $_SESSION["supabase_access_token"] = $accessToken;
    $_SESSION["supabase_refresh_token"] = $refreshToken;
    $_SESSION["supabase_expires_at"] = (int)(
        $authResponse["expires_at"]
        ?? (time() + max(60, (int)($authResponse["expires_in"] ?? 3600)))
    );
    $_SESSION["supabase_claims"] = $claims;
    $_SESSION["auth_user_id"] = strtolower($authUserId);
    return $user;
}

function servitech_supabase_bind_application_profile(PDO $pdo, string $authUserId): array
{
    $stmt = $pdo->prepare("
        SELECT id, email, COALESCE(NULLIF(LOWER(TRIM(role)), ''), 'customer') AS role
        FROM users
        WHERE auth_user_id = :auth_user_id
        LIMIT 1
    ");
    $stmt->execute([":auth_user_id" => $authUserId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($profile)) {
        throw new RuntimeException("The authenticated account is not linked to a ServiTech profile.");
    }

    $role = strtolower(trim((string)($profile["role"] ?? "customer")));
    $role = $role === "admin" ? "admin" : "customer";
    $_SESSION["user_id"] = (int)$profile["id"];
    $_SESSION["role"] = $role;
    if ($role === "admin") {
        $_SESSION["admin_logged_in"] = true;
        $_SESSION["admin_email"] = (string)($profile["email"] ?? "");
    } else {
        unset($_SESSION["admin_logged_in"], $_SESSION["admin_email"]);
    }
    return $profile;
}

function servitech_supabase_complete_login(PDO $pdo, array $authResponse): array
{
    $user = servitech_supabase_store_auth_session($authResponse);
    $authUserId = trim((string)($user["id"] ?? $_SESSION["auth_user_id"] ?? ""));
    $profile = servitech_supabase_bind_application_profile($pdo, $authUserId);
    $_SESSION["supabase_profile_bound_at"] = time();
    $_SESSION["supabase_last_activity_at"] = time();
    session_regenerate_id(true);
    return $profile;
}

function servitech_supabase_clear_application_session(): void
{
    unset(
        $_SESSION["user_id"],
        $_SESSION["role"],
        $_SESSION["admin_logged_in"],
        $_SESSION["admin_email"],
        $_SESSION["remember_me"],
        $_SESSION["remember_selector"],
        $_SESSION["supabase_profile_bound_at"],
        $_SESSION["supabase_last_activity_at"]
    );
}

function servitech_supabase_rebind_application_profile(
    PDO $pdo,
    bool $force = false,
    int $maxAgeSeconds = 300
): bool {
    if (!servitech_supabase_auth_enabled()) {
        return true;
    }

    $authUserId = strtolower(trim((string)($_SESSION["auth_user_id"] ?? "")));
    if (!preg_match('/^[0-9a-f-]{36}$/i', $authUserId)) {
        return false;
    }

    $maxAgeSeconds = max(30, $maxAgeSeconds);
    $lastBoundAt = (int)($_SESSION["supabase_profile_bound_at"] ?? 0);
    if (!$force && $lastBoundAt > 0 && $lastBoundAt >= time() - $maxAgeSeconds) {
        return true;
    }

    $previousUserId = (int)($_SESSION["user_id"] ?? 0);
    $previousRole = strtolower(trim((string)($_SESSION["role"] ?? "customer")));

    try {
        $profile = servitech_supabase_bind_application_profile($pdo, $authUserId);
        $_SESSION["supabase_profile_bound_at"] = time();
        $currentUserId = (int)($profile["id"] ?? 0);
        $currentRole = strtolower(trim((string)($profile["role"] ?? "customer")));
        if ($previousUserId !== $currentUserId || $previousRole !== $currentRole) {
            session_regenerate_id(true);
        }
        return true;
    } catch (Throwable $exception) {
        error_log("Supabase profile rebind failed: " . $exception->getMessage());
        return false;
    }
}

function servitech_supabase_clear_auth_session(): void
{
    unset(
        $_SESSION["supabase_access_token"],
        $_SESSION["supabase_refresh_token"],
        $_SESSION["supabase_expires_at"],
        $_SESSION["supabase_claims"],
        $_SESSION["auth_user_id"],
        $_SESSION["supabase_profile_bound_at"],
        $_SESSION["supabase_last_activity_at"]
    );
}

function servitech_supabase_refresh_session_if_needed(): bool
{
    if (!servitech_supabase_auth_enabled()) {
        return true;
    }

    $accessToken = trim((string)($_SESSION["supabase_access_token"] ?? ""));
    $refreshToken = trim((string)($_SESSION["supabase_refresh_token"] ?? ""));
    $expiresAt = (int)($_SESSION["supabase_expires_at"] ?? 0);
    if ($accessToken === "" || $refreshToken === "") {
        return false;
    }
    if ($expiresAt > time() + 90) {
        return true;
    }

    try {
        servitech_supabase_store_auth_session(servitech_supabase_refresh($refreshToken));
        // Refreshing proves the Auth session is current, but the application role
        // still comes from public.users and must be re-read on the next guard.
        $_SESSION["supabase_profile_bound_at"] = 0;
        return true;
    } catch (Throwable $exception) {
        error_log("Supabase session refresh failed: " . $exception->getMessage());
        servitech_supabase_clear_auth_session();
        return false;
    }
}
