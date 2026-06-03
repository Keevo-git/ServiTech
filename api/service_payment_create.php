<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/app.php";

servitech_enforce_csrf_token(false);

$user_id = (int)($_SESSION["user_id"] ?? 0);
if ($user_id <= 0) {
  header("Location: " . servitech_url("/auth/log_in.php"));
  exit();
}
if (!servitech_is_customer()) {
  header("Location: " . servitech_url("/pages/admin/admin_dashboard.php"));
  exit();
}

unset($_SESSION["service_payment_draft"], $_SESSION["service_payment_confirmation"], $_SESSION["service_payment_flash_error"], $_SESSION["service_payment_form"]);
header("Location: /pages/customer/custo1_printing_option.php");
exit();
