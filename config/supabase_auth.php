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

function servitech_supabase_admin_configured(): bool
{
    return servitech_supabase_auth_configured()
        && servitech_supabase_env("SUPABASE_SERVICE_ROLE_KEY") !== "";
}

function servitech_supabase_confirmation_redirect_url(): string
{
    $baseUrl = rtrim(servitech_supabase_env("APP_PUBLIC_URL", "https://servitech.store"), "/");
    $parts = parse_url($baseUrl);
    $scheme = strtolower((string)($parts["scheme"] ?? ""));
    $host = trim((string)($parts["host"] ?? ""));

    if (!in_array($scheme, ["http", "https"], true) || $host === "") {
        throw new RuntimeException("APP_PUBLIC_URL is not a valid public URL.");
    }

    return $baseUrl . "/auth/verification_callback.php";
}

function servitech_supabase_recovery_redirect_url(): string
{
    $baseUrl = rtrim(servitech_supabase_env("APP_PUBLIC_URL", "https://servitech.store"), "/");
    $parts = parse_url($baseUrl);
    $scheme = strtolower((string)($parts["scheme"] ?? ""));
    $host = trim((string)($parts["host"] ?? ""));

    if (!in_array($scheme, ["http", "https"], true) || $host === "") {
        throw new RuntimeException("APP_PUBLIC_URL is not a valid public URL.");
    }

    return $baseUrl . "/auth/reset_password.php";
}

function servitech_supabase_error_is_email_rate_limited(string $message): bool
{
    $message = strtolower(trim($message));
    return str_contains($message, "rate limit")
        || str_contains($message, "too many requests")
        || str_contains($message, "security purposes");
}

