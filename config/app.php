<?php

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

function servitech_cookie_path(): string
{
    $base = servitech_base_path();
    return ($base === "") ? "/" : ($base . "/");
}
