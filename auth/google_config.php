<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/google_auth.php";

header("Content-Type: application/json; charset=utf-8");

echo json_encode([
    "googleEnabled" => servitech_google_is_enabled(),
    "googleClientId" => servitech_google_client_id(),
]);
exit();
