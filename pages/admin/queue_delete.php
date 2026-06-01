<?php
require_once __DIR__ . "/_includes/admin_auth.php";
require_once __DIR__ . "/../../config/csrf.php";
require_once __DIR__ . "/_includes/admin_db.php";

header("Content-Type: application/json; charset=utf-8");
servitech_enforce_csrf_token(true);

$id = (int)($_POST["id"] ?? 0);
if ($id <= 0) {
  echo json_encode(["ok" => false, "error" => "Invalid ID"]);
  exit();
}

http_response_code(405);
echo json_encode(["ok" => false, "error" => "Queue records cannot be permanently deleted. Cancel the order instead."]);
