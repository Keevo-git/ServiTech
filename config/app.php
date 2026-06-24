<?php

date_default_timezone_set("Asia/Manila");

function servitech_base_path(): string
{
    static $basePath = null;
    if ($basePath !== null) {
        return $basePath;
    }

    $raw = getenv("APP_BASE_PATH");
    if (is_string($raw) && trim($raw) !== "") {
        $normalized = "/" . trim($raw, "/");
        $basePath = ($normalized === "/") ? "" : rtrim($normalized, "/");
        return $basePath;
    }

    $projectRoot = realpath(dirname(__DIR__));
    $documentRoot = isset($_SERVER["DOCUMENT_ROOT"]) ? realpath((string)$_SERVER["DOCUMENT_ROOT"]) : false;

    if (is_string($projectRoot) && is_string($documentRoot)) {
        $projectNorm = str_replace("\\", "/", $projectRoot);
        $docNorm = rtrim(str_replace("\\", "/", $documentRoot), "/");
        if ($docNorm !== "" && strpos($projectNorm, $docNorm) === 0) {
            $relative = substr($projectNorm, strlen($docNorm));
            $relative = "/" . trim((string)$relative, "/");
            $basePath = ($relative === "/") ? "" : rtrim($relative, "/");
            return $basePath;
        }
    }

    $basePath = "";
    return $basePath;
}

function servitech_url(string $path = "/"): string
{
    $base = servitech_base_path();
    $cleanPath = "/" . ltrim($path, "/");
    return $base . $cleanPath;
}

function servitech_favicon_link(): string
{
    $href = htmlspecialchars(servitech_url("/assets/images/favicon.png"), ENT_QUOTES, "UTF-8");
    return '<link rel="icon" type="image/png" href="' . $href . '">';
}

function servitech_cookie_path(): string
{
    $base = servitech_base_path();
    return ($base === "") ? "/" : ($base . "/");
}

function servitech_request_is_https(): bool
{
    if (!empty($_SERVER["HTTPS"]) && strtolower((string)$_SERVER["HTTPS"]) !== "off") {
        return true;
    }

    $forwardedProto = strtolower(trim(explode(",", (string)($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? ""))[0]));
    if ($forwardedProto === "https") {
        return true;
    }

    return (string)($_SERVER["SERVER_PORT"] ?? "") === "443";
}