function servitech_supabase_error_is_email_delivery_failure(string $message): bool
{
    $message = strtolower(trim($message));
    foreach ([
        "confirmation email",
        "sending confirmation",
        "send email",
        "smtp",
        "mailer",
        "email rate limit",
    ] as $marker) {
        if (str_contains($message, $marker)) {
            return true;
        }
    }
    return false;
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

function servitech_supabase_admin_auth_request(
    string $path,
    string $method = "GET",
    ?array $body = null
): array {
    if (!function_exists("curl_init")) {
        throw new RuntimeException("PHP cURL is required for Supabase Auth.");
    }

    $baseUrl = rtrim(servitech_supabase_env("SUPABASE_URL"), "/");
    $serviceRoleKey = servitech_supabase_env("SUPABASE_SERVICE_ROLE_KEY");
    if ($baseUrl === "" || $serviceRoleKey === "") {
        throw new RuntimeException("Supabase service role configuration is incomplete.");
    }

    $curl = curl_init($baseUrl . "/auth/v1/" . ltrim($path, "/"));
    if ($curl === false) {
        throw new RuntimeException("Unable to initialize Supabase Admin Auth request.");
    }

    $headers = [
        "Accept: application/json",
        "Content-Type: application/json",
        "apikey: " . $serviceRoleKey,
        "Authorization: Bearer " . $serviceRoleKey,
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
        throw new RuntimeException("Supabase Admin Auth request failed: " . $curlError);
    }
    $decoded = json_decode($raw, true);
    $payload = is_array($decoded) ? $decoded : [];
    if ($status < 200 || $status >= 300) {
        $message = trim((string)(
            $payload["msg"]
            ?? $payload["message"]
            ?? $payload["error_description"]
            ?? $payload["error"]
            ?? "Supabase Admin Auth request failed."
        ));
        throw new DomainException($message !== "" ? $message : "Supabase Admin Auth request failed.", $status);
    }
    return $payload;
}

function servitech_supabase_admin_create_user(string $email, string $password, array $metadata = []): array
{
    return servitech_supabase_admin_auth_request("admin/users", "POST", [
        "email" => $email,
        "password" => $password,
        "email_confirm" => true,
        "user_metadata" => $metadata,
    ]);
}

function servitech_supabase_admin_update_user(string $authUserId, array $updates): array
{
    $authUserId = strtolower(trim($authUserId));
    if (!preg_match('/^[0-9a-f-]{36}$/i', $authUserId)) {
        throw new DomainException("A valid Supabase Auth user ID is required.");
    }

    return servitech_supabase_admin_auth_request("admin/users/" . rawurlencode($authUserId), "PUT", $updates);
}

function servitech_supabase_sign_up(
    string $email,
    string $password,
    array $metadata,
    string $redirectUrl = ""
): array
{
    $path = "signup";
    if ($redirectUrl !== "") {
        $path .= "?redirect_to=" . rawurlencode($redirectUrl);
    }

    return servitech_supabase_auth_request($path, "POST", [
        "email" => $email,
        "password" => $password,
        "data" => $metadata,
    ]);
}

function servitech_supabase_resend_signup(string $email, string $redirectUrl = ""): array
{
    $path = "resend";
    if ($redirectUrl !== "") {
        $path .= "?redirect_to=" . rawurlencode($redirectUrl);
    }

    return servitech_supabase_auth_request($path, "POST", [
        "type" => "signup",
        "email" => $email,
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

function servitech_supabase_user_identity_providers(array $user): array
{
    $providers = [];
    $appMetadata = is_array($user["app_metadata"] ?? null) ? $user["app_metadata"] : [];
    $metadataProviders = $appMetadata["providers"] ?? [];
    if (is_string($metadataProviders)) {
        $metadataProviders = [$metadataProviders];
    }
    if (is_array($metadataProviders)) {
        foreach ($metadataProviders as $provider) {
            $provider = strtolower(trim((string)$provider));
            if ($provider !== "") {
                $providers[] = $provider;
            }
        }
    }

    $primaryProvider = strtolower(trim((string)($appMetadata["provider"] ?? "")));
    if ($primaryProvider !== "") {
        $providers[] = $primaryProvider;
    }
    foreach ((array)($user["identities"] ?? []) as $identity) {
        if (!is_array($identity)) {
            continue;
        }
        $provider = strtolower(trim((string)($identity["provider"] ?? "")));
        if ($provider !== "") {
            $providers[] = $provider;
        }
    }

    return array_values(array_unique($providers));
}

function servitech_supabase_user_has_provider(array $user, string $provider): bool
{
    return in_array(
        strtolower(trim($provider)),
        servitech_supabase_user_identity_providers($user),
        true
    );
}

function servitech_supabase_user_email_confirmed_at(array $user): string
{
    return trim((string)($user["email_confirmed_at"] ?? $user["confirmed_at"] ?? ""));
}

function servitech_supabase_user_is_usable(array $user, string $authMethod): bool
{
    if (servitech_supabase_user_email_confirmed_at($user) === "") {
        return false;
    }

    $authMethod = strtolower(trim($authMethod));
    if ($authMethod === "google") {
        return servitech_supabase_user_has_provider($user, "google");
    }

    return $authMethod === "password";
}

function servitech_supabase_error_requires_email_verification(string $message): bool
{
    $message = strtolower(trim($message));
    foreach ([
        "email not confirmed",
        "email_not_confirmed",
        "email is not confirmed",
        "confirm your email",
        "email confirmation required",
        "email verification is required",
    ] as $marker) {
        if (str_contains($message, $marker)) {
            return true;
        }
    }
    return false;
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

function servitech_supabase_verify_recovery_token_hash(string $tokenHash): array
{
    $tokenHash = trim($tokenHash);
    if ($tokenHash === "" || strlen($tokenHash) > 1024) {
        throw new DomainException("The recovery token is invalid.");
    }

    return servitech_supabase_auth_request("verify", "POST", [
        "type" => "recovery",
        "token_hash" => $tokenHash,
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
            "Supabase did not return an active session."
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
        SELECT id, fullname, email,
               COALESCE(NULLIF(LOWER(TRIM(role)), ''), 'customer') AS role,
               COALESCE(NULLIF(to_jsonb(users)->>'account_status', ''), 'active') AS account_status
        FROM users
        WHERE auth_user_id = :auth_user_id
          AND email_verified_at IS NOT NULL
        LIMIT 1
    ");
    $stmt->execute([":auth_user_id" => $authUserId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($profile)) {
        throw new RuntimeException("The authenticated account is not linked to a ServiTech profile.");
    }
    if (strtolower(trim((string)($profile["account_status"] ?? "active"))) !== "active") {
        throw new RuntimeException("This ServiTech account is deactivated.");
    }

    $role = servitech_normalize_role($profile["role"] ?? "customer");
    $_SESSION["user_id"] = (int)$profile["id"];
    $_SESSION["role"] = $role;
    if (in_array($role, ["admin", "super_admin"], true)) {
        $_SESSION["admin_logged_in"] = true;
        $_SESSION["admin_email"] = (string)($profile["email"] ?? "");
    } else {
        unset($_SESSION["admin_logged_in"], $_SESSION["admin_email"]);
    }
    return $profile;
}

function servitech_supabase_ensure_application_profile(PDO $pdo, array $authUser): void
{
    $authUserId = strtolower(trim((string)($authUser["id"] ?? "")));
    $email = strtolower(trim((string)($authUser["email"] ?? "")));
    $confirmedAt = servitech_supabase_user_email_confirmed_at($authUser);
    if (
        !preg_match('/^[0-9a-f-]{36}$/i', $authUserId)
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || $confirmedAt === ""
    ) {
        throw new DomainException("A verified Supabase identity is required to create an application profile.");
    }

    $existing = $pdo->prepare("SELECT id FROM users WHERE auth_user_id = :auth_user_id LIMIT 1");
    $existing->execute([":auth_user_id" => $authUserId]);
    if ($existing->fetchColumn()) {
        return;
    }

    $metadata = is_array($authUser["user_metadata"] ?? null) ? $authUser["user_metadata"] : [];
    $fullname = trim((string)($metadata["fullname"] ?? $metadata["full_name"] ?? $metadata["name"] ?? ""));
    if ($fullname === "") {
        $fullname = trim((string)(strstr($email, "@", true) ?: "ServiTech Customer"));
    }
    $contact = trim((string)($metadata["contact"] ?? ""));
    $consentAccepted = (string)($metadata["privacy_consent"] ?? "") === "1";
    $consentVersion = trim((string)($metadata["consent_version"] ?? ""));

    // This is a verified-login repair path for deployments where the Auth
    // profile trigger was missing or temporarily failed. It never links an
    // existing email row automatically, preserving admin/customer role safety.
    $insert = $pdo->prepare("
        INSERT INTO users (
            auth_user_id, fullname, email, contact, password_hash, role,
            email_verified_at, consent_accepted_at, consent_version,
            created_at, updated_at
        )
        SELECT
            :auth_user_id, :fullname, :email, :contact, NULL, 'customer',
            :confirmed_at,
            CASE WHEN :consent_accepted = '1' THEN NOW() ELSE NULL END,
            NULLIF(:consent_version, ''),
            NOW(), NOW()
        WHERE NOT EXISTS (
            SELECT 1 FROM users WHERE LOWER(email) = LOWER(:email)
        )
        ON CONFLICT DO NOTHING
    ");
    $insert->execute([
        ":auth_user_id" => $authUserId,
        ":fullname" => $fullname,
        ":email" => $email,
        ":contact" => $contact !== "" ? $contact : null,
        ":confirmed_at" => $confirmedAt,
        ":consent_accepted" => $consentAccepted ? "1" : "0",
        ":consent_version" => $consentVersion,
    ]);

    $existing->execute([":auth_user_id" => $authUserId]);
    if (!$existing->fetchColumn()) {
        throw new RuntimeException(
            "The verified Supabase identity could not be linked because that email already belongs to another profile."
        );
    }
}

function servitech_supabase_complete_login(
    PDO $pdo,
    array $authResponse,
    string $authMethod = "password"
): array
{
    $authUser = is_array($authResponse["user"] ?? null) ? $authResponse["user"] : [];
    if (!servitech_supabase_user_is_usable($authUser, $authMethod)) {
        throw new DomainException("Email verification is required before this account can be used.");
    }

    $authUserId = strtolower(trim((string)($authUser["id"] ?? "")));
    if (!preg_match('/^[0-9a-f-]{36}$/i', $authUserId)) {
        throw new RuntimeException("Supabase did not return a valid authenticated user.");
    }

    servitech_supabase_ensure_application_profile($pdo, $authUser);

    // The auth.users trigger normally performs this synchronization. Repeating
    // it here makes late confirmation resilient to deployment-order or trigger
    // failures while still deriving activation only from Supabase's confirmed user.
    $activateProfile = $pdo->prepare("
        UPDATE users
        SET email_verified_at = COALESCE(email_verified_at, :confirmed_at),
            updated_at = NOW()
        WHERE auth_user_id = :auth_user_id
    ");
    $activateProfile->execute([
        ":confirmed_at" => servitech_supabase_user_email_confirmed_at($authUser),
        ":auth_user_id" => $authUserId,
    ]);

    $user = servitech_supabase_store_auth_session($authResponse);
    $authUserId = trim((string)($user["id"] ?? $_SESSION["auth_user_id"] ?? ""));
    $_SESSION["supabase_auth_method"] = strtolower(trim($authMethod));
    $_SESSION["supabase_identity_verified"] = true;
    $profile = servitech_supabase_bind_application_profile($pdo, $authUserId);
    $_SESSION["supabase_profile_bound_at"] = time();
    $_SESSION["supabase_last_activity_at"] = time();
    unset($_SESSION["verification_registration_state"], $_SESSION["verification_email_hint"]);
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

    if (empty($_SESSION["supabase_identity_verified"])) {
        return false;
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
        $_SESSION["supabase_auth_method"],
        $_SESSION["supabase_identity_verified"],
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
        $refreshed = servitech_supabase_refresh($refreshToken);
        $refreshedUser = is_array($refreshed["user"] ?? null) ? $refreshed["user"] : [];
        $authMethod = strtolower(trim((string)($_SESSION["supabase_auth_method"] ?? "")));
        if ($refreshedUser && !servitech_supabase_user_is_usable($refreshedUser, $authMethod)) {
            throw new DomainException("The refreshed Supabase identity is not verified.");
        }
        servitech_supabase_store_auth_session($refreshed);
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
