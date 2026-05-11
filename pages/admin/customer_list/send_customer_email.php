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

$email = trim((string)($_POST["email"] ?? ""));
$name = trim((string)($_POST["name"] ?? ""));
$message = trim((string)($_POST["message"] ?? ""));
$subject = trim((string)($_POST["subject"] ?? "ServiTech Service Update"));
$userId = (int)($_POST["user_id"] ?? 0);

if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(["ok" => false, "error" => "Customer email is missing or invalid."], 422);
}

if ($message === "") {
    respond(["ok" => false, "error" => "Please enter a message before sending."], 422);
}

if ($subject === "") {
    $subject = "ServiTech Service Update";
}

if ($userId > 0) {
    $userStmt = $pdo->prepare("
        SELECT id, email, fullname
        FROM users
        WHERE id = :id
        LIMIT 1
    ");
    $userStmt->execute([":id" => $userId]);
} else {
    $userStmt = $pdo->prepare("
        SELECT id, email, fullname
        FROM users
        WHERE LOWER(email) = LOWER(:email)
        LIMIT 1
    ");
    $userStmt->execute([":email" => $email]);
}

$customer = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    respond(["ok" => false, "error" => "Customer account was not found."], 404);
}

$userId = (int)($customer["id"] ?? 0);
$accountEmail = trim((string)($customer["email"] ?? ""));
if (strcasecmp($accountEmail, $email) !== 0) {
    respond(["ok" => false, "error" => "Customer email no longer matches this account."], 409);
}

$safeName = $name !== "" ? $name : "Customer";
$body = "Good day {$safeName},\n\n"
    . $message
    . "\n\nServiTech: JC Repair Shop";

$fromEmail = "noreply@servitech.store";
$fromName = "ServiTech JC Repair Shop";

$headers = [
    "MIME-Version: 1.0",
    "Content-Type: text/plain; charset=UTF-8",
    "Content-Transfer-Encoding: 8bit",
    "From: {$fromName} <{$fromEmail}>",
    "Reply-To: servitech@gmail.com",
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

$notificationMessage = "ServiTech Service Update: " . $message;
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
        VALUES (:user_id, :type, NULL, :message, FALSE, NOW())
    ");
    $notificationStmt->execute([
        ":user_id" => $userId,
        ":type" => "admin_message",
        ":message" => $notificationMessage,
    ]);
} catch (Throwable $exception) {
    error_log("customer email notification insert failed: " . $exception->getMessage());
    $warning = "Email sent, but the account notification could not be created.";
}

respond([
    "ok" => true,
    "message" => "Email sent to {$email} and added to the customer notifications.",
    "warning" => $warning,
]);
