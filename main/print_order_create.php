<?php
require_once __DIR__ . "/includes/auth.php";
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

$notes = trim((string)($draft['notes'] ?? ''));
$payNote = "Payment: " . $payment_method;
if ($payment_method === 'GCash' && $gcash_ref !== '') {
    $payNote .= " | Ref: " . $gcash_ref;
}
$notes = trim($notes . "\n" . $payNote);

$queue_code = "OP-" . rand(100, 999) . "-" . substr(time(), -4);

$sql = "
INSERT INTO queues
(queue_code, user_id, category, service_label, paper_size, quantity,
 color_option, package_label, lamination_type, device_type, notes, file_name)
VALUES
(:queue_code, :user_id, :category, :service_label, :paper_size, :quantity,
 :color_option, :package_label, :lamination_type, :device_type, :notes, :file_name)
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':queue_code' => $queue_code,
        ':user_id' => $user_id,
        ':category' => 'printing',
        ':service_label' => 'Online Print Order',
        ':paper_size' => $draft['paper_size'] ?? null,
        ':quantity' => max(1, (int)($draft['quantity'] ?? 1)),
        ':color_option' => $draft['color_option'] ?? null,
        ':package_label' => null,
        ':lamination_type' => null,
        ':device_type' => null,
        ':notes' => $notes,
        ':file_name' => $draft['file_name'] ?? null,
    ]);

    unset($_SESSION['print_order_draft']);

    // shows in Service Status and Dashboard immediately
    header('Location: custo_service_status.php');
    exit();

} catch (PDOException $e) {
    error_log("print_order_create error: " . $e->getMessage());
    header('Location: custo_print_order_payment.php');
    exit();
}
