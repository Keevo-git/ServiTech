<?php
require_once __DIR__ . "/../config/session_check.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/queue_helpers.php";

servitech_enforce_csrf_token(false);

function print_order_redirect(string $message = ""): void {
  if ($message !== "") {
    $_SESSION["print_order_flash_error"] = $message;
  }
  header("Location: /pages/customer/custo_print_order_payment.php");
  exit();
}

$user_id = (int)($_SESSION["user_id"] ?? 0);
if ($user_id <= 0) {
  header("Location: /auth/log_in.html");
  exit();
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
  print_order_redirect();
}

$draft = $_SESSION["print_order_draft"] ?? null;
if (!is_array($draft) || empty($draft)) {
  print_order_redirect("Your print order draft has expired. Please start again.");
}

$paper_size = trim((string)($draft["paper_size"] ?? ""));
$quantity = max(0, (int)($draft["quantity"] ?? 0));
$color_option = trim((string)($draft["color_option"] ?? ""));
$file_name = trim((string)($draft["file_name"] ?? ""));
$payment_method = strtolower(trim((string)($draft["payment_method"] ?? "")));
$reference_number = trim((string)($_POST["reference_number"] ?? ""));

$_SESSION["print_order_form"] = [
  "reference_number" => $reference_number,
];

$errors = [];
if ($paper_size === "") {
  $errors[] = "Paper size is required.";
}
if ($quantity < 1) {
  $errors[] = "Quantity must be at least 1.";
}
if ($color_option === "") {
  $errors[] = "Color selection is required.";
}
if ($file_name === "") {
  $errors[] = "An uploaded file is required.";
}
if (!in_array($payment_method, ["cash", "gcash"], true)) {
  $errors[] = "Payment method is missing or invalid.";
}
if ($payment_method === "gcash" && $reference_number === "") {
  $errors[] = "Reference number is required for GCash payments.";
}

if ($errors) {
  print_order_redirect(implode(" ", $errors));
}

$details = [
  "service_label" => trim((string)($draft["service_label"] ?? "Document Printing")),
  "order_type" => "online",
  "paper_size" => $paper_size,
  "quantity" => $quantity,
  "color_option" => $color_option,
  "notes" => trim((string)($draft["notes"] ?? "")),
  "file_name" => $file_name,
  "file_names" => isset($draft["file_names"]) && is_array($draft["file_names"]) ? $draft["file_names"] : [],
  "payment_method" => $payment_method,
  "reference_number" => $payment_method === "gcash" ? $reference_number : null,
  "total_files" => isset($draft["total_files"]) ? max(0, (int)$draft["total_files"]) : 0,
  "total_images" => isset($draft["total_images"]) ? max(0, (int)$draft["total_images"]) : 0,
  "total_pages" => isset($draft["total_pages"]) ? max(0, (int)$draft["total_pages"]) : 0,
  "price_per_page" => isset($draft["price_per_page"]) ? max(0, (float)$draft["price_per_page"]) : 0,
  "estimated_total" => isset($draft["estimated_total"]) ? max(0, (float)$draft["estimated_total"]) : 0,
  "file_analysis" => isset($draft["file_analysis"]) && is_array($draft["file_analysis"]) ? $draft["file_analysis"] : [],
  "uploaded_files" => isset($draft["uploaded_files"]) && is_array($draft["uploaded_files"]) ? $draft["uploaded_files"] : [],
];

foreach ($details as $key => $value) {
  if ($value === null) {
    unset($details[$key]);
    continue;
  }
  if (is_string($value) && trim($value) === "") {
    unset($details[$key]);
  }
}

try {
  $pdo->beginTransaction();

  $printMeta = servitech_get_print_order_queue_meta("online");
  $queue_code = servitech_generate_queue_code($pdo, $printMeta["prefix"]);
  if (!servitech_queue_code_matches_category($queue_code, $printMeta["category"])) {
    throw new RuntimeException("Queue prefix/category mapping mismatch.");
  }

  $queueStmt = $pdo->prepare("
    INSERT INTO queues (queue_code, user_id, category, details)
    VALUES (:queue_code, :user_id, :category, :details::jsonb)
    RETURNING id
  ");
  $queueStmt->execute([
    ":queue_code" => $queue_code,
    ":user_id" => $user_id,
    ":category" => $printMeta["category"],
    ":details" => json_encode($details, JSON_UNESCAPED_UNICODE),
  ]);

  $queueRow = $queueStmt->fetch();
  $queue_id = (int)($queueRow["id"] ?? 0);
  if ($queue_id <= 0) {
    throw new RuntimeException("Queue was not created.");
  }

  $paymentStmt = $pdo->prepare("
    INSERT INTO payments (queue_id, user_id, amount, payment_method, reference_number, status)
    VALUES (:queue_id, :user_id, :amount, :payment_method, :reference_number, :status)
  ");
  $paymentStmt->execute([
    ":queue_id" => $queue_id,
    ":user_id" => $user_id,
    ":amount" => isset($draft["estimated_total"]) ? max(0, (float)$draft["estimated_total"]) : 0,
    ":payment_method" => $payment_method,
    ":reference_number" => $payment_method === "gcash" ? $reference_number : null,
    ":status" => "PENDING",
  ]);

  $pdo->commit();

  $_SESSION["print_order_confirmation"] = [
    "queue_code" => $queue_code,
    "created_at" => date(DATE_ATOM),
  ];
  unset($_SESSION["print_order_draft"], $_SESSION["print_order_flash_error"], $_SESSION["print_order_form"]);

  header("Location: /pages/customer/custo_print_order_payment.php?queue=" . urlencode($queue_code));
  exit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  error_log("print_order_create error: " . $e->getMessage());
  print_order_redirect("Unable to place your print order right now. Please try again.");
}
