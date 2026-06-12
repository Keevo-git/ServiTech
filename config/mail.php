<?php

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

function servitech_mail_error_log_path(): string
{
    return __DIR__ . "/../logs/mail_error.log";
}

function servitech_forgot_password_mail_log_path(): string
{
    return __DIR__ . "/../logs/forgot_password_mail.log";
}

function servitech_write_log_file(string $path, string $message): void
{
    $directory = dirname($path);
    if (!is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }

    $line = "[" . date("Y-m-d H:i:s") . "] " . $message . PHP_EOL;
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

function servitech_mail_log(string $message): void
{
    servitech_write_log_file(servitech_mail_error_log_path(), $message);
    error_log($message);
}

function servitech_forgot_password_mail_log(string $message): void
{
    servitech_write_log_file(servitech_forgot_password_mail_log_path(), $message);
    error_log($message);
}

function servitech_mail_raw_env_value(string $key): string
{
    $candidates = [
        getenv($key),
        $_ENV[$key] ?? null,
        $_SERVER[$key] ?? null,
    ];

    if (function_exists("apache_getenv")) {
        $candidates[] = apache_getenv($key, true);
        $candidates[] = apache_getenv($key);
    }

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== "") {
            return trim($candidate);
        }
    }

    return "";
}

function servitech_mail_dotenv_paths(): array
{
    return [
        dirname(__DIR__) . "/.env",
        __DIR__ . "/.env",
    ];
}

function servitech_mail_parse_dotenv_value(string $value): string
{
    $value = trim($value);
    if ($value === "") {
        return "";
    }

    $quote = $value[0];
    if (($quote === '"' || $quote === "'") && substr($value, -1) === $quote) {
        $value = substr($value, 1, -1);
        return $quote === '"' ? stripcslashes($value) : $value;
    }

    return trim((string)preg_replace('/\s+#.*$/', '', $value));
}

function servitech_mail_load_dotenv(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $GLOBALS["servitech_mail_dotenv_files"] = [];
    $GLOBALS["servitech_mail_dotenv_keys"] = [];

    foreach (servitech_mail_dotenv_paths() as $path) {
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            continue;
        }

        $GLOBALS["servitech_mail_dotenv_files"][] = $path;

        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === "" || $line[0] === "#" || $line[0] === ";") {
                continue;
            }

            if (strpos($line, "export ") === 0) {
                $line = trim(substr($line, 7));
            }

            $separator = strpos($line, "=");
            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }

            if (servitech_mail_raw_env_value($key) !== "") {
                continue;
            }

            $value = servitech_mail_parse_dotenv_value(substr($line, $separator + 1));
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            @putenv($key . "=" . $value);
            $GLOBALS["servitech_mail_dotenv_keys"][$key] = $path;
        }
    }
}

function servitech_mail_dotenv_files(): array
{
    servitech_mail_load_dotenv();
    $files = $GLOBALS["servitech_mail_dotenv_files"] ?? [];
    return is_array($files) ? $files : [];
}

function servitech_mail_env_value(string $key): string
{
    servitech_mail_load_dotenv();
    return servitech_mail_raw_env_value($key);
}

function servitech_mail_local_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $configPath = __DIR__ . "/mail.local.php";
    if (!is_file($configPath)) {
        $config = [];
        return $config;
    }

    $loaded = require $configPath;
    $config = is_array($loaded) ? $loaded : [];
    return $config;
}

function servitech_mail_config_value(string $envKey, string $localKey, string $default = ""): string
{
    $envValue = servitech_mail_env_value($envKey);
    if ($envValue !== "") {
        return $envValue;
    }

    $localConfig = servitech_mail_local_config();
    $localValue = $localConfig[$localKey] ?? "";
    if (is_string($localValue) || is_numeric($localValue)) {
        $localValue = trim((string)$localValue);
        if ($localValue !== "") {
            return $localValue;
        }
    }

    return $default;
}

