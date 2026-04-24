<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/app.php";

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), "", time() - 42000, servitech_cookie_path(), $params["domain"], $params["secure"], $params["httponly"]);
}

session_destroy();

header("Location: " . servitech_url("/auth/log_in.php?logout=1"));
exit();
