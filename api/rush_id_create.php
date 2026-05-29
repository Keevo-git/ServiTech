<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/app.php";
require_once __DIR__ . "/queue_helpers.php";

servitech_enforce_csrf_token(false);

function rush_id_redirect(string $message = ""): void {
  if ($message !== "") {
    $_SESSION["rush_id_flash_error"] = $message;
  }
  header("Location: /pages/customer/custo_rush_id_payment.php");
  exit();
}

$user_id = (int)($_SESSION["user_id"] ?? 0);
if ($user_id <= 0) {
  header("Location: " . servitech_url("/auth/log_in.php"));
  exit();
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
  rush_id_redirect();
}

$draft = $_SESSION["rush_id_draft"] ?? null;
if (!is_array($draft) || empty($draft)) {
  rush_id_redirect("Your Rush ID draft has expired. Please start again.");
}

$reference_number = trim((string)($_POST["reference_number"] ?? ""));
$_SESSION["rush_id_form"] = ["reference_number" => $reference_number];

if ($reference_number === "") {
  rush_id_redirect("Reference number is required for GCash payments.");
}
if (!preg_match('/^\d{13}$/', $reference_number)) {
  rush_id_redirect("GCash reference number must be exactly 13 digits.");
}

$details = [
  "service_label" => "Rush ID",
  "quantity" => max(1, (int)($draft["quantity"] ?? 1)),
  "package_label" => trim((string)($draft["package_label"] ?? "")),
  "notes" => trim((string)($draft["notes"] ?? "")),
  "file_name" => trim((string)($draft["file_name"] ?? "")),
  "file_names" => isset($draft["file_names"]) && is_array($draft["file_names"]) ? $draft["file_names"] : [],
  "payment_method" => "gcash",
  "reference_number" => $reference_number,
  "payment_status" => "Pending Verification",
  "total_files" => isset($draft["total_files"]) ? max(0, (int)$draft["total_files"]) : 0,
  "total_images" => isset($draft["total_images"]) ? max(0, (int)$draft["total_images"]) : 0,
  "total_pages" => isset($draft["total_pages"]) ? max(0, (int)$draft["total_pages"]) : 0,
  "price_per_page" => isset($draft["price_per_page"]) ? max(0, (float)$draft["price_per_page"]) : 0,
  "estimated_total" => isset($draft["estimated_total"]) ? max(0, (float)$draft["estimated_total"]) : 0,
  "file_analysis" => isset($draft["file_analysis"]) && is_array($draft["file_analysis"]) ? $draft["file_analysis"] : [],
  "uploaded_files" => isset($draft["uploaded_files"]) && is_array($draft["uploaded_files"]) ? $draft["uploaded_files"] : [],
];

foreach ($details as $key => $value) {
  if ($value === null || (is_string($value) && trim($value) === "")) {
    unset($details[$key]);
  }
}

try {
  $pdo->beginTransaction();

  $queue_code = servitech_generate_queue_code($pdo, "P");
  $queueStmt = $pdo->prepare("
    INSERT INTO queues (queue_code, user_id, category, details)
    VALUES (:queue_code, :user_id, 'printing', :details::jsonb)
    RETURNING id
  ");
  $queueStmt->execute([
    ":queue_code" => $queue_code,
    ":user_id" => $user_id,
    ":details" => json_encode($details, JSON_UNESCAPED_UNICODE),
  ]);

  $queue_id = (int)($queueStmt->fetchColumn() ?: 0);
  if ($queue_id <= 0) {
    throw new RuntimeException("Queue was not created.");
  }

  $paymentStmt = $pdo->prepare("
    INSERT INTO payments (queue_id, user_id, amount, payment_method, reference_number, status)
    VALUES (:queue_id, :user_id, :amount, 'gcash', :reference_number, 'PENDING')
  ");
  $paymentStmt->execute([
    ":queue_id" => $queue_id,
    ":user_id" => $user_id,
    ":amount" => isset($draft["estimated_total"]) ? max(0, (float)$draft["estimated_total"]) : 0,
    ":reference_number" => $reference_number,
  ]);

  servitech_add_notification($pdo, $user_id, "printing", $queue_id, "Queue {$queue_code}: GCash payment details submitted for verification.");
  servitech_notify_admins($pdo, "printing", $queue_id, "Queue {$queue_code}: New Rush ID GCash payment needs checking.");

  $pdo->commit();

  $_SESSION["rush_id_confirmation"] = [
    "queue_code" => $queue_code,
    "created_at" => date(DATE_ATOM),
  ];
  unset($_SESSION["rush_id_draft"], $_SESSION["rush_id_flash_error"], $_SESSION["rush_id_form"]);

  header("Location: /pages/customer/custo_rush_id_payment.php?queue=" . urlencode($queue_code));
  exit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  error_log("rush_id_create error: " . $e->getMessage());
  rush_id_redirect("Unable to place your Rush ID order right now. Please try again.");
}