function servitech_mail_config_value_any(array $envKeys, array $localKeys, string $default = ""): string
{
    foreach ($envKeys as $envKey) {
        $envValue = servitech_mail_env_value((string)$envKey);
        if ($envValue !== "") {
            return $envValue;
        }
    }

    $localConfig = servitech_mail_local_config();
    foreach ($localKeys as $localKey) {
        $localValue = $localConfig[(string)$localKey] ?? "";
        if (is_string($localValue) || is_numeric($localValue)) {
            $localValue = trim((string)$localValue);
            if ($localValue !== "") {
                return $localValue;
            }
        }
    }

    return $default;
}

function servitech_mail_config_source(string $envKey, string $localKey, string $default = ""): string
{
    servitech_mail_load_dotenv();

    $dotenvKeys = $GLOBALS["servitech_mail_dotenv_keys"] ?? [];
    if (is_array($dotenvKeys) && isset($dotenvKeys[$envKey])) {
        return ".env";
    }

    if (servitech_mail_raw_env_value($envKey) !== "") {
        return "environment";
    }

    $localConfig = servitech_mail_local_config();
    $localValue = $localConfig[$localKey] ?? "";
    if ((is_string($localValue) || is_numeric($localValue)) && trim((string)$localValue) !== "") {
        return "config/mail.local.php";
    }

    return $default !== "" ? "default" : "missing";
}

function servitech_mail_config_source_any(array $envKeys, array $localKeys, string $default = ""): string
{
    servitech_mail_load_dotenv();

    $dotenvKeys = $GLOBALS["servitech_mail_dotenv_keys"] ?? [];
    foreach ($envKeys as $envKey) {
        $envKey = (string)$envKey;
        if (is_array($dotenvKeys) && isset($dotenvKeys[$envKey])) {
            return ".env";
        }

        if (servitech_mail_raw_env_value($envKey) !== "") {
            return "environment";
        }
    }

    $localConfig = servitech_mail_local_config();
    foreach ($localKeys as $localKey) {
        $localValue = $localConfig[(string)$localKey] ?? "";
        if ((is_string($localValue) || is_numeric($localValue)) && trim((string)$localValue) !== "") {
            return "config/mail.local.php";
        }
    }

    return $default !== "" ? "default" : "missing";
}

function servitech_smtp_config(): array
{
    $username = servitech_mail_config_value("SMTP_USERNAME", "username");
    $fromEmail = servitech_mail_config_value("SMTP_FROM_EMAIL", "from_email", $username);
    $replyTo = servitech_mail_config_value("SMTP_REPLY_TO", "reply_to", $fromEmail !== "" ? $fromEmail : $username);

    return [
        "host" => servitech_mail_config_value("SMTP_HOST", "host", "smtp.gmail.com"),
        "port" => servitech_mail_config_value("SMTP_PORT", "port", "587"),
        "username" => $username,
        "password" => servitech_mail_config_value("SMTP_PASSWORD", "password"),
        "encryption" => strtolower(servitech_mail_config_value_any(["SMTP_SECURE", "SMTP_ENCRYPTION"], ["secure", "encryption"], "tls")),
        "from_email" => $fromEmail,
        "from_name" => servitech_mail_config_value("SMTP_FROM_NAME", "from_name", "ServiTech"),
        "reply_to" => $replyTo,
    ];
}

function servitech_smtp_public_from_email(): string
{
    $config = servitech_smtp_config();
    $fromEmail = trim((string)$config["from_email"]);
    if ($fromEmail !== "") {
        return $fromEmail;
    }

    return trim((string)$config["username"]);
}

