<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
require_once __DIR__ . "/../_includes/admin_db.php";
require_once __DIR__ . "/../../../config/csrf.php";
require_once __DIR__ . "/../../../config/mail.php";
require_once __DIR__ . "/../../../config/activity_log.php";
require_once __DIR__ . "/../../../api/queue_helpers.php";
require_once __DIR__ . "/../../../config/input_limits.php";

header("Content-Type: application/json; charset=utf-8");

servitech_enforce_same_origin(true);
servitech_enforce_csrf_token(true);

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit();
}

$queueId = (int)($_POST["queue_id"] ?? 0);
$message = trim((string)($_POST["message"] ?? ""));
$subject = trim((string)($_POST["subject"] ?? "ServiTech Queue Update"));

if ($queueId <= 0) {
    respond(["ok" => false, "error" => "Queue entry is missing."], 422);
}

if ($message === "") {
    respond(["ok" => false, "error" => "Please enter a message before sending."], 422);
}
if (servitech_text_length($message) > SERVITECH_LIMIT_MESSAGE_BODY) {
    respond(["ok" => false, "error" => "Message must not exceed " . SERVITECH_LIMIT_MESSAGE_BODY . " characters."], 422);
}

if ($subject === "") {
    $subject = "ServiTech Queue Update";
}
if (servitech_text_length($subject) > SERVITECH_LIMIT_MESSAGE_SUBJECT) {
    respond(["ok" => false, "error" => "Message subject must not exceed " . SERVITECH_LIMIT_MESSAGE_SUBJECT . " characters."], 422);
}

$stmt = $pdo->prepare("
    SELECT
        q.id,
        q.queue_code,
        q.category,
        q.user_id,
        u.fullname,
        u.email
    FROM queues q
    JOIN users u ON u.id = q.user_id
    WHERE q.id = :id
    LIMIT 1
");
$stmt->execute([":id" => $queueId]);
$queue = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$queue) {
    respond(["ok" => false, "error" => "Queue entry was not found."], 404);
}

$queueCode = trim((string)($queue["queue_code"] ?? ""));
$customerName = trim((string)($queue["fullname"] ?? ""));
$safeName = $customerName !== "" ? $customerName : "Customer";
$email = trim((string)($queue["email"] ?? ""));

$body = "Good day {$safeName},\n\n"
    . "Queue Number: {$queueCode}\n\n"
    . $message
    . "\n\nServiTech: JC Repair Shop";

$notificationMessage = "Queue {$queueCode}: " . $message;
$warning = "";

try {
    servitech_add_notification($pdo, (int)$queue["user_id"], "admin_message", $queueId, $notificationMessage);
    servitech_activity_log($pdo, [
        "action_type" => "customer_message_send",
        "module" => "queue_messages",
        "target_record_id" => $queueCode,
        "new_value" => ["subject" => $subject, "message" => $message],
        "description" => "Admin sent a customer message for Queue {$queueCode}.",
    ]);
} catch (Throwable $exception) {
    error_log("queue message notification insert failed: " . $exception->getMessage());
    respond(["ok" => false, "error" => "The customer notification could not be created."], 500);
}

$emailSent = false;
if ($email !== "" && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $fromEmail = servitech_smtp_public_from_email();
    $fromName = "ServiTech JC Repair Shop";

    if ($fromEmail !== "" && filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $headers = [
            "MIME-Version: 1.0",
            "Content-Type: text/plain; charset=UTF-8",
            "Content-Transfer-Encoding: 8bit",
            "From: {$fromName} <{$fromEmail}>",
            "Reply-To: {$fromEmail}",
            "Return-Path: {$fromEmail}",
            "X-Mailer: PHP/" . PHP_VERSION,
        ];

        $emailSent = @mail($email, $subject, $body, implode("\r\n", $headers), "-f{$fromEmail}");
        if (!$emailSent) {
            $warning = "Notification was saved, but email sending failed. Please check mail settings.";
        }
    } else {
        $warning = "Notification was saved, but email sender is not configured.";
    }
} else {
    $warning = "Notification was saved, but customer email is missing or invalid.";
}

respond([
    "ok" => true,
    "message" => $emailSent
        ? "Message sent to {$email} and added to the customer notifications."
        : "Message added to the customer notifications.",
    "warning" => $warning,
]);
