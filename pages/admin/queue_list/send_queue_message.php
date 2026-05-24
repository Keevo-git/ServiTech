<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
require_once __DIR__ . "/../_includes/admin_db.php";
require_once __DIR__ . "/../../../config/csrf.php";

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

if ($subject === "") {
    $subject = "ServiTech Queue Update";
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

$email = trim((string)($queue["email"] ?? ""));
if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(["ok" => false, "error" => "Customer email is missing or invalid."], 422);
}

$queueCode = trim((string)($queue["queue_code"] ?? ""));
$customerName = trim((string)($queue["fullname"] ?? ""));
$safeName = $customerName !== "" ? $customerName : "Customer";

$body = "Good day {$safeName},\n\n"
    . "Queue Number: {$queueCode}\n\n"
    . $message
    . "\n\nServiTech: JC Repair Shop";

$fromEmail = "noreply@servitech.store";
$fromName = "ServiTech JC Repair Shop";

$headers = [
    "MIME-Version: 1.0",
    "Content-Type: text/plain; charset=UTF-8",
    "Content-Transfer-Encoding: 8bit",
    "From: {$fromName} <{$fromEmail}>",
    "Reply-To: theservitech.store@gmail.com",
    "Return-Path: {$fromEmail}",
    "X-Mailer: PHP/" . PHP_VERSION,
];

$sent = @mail($email, $subject, $body, implode("\r\n", $headers), "-f{$fromEmail}");

if (!$sent) {
    respond([
        "ok" => false,
        "error" => "The server could not send the email. Please check Hostinger mail/PHP mail settings.",
    ], 500);
}

$notificationMessage = "Queue {$queueCode}: " . $message;
$warning = "";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id BIGSERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL,
            type TEXT NOT NULL DEFAULT 'admin_message',
            reference_id INTEGER NULL,
            message TEXT NOT NULL,
            is_read BOOLEAN NOT NULL DEFAULT FALSE,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    ");

    $notificationStmt = $pdo->prepare("
        INSERT INTO notifications (user_id, type, reference_id, message, is_read, created_at)
        VALUES (:user_id, :type, :reference_id, :message, FALSE, NOW())
    ");
    $notificationStmt->execute([
        ":user_id" => (int)$queue["user_id"],
        ":type" => trim((string)($queue["category"] ?? "admin_message")),
        ":reference_id" => $queueId,
        ":message" => $notificationMessage,
    ]);
} catch (Throwable $exception) {
    error_log("queue message notification insert failed: " . $exception->getMessage());
    $warning = "Email sent, but the account notification could not be created.";
}

respond([
    "ok" => true,
    "message" => "Queue message sent to {$email} and added to the customer notifications.",
    "warning" => $warning,
]);