function servitech_smtp_config_status(): array
{
    return [
        "SMTP_HOST" => [
            "present" => servitech_mail_config_value("SMTP_HOST", "host", "smtp.gmail.com") !== "",
            "source" => servitech_mail_config_source("SMTP_HOST", "host", "smtp.gmail.com"),
        ],
        "SMTP_PORT" => [
            "present" => servitech_mail_config_value("SMTP_PORT", "port", "587") !== "",
            "source" => servitech_mail_config_source("SMTP_PORT", "port", "587"),
        ],
        "SMTP_USERNAME" => [
            "present" => servitech_mail_config_value("SMTP_USERNAME", "username") !== "",
            "source" => servitech_mail_config_source("SMTP_USERNAME", "username"),
        ],
        "SMTP_PASSWORD" => [
            "present" => servitech_mail_config_value("SMTP_PASSWORD", "password") !== "",
            "source" => servitech_mail_config_source("SMTP_PASSWORD", "password"),
        ],
        "SMTP_SECURE" => [
            "present" => servitech_mail_config_value_any(["SMTP_SECURE", "SMTP_ENCRYPTION"], ["secure", "encryption"], "tls") !== "",
            "source" => servitech_mail_config_source_any(["SMTP_SECURE", "SMTP_ENCRYPTION"], ["secure", "encryption"], "tls"),
        ],
        "SMTP_FROM_EMAIL" => [
            "present" => servitech_smtp_public_from_email() !== "",
            "source" => servitech_mail_config_source("SMTP_FROM_EMAIL", "from_email", servitech_mail_config_value("SMTP_USERNAME", "username")),
        ],
    ];
}

function servitech_check_smtp_config(): array
{
    $config = servitech_smtp_config();
    $missing = [];

    foreach ([
        "SMTP_HOST" => "host",
        "SMTP_PORT" => "port",
        "SMTP_USERNAME" => "username",
        "SMTP_PASSWORD" => "password",
        "SMTP_FROM_EMAIL" => "from_email",
    ] as $envKey => $configKey) {
        if (trim((string)$config[$configKey]) === "") {
            $missing[] = $envKey;
        }
    }

    $port = (int)$config["port"];
    if ($port <= 0 || $port > 65535) {
        $missing[] = "SMTP_PORT";
    }

    if ((string)$config["from_email"] !== "" && !filter_var((string)$config["from_email"], FILTER_VALIDATE_EMAIL)) {
        $missing[] = "SMTP_FROM_EMAIL";
    }

    if ((string)$config["username"] !== "" && preg_match('/[\r\n]/', (string)$config["username"])) {
        $missing[] = "SMTP_USERNAME";
    }

    if (!in_array((string)$config["encryption"], ["tls", "ssl"], true)) {
        $missing[] = "SMTP_SECURE";
    }

    $missing = array_values(array_unique($missing));

    return [
        "ok" => count($missing) === 0,
        "missing" => $missing,
        "config" => $config,
        "status" => servitech_smtp_config_status(),
    ];
}

function servitech_log_smtp_config_status(array $check): void
{
    $parts = [];
    foreach (($check["status"] ?? []) as $key => $status) {
        $parts[] = $key . "=" . (!empty($status["present"]) ? "present" : "missing") . " source=" . (string)($status["source"] ?? "unknown");
    }

    servitech_forgot_password_mail_log("SMTP configuration check: " . implode("; ", $parts));
}

function servitech_mail_debug_enabled(): bool
{
    $value = strtolower(servitech_mail_config_value("SMTP_DEBUG", "debug", servitech_mail_env_value("APP_DEBUG")));
    return in_array($value, ["1", "true", "yes", "on"], true);
}

function servitech_smtp_read_response($socket): array
{
    $lines = [];
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }

        $lines[] = rtrim($line, "\r\n");
        if (strlen($line) >= 4 && $line[3] === " ") {
            break;
        }
    }

    $lastLine = end($lines);
    $code = is_string($lastLine) ? (int)substr($lastLine, 0, 3) : 0;
    return ["code" => $code, "message" => implode("\n", $lines)];
}

function servitech_smtp_command($socket, string $command, array $expectedCodes): array
{
    fwrite($socket, $command . "\r\n");
    $response = servitech_smtp_read_response($socket);
    if (!in_array((int)$response["code"], $expectedCodes, true)) {
        return [
            "ok" => false,
            "error" => "SMTP command failed: " . preg_replace('/AUTH\s+\S+/i', 'AUTH [hidden]', $command) . " | " . $response["message"],
        ];
    }

    return ["ok" => true, "response" => $response];
}

