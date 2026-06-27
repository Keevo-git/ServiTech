<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
require_once __DIR__ . "/../_includes/admin_db.php";
require_once __DIR__ . "/../../../config/csrf.php";
require_once __DIR__ . "/../../../config/activity_log.php";
require_once __DIR__ . "/../../../api/queue_helpers.php";
require_once __DIR__ . "/_auth_backed_customer_scope.php";
require_once __DIR__ . "/../../../config/input_limits.php";

header("Content-Type: application/json; charset=utf-8");

servitech_enforce_same_origin(true);
servitech_enforce_csrf_token(true);

function customer_message_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit();
}

$customerId = (int)($_POST["customer_id"] ?? 0);
$message = trim((string)($_POST["message"] ?? ""));

if ($customerId <= 0) {
    customer_message_respond(["ok" => false, "error" => "Customer is missing."], 422);
}

if ($message === "") {
    customer_message_respond(["ok" => false, "error" => "Please enter a message before sending."], 422);
}
if (servitech_text_length($message) > SERVITECH_LIMIT_MESSAGE_BODY) {
    customer_message_respond(["ok" => false, "error" => "Message must not exceed " . SERVITECH_LIMIT_MESSAGE_BODY . " characters."], 422);
}

$customerPdo = admin_auth_backed_customer_connection();
$customerScopeSql = admin_auth_backed_customer_scope_sql();
$stmt = $customerPdo->prepare("
    SELECT users.id, users.fullname, users.email
    {$customerScopeSql}
      AND users.id = :id
    LIMIT 1
");
$stmt->execute([":id" => $customerId]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    customer_message_respond(["ok" => false, "error" => "Customer was not found."], 404);
}

$customerName = trim((string)($customer["fullname"] ?? ""));
$prefix = $customerName !== "" ? "Hi {$customerName}, " : "";
$notificationMessage = $prefix . $message;
$eventKey = "customer_message:{$customerId}:" . str_replace(" ", "", (string)microtime());

try {
    servitech_add_notification($pdo, $customerId, "admin_message", $customerId, $notificationMessage, $eventKey);
    servitech_activity_log($pdo, [
        "action_type" => "customer_message_send",
        "module" => "customer_messages",
        "target_record_id" => (string)$customerId,
        "new_value" => ["message" => $message],
        "description" => "Admin sent a customer message to " . ($customerName !== "" ? $customerName : "customer #{$customerId}") . ".",
    ]);
} catch (Throwable $exception) {
    error_log("customer message notification insert failed: " . $exception->getMessage());
    customer_message_respond(["ok" => false, "error" => "The customer notification could not be created."], 500);
}

customer_message_respond([
    "ok" => true,
    "message" => "Message added to the customer notifications.",
]);
