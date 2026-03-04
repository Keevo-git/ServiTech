<?php
require_once __DIR__ . "/session_check.php";
require_once __DIR__ . "/db.php";

$user_id = (int)($_SESSION["user_id"] ?? 0);
if ($user_id <= 0) {
    header("Location: log_in.html");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: custo_print_order.php');
    exit();
}

$draft = $_SESSION['print_order_draft'] ?? null;
if (!$draft) {
    header('Location: custo_print_order.php');
    exit();
}

$payment_method = trim($_POST['payment_method'] ?? 'Cash');
$gcash_ref = trim($_POST['gcash_ref'] ?? '');

$queue_code = "OP-" . rand(100, 999) . "-" . substr((string)time(), -4);

// Put everything into details jsonb
$details = [
  "service_label" => "Online Print Order",
  "paper_size" => $draft["paper_size"] ?? null,
  "quantity" => max(1, (int)($draft["quantity"] ?? 1)),
  "color_option" => $draft["color_option"] ?? null,
  "notes" => $draft["notes"] ?? null,
  "file_name" => $draft["file_name"] ?? null,
  "payment_method" => $payment_method,
  "gcash_ref" => ($payment_method === "GCash" && $gcash_ref !== "") ? $gcash_ref : null,
];

foreach ($details as $k => $v) {
  if ($v === null) unset($details[$k]);
  if (is_string($v) && trim($v) === "") unset($details[$k]);
}

try {
    $stmt = $pdo->prepare("
      INSERT INTO queues (queue_code, user_id, category, details)
      VALUES (:queue_code, :user_id, :category, :details::jsonb)
    ");
    $stmt->execute([
        ':queue_code' => $queue_code,
        ':user_id' => $user_id,
        ':category' => 'printing',
        ':details' => json_encode($details, JSON_UNESCAPED_UNICODE),
    ]);

    unset($_SESSION['print_order_draft']);

    header('Location: custo_service_status.php');
    exit();

} catch (PDOException $e) {
    error_log("print_order_create error: " . $e->getMessage());
    header('Location: custo_print_order_payment.php');
    exit();
}