function servitech_mail_dot_stuff(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $lines = explode("\n", $body);
    foreach ($lines as &$line) {
        if (isset($line[0]) && $line[0] === ".") {
            $line = "." . $line;
        }
    }
    unset($line);

    return implode("\r\n", $lines);
}

function servitech_mail_format_address(string $email, string $name = ""): string
{
    $email = trim($email);
    $name = trim($name);
    if ($name === "") {
        return "<" . $email . ">";
    }

    $encodedName = "=?UTF-8?B?" . base64_encode($name) . "?=";
    return $encodedName . " <" . $email . ">";
}

function servitech_send_smtp_mail(string $toEmail, string $subject, string $textBody, string $htmlBody = ""): array
{
    $check = servitech_check_smtp_config();
    $config = $check["config"];

    $host = (string)$config["host"];
    $port = (int)$config["port"];
    $username = (string)$config["username"];
    $password = (string)$config["password"];
    $encryption = (string)$config["encryption"];
    $fromEmail = (string)$config["from_email"];
    $fromName = (string)$config["from_name"];
    $replyTo = (string)$config["reply_to"];

    servitech_forgot_password_mail_log("SMTP config: host={$host}; port={$port}; encryption={$encryption}; username={$username}; from={$fromEmail}; reply_to={$replyTo}");
    servitech_log_smtp_config_status($check);

    if (!$check["ok"]) {
        servitech_forgot_password_mail_log("SMTP configuration failed: missing/invalid " . implode(", ", $check["missing"]));
        return ["ok" => false, "error" => "SMTP configuration is incomplete: " . implode(", ", $check["missing"])];
    }

    $phpMailerResult = servitech_send_phpmailer_mail(
        $toEmail,
        $subject,
        $textBody,
        $htmlBody,
        [
            "host" => $host,
            "port" => $port,
            "username" => $username,
            "password" => $password,
            "encryption" => $encryption,
            "from_email" => $fromEmail,
            "from_name" => $fromName,
            "reply_to" => $replyTo,
        ]
    );
    if ($phpMailerResult["available"]) {
        servitech_forgot_password_mail_log("PHPMailer send result for {$toEmail}: " . ($phpMailerResult["ok"] ? "success" : "failed: " . $phpMailerResult["error"]));
        return ["ok" => $phpMailerResult["ok"], "error" => $phpMailerResult["error"]];
    }

    servitech_forgot_password_mail_log("PHPMailer is not installed or autoloadable; falling back to direct SMTP socket sender.");

    $transportHost = ($encryption === "ssl" ? "ssl://" : "") . $host;
    $errno = 0;
    $errstr = "";
    servitech_forgot_password_mail_log("SMTP connection attempt: {$transportHost}:{$port}");
    $socket = @stream_socket_client($transportHost . ":" . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        servitech_forgot_password_mail_log("SMTP connection failed: errno={$errno}; error=" . ($errstr !== "" ? $errstr : "unknown error"));
        return ["ok" => false, "error" => "SMTP connection failed: " . ($errstr !== "" ? $errstr : "unknown error")];
    }

    servitech_forgot_password_mail_log("SMTP connection opened.");

    stream_set_timeout($socket, 20);
    $response = servitech_smtp_read_response($socket);
    if ((int)$response["code"] !== 220) {
        fclose($socket);
        servitech_forgot_password_mail_log("SMTP greeting failed: " . $response["message"]);
        return ["ok" => false, "error" => "SMTP greeting failed: " . $response["message"]];
    }

    servitech_forgot_password_mail_log("SMTP greeting ok: " . $response["message"]);

    $serverName = $_SERVER["SERVER_NAME"] ?? "servitech.store";
    $result = servitech_smtp_command($socket, "EHLO " . $serverName, [250]);
    if (!$result["ok"]) {
        fclose($socket);
        return $result;
    }

    if ($encryption === "tls") {
        $result = servitech_smtp_command($socket, "STARTTLS", [220]);
        if (!$result["ok"]) {
            fclose($socket);
            return $result;
        }

        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            servitech_forgot_password_mail_log("SMTP TLS negotiation failed.");
            return ["ok" => false, "error" => "SMTP TLS negotiation failed."];
        }

        servitech_forgot_password_mail_log("SMTP STARTTLS negotiation ok.");

        $result = servitech_smtp_command($socket, "EHLO " . $serverName, [250]);
        if (!$result["ok"]) {
            fclose($socket);
            return $result;
        }
    }

    if ($username !== "" || $password !== "") {
        $result = servitech_smtp_command($socket, "AUTH LOGIN", [334]);
        if (!$result["ok"]) {
            fclose($socket);
            return $result;
        }

        $result = servitech_smtp_command($socket, base64_encode($username), [334]);
        if (!$result["ok"]) {
            fclose($socket);
            return $result;
        }

        $result = servitech_smtp_command($socket, base64_encode($password), [235]);
        if (!$result["ok"]) {
            fclose($socket);
            servitech_forgot_password_mail_log("SMTP authentication failed for {$username}. Gmail requires 2-Step Verification and an App Password, not the normal Gmail password.");
            return ["ok" => false, "error" => "SMTP authentication failed."];
        }

        servitech_forgot_password_mail_log("SMTP authentication ok for {$username}.");
    }

    $encodedSubject = "=?UTF-8?B?" . base64_encode($subject) . "?=";
    $hasHtmlBody = trim($htmlBody) !== "";
    $boundary = "servitech_" . bin2hex(random_bytes(12));
    $headers = [
        "From: " . servitech_mail_format_address($fromEmail, $fromName),
        "To: " . servitech_mail_format_address($toEmail),
        "Reply-To: " . servitech_mail_format_address($replyTo),
        "Subject: " . $encodedSubject,
        "MIME-Version: 1.0",
        "Message-ID: <" . bin2hex(random_bytes(16)) . "@servitech.store>",
        "Date: " . date(DATE_RFC2822),
        "X-Mailer: ServiTech SMTP",
    ];

    if ($hasHtmlBody) {
        $headers[] = "Content-Type: multipart/alternative; boundary=\"" . $boundary . "\"";
        $messageBody = "--" . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $textBody . "\r\n\r\n"
            . "--" . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $htmlBody . "\r\n\r\n"
            . "--" . $boundary . "--";
    } else {
        $headers[] = "Content-Type: text/plain; charset=UTF-8";
        $headers[] = "Content-Transfer-Encoding: 8bit";
        $messageBody = $textBody;
    }

    $message = implode("\r\n", $headers) . "\r\n\r\n" . servitech_mail_dot_stuff($messageBody);

    foreach ([
        ["MAIL FROM:<" . $fromEmail . ">", [250]],
        ["RCPT TO:<" . $toEmail . ">", [250, 251]],
        ["DATA", [354]],
    ] as $command) {
        $result = servitech_smtp_command($socket, $command[0], $command[1]);
        if (!$result["ok"]) {
            fclose($socket);
            return $result;
        }
    }

    fwrite($socket, $message . "\r\n.\r\n");
    $response = servitech_smtp_read_response($socket);
    servitech_smtp_command($socket, "QUIT", [221, 250]);
    fclose($socket);

    if (!in_array((int)$response["code"], [250], true)) {
        servitech_forgot_password_mail_log("SMTP message delivery failed: " . $response["message"]);
        return ["ok" => false, "error" => "SMTP message delivery failed: " . $response["message"]];
    }

    servitech_forgot_password_mail_log("SMTP message accepted by server for {$toEmail}: " . $response["message"]);
    return ["ok" => true, "error" => ""];
}

