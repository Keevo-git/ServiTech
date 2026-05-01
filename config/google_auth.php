<?php
require_once __DIR__ . "/app.php";

function servitech_google_local_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $configPath = __DIR__ . "/google.local.php";
    if (!is_file($configPath)) {
        $config = [];
        return $config;
    }

    $loaded = require $configPath;
    $config = is_array($loaded) ? $loaded : [];
    return $config;
}

function servitech_google_client_id(): string
{
    $raw = getenv("GOOGLE_CLIENT_ID");
    if (is_string($raw) && trim($raw) !== "") {
        return trim($raw);
    }

    $localConfig = servitech_google_local_config();
    $localClientId = $localConfig["client_id"] ?? "";
    return is_string($localClientId) ? trim($localClientId) : "";
}

function servitech_google_is_enabled(): bool
{
    return servitech_google_client_id() !== "";
}

function servitech_google_base64url_decode(string $value): string
{
    $remainder = strlen($value) % 4;
    if ($remainder !== 0) {
        $value .= str_repeat("=", 4 - $remainder);
    }

    $decoded = base64_decode(strtr($value, "-_", "+/"), true);
    return ($decoded === false) ? "" : $decoded;
}

function servitech_google_http_get(string $url): array
{
    if (function_exists("curl_init")) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            "ok" => is_string($body) && $statusCode >= 200 && $statusCode < 300,
            "status" => $statusCode,
            "body" => is_string($body) ? $body : "",
        ];
    }

    $context = stream_context_create([
        "http" => [
            "method" => "GET",
            "timeout" => 10,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $statusCode = 0;
    $headers = $http_response_header ?? [];
    if (!empty($headers[0]) && preg_match("/\s(\d{3})\s/", (string)$headers[0], $matches)) {
        $statusCode = (int)$matches[1];
    }

    return [
        "ok" => is_string($body) && $statusCode >= 200 && $statusCode < 300,
        "status" => $statusCode,
        "body" => is_string($body) ? $body : "",
    ];
}

function servitech_google_fetch_certificates(): array
{
    $response = servitech_google_http_get("https://www.googleapis.com/oauth2/v1/certs");
    if (!$response["ok"]) {
        return [];
    }

    $decoded = json_decode((string)$response["body"], true);
    return is_array($decoded) ? $decoded : [];
}

function servitech_google_verify_id_token(string $credential): array
{
    $clientId = servitech_google_client_id();
    if ($clientId === "") {
        return ["ok" => false, "error" => "Google sign-in is not configured on the server."];
    }

    $parts = explode(".", $credential);
    if (count($parts) !== 3) {
        return ["ok" => false, "error" => "Invalid Google credential format."];
    }

    [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
    $header = json_decode(servitech_google_base64url_decode($encodedHeader), true);
    $payload = json_decode(servitech_google_base64url_decode($encodedPayload), true);
    $signature = servitech_google_base64url_decode($encodedSignature);

    if (!is_array($header) || !is_array($payload) || $signature === "") {
        return ["ok" => false, "error" => "Invalid Google credential payload."];
    }

    if (($header["alg"] ?? "") !== "RS256" || empty($header["kid"])) {
        return ["ok" => false, "error" => "Unsupported Google credential signature."];
    }

    $issuer = (string)($payload["iss"] ?? "");
    if (!in_array($issuer, ["accounts.google.com", "https://accounts.google.com"], true)) {
        return ["ok" => false, "error" => "Invalid Google token issuer."];
    }

    $audience = $payload["aud"] ?? "";
    $audiences = is_array($audience) ? $audience : [$audience];
    if (!in_array($clientId, $audiences, true)) {
        return ["ok" => false, "error" => "Google token audience mismatch."];
    }

    $now = time();
    $exp = (int)($payload["exp"] ?? 0);
    $iat = (int)($payload["iat"] ?? 0);
    if ($exp <= 0 || $exp < ($now - 60)) {
        return ["ok" => false, "error" => "Google token has expired."];
    }
    if ($iat > ($now + 60)) {
        return ["ok" => false, "error" => "Google token issue time is invalid."];
    }

    if (empty($payload["sub"]) || empty($payload["email"])) {
        return ["ok" => false, "error" => "Google account data is incomplete."];
    }

    if (!isset($payload["email_verified"]) || !$payload["email_verified"]) {
        return ["ok" => false, "error" => "Google email address is not verified."];
    }

    $certificates = servitech_google_fetch_certificates();
    $certificate = $certificates[(string)$header["kid"]] ?? "";
    if (!is_string($certificate) || trim($certificate) === "") {
        return ["ok" => false, "error" => "Google signing certificate could not be matched."];
    }

    $verified = openssl_verify($encodedHeader . "." . $encodedPayload, $signature, $certificate, OPENSSL_ALGO_SHA256);
    if ($verified !== 1) {
        return ["ok" => false, "error" => "Google credential signature verification failed."];
    }

    return ["ok" => true, "payload" => $payload];
}
