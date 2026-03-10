<?php
require_once __DIR__ . "/../../../config/app.php";

if (!function_exists("admin_url_raw")) {
    function admin_url_raw(string $path): string
    {
        return servitech_url($path);
    }
}

if (!function_exists("admin_url")) {
    function admin_url(string $path): string
    {
        return htmlspecialchars(admin_url_raw($path), ENT_QUOTES, "UTF-8");
    }
}
