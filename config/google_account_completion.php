<?php

require_once __DIR__ . "/account.php";
require_once __DIR__ . "/supabase_auth.php";

const SERVITECH_GOOGLE_ACCOUNT_COMPLETION_PATH = "/pages/customer/complete_google_account.php";

function servitech_google_account_completion_path(): string
{
    return SERVITECH_GOOGLE_ACCOUNT_COMPLETION_PATH;
}

function servitech_google_account_quote_identifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
        throw new InvalidArgumentException("Invalid account column name.");
    }

    return '"' . $identifier . '"';
}

function servitech_google_account_profile_columns(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'users'
    ");
    $available = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $resolve = static function (array $candidates) use ($available): ?string {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $available, true)) {
                return $candidate;
            }
        }
        return null;
    };

    $columns = [
        "contact" => $resolve(["contact", "contacts", "phone"]),
        "password" => $resolve(["password_hash", "password"]),
        "completion" => $resolve(["local_password_set_at"]),
    ];

    if ($columns["contact"] === null || $columns["password"] === null) {
        throw new RuntimeException("The users table is missing required account fields.");
    }

    return $columns;
}

function servitech_google_account_normalize_contact(string $contact): string
{
    $digits = preg_replace('/\D+/', '', trim($contact)) ?? "";
    if (str_starts_with($digits, "63")) {
        $digits = substr($digits, 2);
    }
    if (str_starts_with($digits, "0")) {
        $digits = substr($digits, 1);
    }

    return preg_match('/^9\d{9}$/', $digits) ? "+63" . $digits : "";
}

function servitech_supabase_session_providers(): array
{
    $claims = is_array($_SESSION["supabase_claims"] ?? null) ? $_SESSION["supabase_claims"] : [];
    $metadata = is_array($claims["app_metadata"] ?? null) ? $claims["app_metadata"] : [];
    $providers = is_array($metadata["providers"] ?? null) ? $metadata["providers"] : [];
    $primaryProvider = strtolower(trim((string)($metadata["provider"] ?? "")));
    if ($primaryProvider !== "") {
        $providers[] = $primaryProvider;
    }

    return array_values(array_unique(array_filter(array_map(
        static fn($provider): string => strtolower(trim((string)$provider)),
        $providers
    ))));
}

function servitech_google_account_status_from_profile(
    array $profile,
    bool $usesSupabaseAuth,
    bool $googleSession = false,
    bool $emailPasswordIdentity = false
): array
{
    $isGoogle = trim((string)($profile["google_id"] ?? "")) !== "" || $googleSession;
    $missingContact = trim((string)($profile["contact"] ?? "")) === "";
    $missingPassword = $usesSupabaseAuth
        ? trim((string)($profile["local_password_set_at"] ?? "")) === "" && !$emailPasswordIdentity
        : trim((string)($profile["password_hash"] ?? "")) === "";

    return [
        "is_google" => $isGoogle,
        "missing_password" => $isGoogle && $missingPassword,
        "missing_contact" => $isGoogle && $missingContact,
        "required" => $isGoogle && ($missingPassword || $missingContact),
    ];
}

function servitech_google_account_status(PDO $pdo, int $userId): array
{
    $default = [
        "is_google" => false,
        "missing_password" => false,
        "missing_contact" => false,
        "required" => false,
    ];
    if ($userId <= 0) {
        return $default;
    }

    $stmt = $pdo->prepare("
        SELECT
          COALESCE(NULLIF(to_jsonb(users)->>'google_id', ''), '') AS google_id,
          COALESCE(
            NULLIF(to_jsonb(users)->>'contact', ''),
            NULLIF(to_jsonb(users)->>'contacts', ''),
            NULLIF(to_jsonb(users)->>'phone', ''),
            ''
          ) AS contact,
          COALESCE(
            NULLIF(to_jsonb(users)->>'password_hash', ''),
            NULLIF(to_jsonb(users)->>'password', ''),
            ''
          ) AS password_hash,
          COALESCE(NULLIF(to_jsonb(users)->>'local_password_set_at', ''), '') AS local_password_set_at
        FROM users
        WHERE id = :user_id
        LIMIT 1
    ");
    $stmt->execute([":user_id" => $userId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($profile)) {
        return $default;
    }

    $usesSupabaseAuth = servitech_supabase_auth_enabled();
    $sessionProviders = $usesSupabaseAuth ? servitech_supabase_session_providers() : [];
    return servitech_google_account_status_from_profile(
        $profile,
        $usesSupabaseAuth,
        in_array("google", $sessionProviders, true),
        in_array("email", $sessionProviders, true)
    );
}

function servitech_refresh_google_account_completion_state(PDO $pdo, int $userId): array
{
    $status = servitech_google_account_status($pdo, $userId);
    $_SESSION["google_account_completion_required"] = (bool)$status["required"];
    return $status;
}

function servitech_google_account_completion_required(PDO $pdo, int $userId): bool
{
    if (!array_key_exists("google_account_completion_required", $_SESSION)) {
        servitech_refresh_google_account_completion_state($pdo, $userId);
    }

    return $_SESSION["google_account_completion_required"] === true;
}

function servitech_supabase_user_has_email_identity(array $authUser): bool
{
    $providers = $authUser["app_metadata"]["providers"] ?? [];
    if (is_array($providers) && in_array("email", $providers, true)) {
        return true;
    }

    foreach (($authUser["identities"] ?? []) as $identity) {
        if (is_array($identity) && strtolower(trim((string)($identity["provider"] ?? ""))) === "email") {
            return true;
        }
    }

    return false;
}
