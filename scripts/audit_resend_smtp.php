<?php
declare(strict_types=1);

require_once __DIR__ . "/../config/mail.php";

$configCheck = servitech_check_smtp_config();
$config = is_array($configCheck["config"] ?? null) ? $configCheck["config"] : [];
$host = strtolower(trim((string)($config["host"] ?? "")));
$port = (int)($config["port"] ?? 0);
$username = trim((string)($config["username"] ?? ""));
$password = trim((string)($config["password"] ?? ""));
$fromEmail = strtolower(trim((string)($config["from_email"] ?? "")));

$checks = [
    "SMTP configuration is complete" => !empty($configCheck["ok"]),
    "Host is smtp.resend.com" => $host === "smtp.resend.com",
    "Port is a supported Resend SMTP port" => in_array($port, [465, 587, 2465, 2587], true),
    "Username is resend" => $username === "resend",
    "Password looks like a Resend API key" => str_starts_with($password, "re_") && strlen($password) >= 20,
    "From address is valid" => filter_var($fromEmail, FILTER_VALIDATE_EMAIL) !== false,
];

$failed = false;
foreach ($checks as $label => $passed) {
    echo ($passed ? "PASS" : "FAIL") . ": " . $label . PHP_EOL;
    $failed = $failed || !$passed;
}

echo "INFO: from=" . ($fromEmail !== "" ? $fromEmail : "[missing]") . PHP_EOL;
echo "INFO: credentials are redacted; this script never prints the SMTP password." . PHP_EOL;

if ($failed) {
    exit(1);
}

if (($argv[1] ?? "") !== "--send-test") {
    echo "INFO: transport not tested. Pass --send-test to send one message to Resend's delivered@resend.dev test address." . PHP_EOL;
    exit(0);
}

$result = servitech_send_smtp_mail(
    "delivered@resend.dev",
    "ServiTech SMTP delivery audit",
    "Automated SMTP delivery audit from ServiTech. No action is required."
);

if (empty($result["ok"])) {
    fwrite(STDERR, "FAIL: Resend rejected the SMTP transport test: " . (string)($result["error"] ?? "unknown error") . PHP_EOL);
    exit(1);
}

echo "PASS: Resend accepted the SMTP transport test." . PHP_EOL;
