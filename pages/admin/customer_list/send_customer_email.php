<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
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

if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(["ok" => false, "error" => "Customer email is missing or invalid."], 422);
}

if ($message === "") {
    respond(["ok" => false, "error" => "Please enter a message before sending."], 422);
}

if ($subject === "") {
    $subject = "ServiTech Service Update";
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

respond(["ok" => true, "message" => "Email sent to {$email}."]);
