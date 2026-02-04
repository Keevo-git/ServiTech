<?php
// /main/includes/auth.php
// Central session + auth guard for ServiTech

// ✅ ONE consistent session name for the whole app
session_name("SERVITECHSESSID");

// ✅ Cookie must match your app folder path
// Your site is: http://localhost/ServiTech/main/...
session_set_cookie_params([
    "lifetime" => 0,
    "path"     => "/ServiTech/main/",
    "domain"   => "",       // localhost
    "secure"   => false,    // set true only if https
    "httponly" => true,
    "samesite" => "Lax"
]);

// Optional hardening (safe)
ini_set("session.use_strict_mode", "1");
ini_set("session.use_only_cookies", "1");
ini_set("session.use_cookies", "1");

// ✅ Start session ONCE
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ✅ Debug log (you can remove later)
error_log(
    "AUTH " . basename($_SERVER["SCRIPT_NAME"]) .
    " SID=" . session_id() .
    " user_id=" . ($_SESSION["user_id"] ?? "NONE") .
    " URI=" . ($_SERVER["REQUEST_URI"] ?? "")
);

// ✅ AUTH CHECK
if (!isset($_SESSION["user_id"])) {
    // If AJAX/JSON request, return JSON instead of redirect
    $isAjax = !empty($_SERVER["HTTP_X_REQUESTED_WITH"]) &&
              strtolower($_SERVER["HTTP_X_REQUESTED_WITH"]) === "xmlhttprequest";
    $acceptsJson = strpos($_SERVER["HTTP_ACCEPT"] ?? "", "application/json") !== false;
    $isJsonRequest = $isAjax || $acceptsJson ||
                     (stripos($_SERVER["CONTENT_TYPE"] ?? "", "application/json") !== false);

    if ($isJsonRequest) {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(["ok" => false, "error" => "Not logged in"]);
        exit();
    }

    header("Location: /ServiTech/main/log_in.html");
    exit();
}