function servitech_load_phpmailer(): bool
{
    if (class_exists(PHPMailer::class)) {
        return true;
    }

    $autoloadPaths = [
        __DIR__ . "/../vendor/autoload.php",
        __DIR__ . "/vendor/autoload.php",
    ];

    foreach ($autoloadPaths as $autoloadPath) {
        if (is_file($autoloadPath)) {
            require_once $autoloadPath;
            if (class_exists(PHPMailer::class)) {
                return true;
            }
        }
    }

    $manualBase = __DIR__ . "/../PHPMailer/src";
    $manualFiles = [
        $manualBase . "/Exception.php",
        $manualBase . "/PHPMailer.php",
        $manualBase . "/SMTP.php",
    ];

    if (is_file($manualFiles[0]) && is_file($manualFiles[1]) && is_file($manualFiles[2])) {
        foreach ($manualFiles as $manualFile) {
            require_once $manualFile;
        }
    }

    return class_exists(PHPMailer::class);
}

function servitech_send_phpmailer_mail(string $toEmail, string $subject, string $textBody, string $htmlBody, array $config): array
{
    if (!servitech_load_phpmailer()) {
        servitech_forgot_password_mail_log("PHPMailer setup check failed: vendor/autoload.php not found or PHPMailer classes missing.");
        return ["available" => false, "ok" => false, "error" => "PHPMailer is not installed. Install phpmailer/phpmailer with Composer or place PHPMailer source files in PHPMailer/src."];
    }

    servitech_forgot_password_mail_log("PHPMailer setup check ok.");

    $debugOutput = "";

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string)$config["host"];
        $mail->Port = (int)$config["port"];
        $mail->SMTPAuth = true;
        $mail->Username = (string)$config["username"];
        $mail->Password = (string)$config["password"];
        $mail->SMTPSecure = ((string)$config["encryption"] === "ssl")
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;

        if (servitech_mail_debug_enabled()) {
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            $mail->Debugoutput = static function (string $str, int $level) use (&$debugOutput): void {
                $debugOutput .= "SMTP debug {$level}: {$str}\n";
            };
        }

        $mail->CharSet = "UTF-8";
        $mail->setFrom((string)$config["from_email"], (string)$config["from_name"]);
        $mail->addReplyTo((string)$config["reply_to"]);
        $mail->addAddress($toEmail);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody !== "" ? $htmlBody : nl2br(htmlspecialchars($textBody, ENT_QUOTES, "UTF-8"));
        $mail->AltBody = $textBody;
        $mail->isHTML($htmlBody !== "");

        $sent = $mail->send();
        if ($debugOutput !== "") {
            servitech_mail_log("PHPMailer SMTP debug for {$toEmail}:\n" . $debugOutput);
        }

        if (!$sent) {
            servitech_forgot_password_mail_log("PHPMailer ErrorInfo for {$toEmail}: " . $mail->ErrorInfo);
            return ["available" => true, "ok" => false, "error" => $mail->ErrorInfo];
        }

        return ["available" => true, "ok" => true, "error" => ""];
    } catch (PHPMailerException $e) {
        if ($debugOutput !== "") {
            servitech_mail_log("PHPMailer SMTP debug for {$toEmail}:\n" . $debugOutput);
        }

        return ["available" => true, "ok" => false, "error" => $e->getMessage()];
    } catch (Throwable $e) {
        if ($debugOutput !== "") {
            servitech_mail_log("PHPMailer SMTP debug for {$toEmail}:\n" . $debugOutput);
        }

        return ["available" => true, "ok" => false, "error" => $e->getMessage()];
    }
}

