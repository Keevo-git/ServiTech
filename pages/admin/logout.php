<?php
// Admin/logout.php
require_once __DIR__ . "/../../config/session_check.php";
require_once __DIR__ . "/_includes/url.php";

$_SESSION = [];
session_destroy();

setcookie(session_name(), "", [
    "expires" => time() - 3600,
    "path" => servitech_cookie_path(),
    "secure" => (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off"),
    "httponly" => true,
    "samesite" => "Lax",
]);

header("Location: " . admin_url_raw("/auth/log_in.html?logout=1"));
exit();