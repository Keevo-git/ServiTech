<?php

function servitech_base_path(): string
{
    static $basePath = null;
    if ($basePath !== null) {
        return $basePath;
    }

    $raw = getenv("APP_BASE_PATH");
    if (!is_string($raw) || trim($raw) === "") {
        $raw = "/ServiTech";
    }

    $normalized = "/" . trim($raw, "/");
    if ($normalized === "/") {
        $basePath = "";
    } else {
        $basePath = rtrim($normalized, "/");
    }

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