function servitech_send_password_reset_mail(string $toEmail, string $resetUrl): array
{
    $subject = "ServiTech Password Reset Request";
    $textBody = "You requested a password reset for your ServiTech account.\n\n"
        . "Use this secure link to choose a new password:\n{$resetUrl}\n\n"
        . "This link expires in 1 hour. If you did not request this, you can ignore this email.";
    $safeResetUrl = htmlspecialchars($resetUrl, ENT_QUOTES, "UTF-8");
    $htmlBody = '<!doctype html>
<html>
<body style="margin:0;padding:0;background:#fff7ed;font-family:Arial,Helvetica,sans-serif;color:#24120f;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#fff7ed;padding:28px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border:1px solid #f0d6bd;border-radius:8px;overflow:hidden;">
          <tr>
            <td style="padding:24px 28px;background:#4A0505;color:#ffffff;">
              <h1 style="margin:0;font-size:22px;line-height:1.25;">ServiTech</h1>
            </td>
          </tr>
          <tr>
            <td style="padding:28px;">
              <h2 style="margin:0 0 12px;color:#4A0505;font-size:22px;line-height:1.3;">Password reset request</h2>
              <p style="margin:0 0 18px;font-size:15px;line-height:1.6;">You requested a password reset for your ServiTech account.</p>
              <p style="margin:0 0 24px;font-size:15px;line-height:1.6;">Use the button below to choose a new password. This link expires in 1 hour.</p>
              <p style="margin:0 0 24px;">
                <a href="' . $safeResetUrl . '" style="display:inline-block;background:#4A0505;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 18px;border-radius:6px;">Reset Password</a>
              </p>
              <p style="margin:0 0 10px;font-size:13px;line-height:1.6;color:#65564d;">If the button does not work, copy and paste this link into your browser:</p>
              <p style="margin:0 0 22px;font-size:13px;line-height:1.6;word-break:break-all;"><a href="' . $safeResetUrl . '" style="color:#7c130d;">' . $safeResetUrl . '</a></p>
              <p style="margin:0;font-size:13px;line-height:1.6;color:#65564d;">If you did not request this password reset, you can ignore this email.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';

    return servitech_send_smtp_mail($toEmail, $subject, $textBody, $htmlBody);
}

