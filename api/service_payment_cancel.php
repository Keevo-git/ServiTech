<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/join_queue_flow.php";
require_once __DIR__ . "/upload_helpers.php";

servitech_enforce_csrf_token(false);

if (!servitech_is_customer()) {
  http_response_code(403);
  exit("Customer access required.");
}
if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
  http_response_code(405);
  exit("Method not allowed.");
}

$draft = servitech_service_payment_draft();
$token = trim((string)($_POST["draft_token"] ?? ""));
if (!is_array($draft) || !servitech_service_payment_draft_matches($token, $draft)) {
  $_SESSION["service_payment_flash_error"] = "Your payment session could not be cancelled. Please try again.";
  header("Location: " . (is_array($draft) ? servitech_service_payment_draft_url($draft) : servitech_url("/pages/customer/customer_dash.php")));
  exit();
}

if (is_array($draft)) {
  $details = is_array($draft["details"] ?? null) ? $draft["details"] : [];
  $uploadedFiles = is_array($details["uploaded_files"] ?? null) ? $details["uploaded_files"] : [];
  if ($uploadedFiles !== []) {
    try {
      servitech_upload_delete_owned_orphans($pdo, (int)($_SESSION["user_id"] ?? 0), $uploadedFiles);
    } catch (Throwable $e) {
      error_log("service payment draft upload cleanup failed: " . $e->getMessage());
    }
  }
}

unset(
  $_SESSION[SERVITECH_SERVICE_PAYMENT_DRAFT_KEY],
  $_SESSION["service_payment_flash_error"],
  $_SESSION["service_payment_confirmation"]
);
servitech_clear_join_queue_completion();
header("Location: " . servitech_url("/pages/customer/customer_dash.php"));
exit();
