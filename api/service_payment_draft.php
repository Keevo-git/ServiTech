<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

http_response_code(422);
echo json_encode([
  "ok" => false,
  "error" => "Payment options are only available for Document Print.",
], JSON_UNESCAPED_UNICODE);
exit();
