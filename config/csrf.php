<?php
require_once __DIR__ . "/app.php";

function servitech_csrf_is_safe_method(string $method): bool
{
    return in_array(strtoupper($method), ["GET", "HEAD", "OPTIONS"], true);
}

function servitech_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return "";
    }

    if (empty($_SESSION["csrf_token"]) || !is_string($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    $token = $_SESSION["csrf_token"];
    $secure = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off");

    setcookie("SERVITECH_CSRF", $token, [
        "expires" => 0,
        "path" => servitech_cookie_path(),
        "secure" => $secure,
        "httponly" => false,
        "samesite" => "Lax",
    ]);

    return $token;
}

function servitech_csrf_fail(bool $json, string $message = "Forbidden"): void
{
    http_response_code(403);
    if ($json) {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(["ok" => false, "error" => $message]);
    } else {
        echo $message;
    }
    exit();
}

function servitech_enforce_same_origin(bool $json = false): void
{
    $method = $_SERVER["REQUEST_METHOD"] ?? "GET";
    if (servitech_csrf_is_safe_method($method)) {
        return;
    }

    $host = strtolower((string)($_SERVER["HTTP_HOST"] ?? ""));
    if ($host === "") {
        return;
    }

    $origin = (string)($_SERVER["HTTP_ORIGIN"] ?? "");
    if ($origin !== "") {
        $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
        $originPort = parse_url($origin, PHP_URL_PORT);
        $hostPort = parse_url(((!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https://" : "http://") . $host, PHP_URL_PORT);
        $sameHost = ($originHost === strtolower((string)parse_url(((!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https://" : "http://") . $host, PHP_URL_HOST)));
        $samePort = ($originPort === null || $hostPort === null || (int)$originPort === (int)$hostPort);
        if (!$sameHost || !$samePort) {
            servitech_csrf_fail($json);
        }
        return;
    }

    $referer = (string)($_SERVER["HTTP_REFERER"] ?? "");
    if ($referer !== "") {
        $refererHost = strtolower((string)parse_url($referer, PHP_URL_HOST));
        if ($refererHost !== strtolower((string)parse_url(((!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https://" : "http://") . $host, PHP_URL_HOST))) {
            servitech_csrf_fail($json);
        }
        return;
    }

    servitech_csrf_fail($json);
}

function servitech_enforce_csrf_token(bool $json = false): void
{
    $method = $_SERVER["REQUEST_METHOD"] ?? "GET";
    if (servitech_csrf_is_safe_method($method)) {
        return;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        servitech_csrf_fail($json);
    }

    $sessionToken = (string)($_SESSION["csrf_token"] ?? "");
    $requestToken = (string)($_POST["csrf_token"] ?? ($_SERVER["HTTP_X_CSRF_TOKEN"] ?? ""));

    if ($sessionToken === "" || $requestToken === "" || !hash_equals($sessionToken, $requestToken)) {
        servitech_csrf_fail($json);
    }
}