function servitech_send_email_verification_mail(string $toEmail, string $verificationUrl): array
{
    $subject = "Verify your ServiTech email address";
    $textBody = "Welcome to ServiTech.\n\n"
        . "Verify your email address before logging in:\n{$verificationUrl}\n\n"
        . "This link expires in 24 hours. If you did not create this account, you can ignore this email.";
    $safeVerificationUrl = htmlspecialchars($verificationUrl, ENT_QUOTES, "UTF-8");
    $htmlBody = '<!doctype html>
<html>
<body style="margin:0;padding:0;background:#fff7ed;font-family:Arial,Helvetica,sans-serif;color:#24120f;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#fff7ed;padding:28px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border:1px solid #f0d6bd;border-radius:8px;overflow:hidden;">
          <tr>
            <td style="padding:24px 28px;background:#4A0505;color:#ffffff;">
              <h1 style="margin:0;font-size:22px;line-height:1.25;">ServiTech</h1>
            </td>
          </tr>
          <tr>
            <td style="padding:28px;">
              <h2 style="margin:0 0 12px;color:#4A0505;font-size:22px;line-height:1.3;">Verify your email address</h2>
              <p style="margin:0 0 18px;font-size:15px;line-height:1.6;">Welcome to ServiTech. Confirm that this email address belongs to you before logging in.</p>
              <p style="margin:0 0 24px;">
                <a href="' . $safeVerificationUrl . '" style="display:inline-block;background:#4A0505;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 18px;border-radius:6px;">Verify Email</a>
              </p>
              <p style="margin:0 0 10px;font-size:13px;line-height:1.6;color:#65564d;">This link expires in 24 hours. If the button does not work, copy and paste this link into your browser:</p>
              <p style="margin:0 0 22px;font-size:13px;line-height:1.6;word-break:break-all;"><a href="' . $safeVerificationUrl . '" style="color:#7c130d;">' . $safeVerificationUrl . '</a></p>
              <p style="margin:0;font-size:13px;line-height:1.6;color:#65564d;">If you did not create this account, you can ignore this email.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';

    return servitech_send_smtp_mail($toEmail, $subject, $textBody, $htmlBody);
}
