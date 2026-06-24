<?php
declare(strict_types=1);

require_once __DIR__ . "/../config/supabase_auth.php";

function servitech_auth_admin_request(string $path, string $method, string $serviceRoleKey): array
{
    $baseUrl = rtrim(servitech_supabase_env("SUPABASE_URL"), "/");
    $curl = curl_init($baseUrl . "/auth/v1/" . ltrim($path, "/"));
    if ($curl === false) {
        return ["status" => 0, "body" => []];
    }
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            "Accept: application/json",
            "apikey: " . $serviceRoleKey,
            "Authorization: Bearer " . $serviceRoleKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return ["status" => $status, "body" => is_array($decoded) ? $decoded : []];
}

$mode = (string)($argv[1] ?? "");
if (!in_array($mode, ["--run-live-test", "--cleanup-only"], true)) {
    echo "This test creates one temporary Supabase Auth user, triggers a confirmation email, and deletes the user afterward." . PHP_EOL;
    echo "Run with --run-live-test when you are ready to use one Auth email-rate-limit slot." . PHP_EOL;
    echo "Run with --cleanup-only to remove only users created with this script's servitech-auth-audit prefix." . PHP_EOL;
    exit(0);
}

if (!servitech_supabase_auth_configured()) {
    fwrite(STDERR, "FAIL: Supabase Auth is not configured." . PHP_EOL);
    exit(1);
}

$serviceRoleKey = servitech_supabase_env("SUPABASE_SERVICE_ROLE_KEY");
if ($serviceRoleKey === "") {
    fwrite(STDERR, "FAIL: SUPABASE_SERVICE_ROLE_KEY is required so temporary audit users can be removed." . PHP_EOL);
    exit(1);
}

if ($mode === "--cleanup-only") {
    $list = servitech_auth_admin_request("admin/users?page=1&per_page=1000", "GET", $serviceRoleKey);
    if ((int)$list["status"] !== 200) {
        fwrite(STDERR, "FAIL: could not list temporary audit users (HTTP " . (int)$list["status"] . ")." . PHP_EOL);
        exit(1);
    }
    $removed = 0;
    foreach ((array)($list["body"]["users"] ?? []) as $user) {
        $candidateEmail = strtolower(trim((string)($user["email"] ?? "")));
        $candidateId = strtolower(trim((string)($user["id"] ?? "")));
        if (!str_starts_with($candidateEmail, "servitech-auth-audit-") || !str_ends_with($candidateEmail, "@servitech.store")) {
            continue;
        }
        $deleted = servitech_auth_admin_request("admin/users/" . rawurlencode($candidateId), "DELETE", $serviceRoleKey);
        if (in_array((int)$deleted["status"], [200, 204], true)) {
            $removed++;
        }
    }
    echo "PASS: temporary audit users removed: " . $removed . PHP_EOL;
    exit(0);
}

$email = "servitech-auth-audit-" . gmdate("YmdHis") . "-" . bin2hex(random_bytes(3)) . "@servitech.store";
$password = "Audit-" . bin2hex(random_bytes(12)) . "!9a";
$userId = "";
$exitCode = 1;

try {
    echo "INFO: temporary recipient=" . $email . PHP_EOL;
    $response = servitech_supabase_sign_up(
        $email,
        $password,
        ["audit" => "verification-delivery"],
        servitech_supabase_confirmation_redirect_url()
    );
    $user = is_array($response["user"] ?? null) ? $response["user"] : [];
    $userId = strtolower(trim((string)($user["id"] ?? "")));
    $hasSession = trim((string)($response["access_token"] ?? "")) !== "";

    if (!preg_match('/^[0-9a-f-]{36}$/i', $userId)) {
        throw new RuntimeException("Supabase did not return the temporary Auth user ID.");
    }
    if ($hasSession) {
        throw new RuntimeException("Supabase returned a session, which means email confirmation is not being enforced.");
    }

    echo "PASS: Supabase created a pending Auth user and accepted the confirmation email request." . PHP_EOL;
    echo "INFO: recipient=" . $email . PHP_EOL;
    echo "INFO: inspect Supabase Auth logs and Resend Logs for the matching message before treating inbox delivery as verified." . PHP_EOL;
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "FAIL: Supabase confirmation delivery was rejected: " . $exception->getMessage() . PHP_EOL);
} finally {
    if (preg_match('/^[0-9a-f-]{36}$/i', $userId)) {
        $deleted = servitech_auth_admin_request("admin/users/" . rawurlencode($userId), "DELETE", $serviceRoleKey);
        echo in_array((int)$deleted["status"], [200, 204], true)
            ? "PASS: temporary Auth user removed." . PHP_EOL
            : "WARN: temporary Auth user cleanup returned HTTP " . (int)$deleted["status"] . "." . PHP_EOL;
    }
}

exit($exitCode);
