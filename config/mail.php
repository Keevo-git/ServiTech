<?php

function servitech_mail_env_value(string $key): string
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

function servitech_send_smtp_mail(string $toEmail, string $subject, string $textBody): array
{
    $host = servitech_mail_config_value("SMTP_HOST", "host");
    $port = (int)servitech_mail_config_value("SMTP_PORT", "port", "587");
    $username = servitech_mail_config_value("SMTP_USERNAME", "username");
    $password = servitech_mail_config_value("SMTP_PASSWORD", "password");
    $encryption = strtolower(servitech_mail_config_value("SMTP_ENCRYPTION", "encryption", "tls"));
    $fromEmail = servitech_mail_config_value("SMTP_FROM_EMAIL", "from_email", $username);
    $fromName = servitech_mail_config_value("SMTP_FROM_NAME", "from_name", "ServiTech");
    $replyTo = servitech_mail_config_value("SMTP_REPLY_TO", "reply_to", $fromEmail);

    if ($host === "" || $fromEmail === "") {
        return ["ok" => false, "error" => "SMTP is not configured. Set SMTP_HOST, SMTP_FROM_EMAIL, SMTP_USERNAME, and SMTP_PASSWORD."];
    }

    $transportHost = ($encryption === "ssl" ? "ssl://" : "") . $host;
    $errno = 0;
    $errstr = "";
    $socket = @stream_socket_client($transportHost . ":" . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        return ["ok" => false, "error" => "SMTP connection failed: " . ($errstr !== "" ? $errstr : "unknown error")];
    }

    stream_set_timeout($socket, 20);
    $response = servitech_smtp_read_response($socket);
    if ((int)$response["code"] !== 220) {
        fclose($socket);
        return ["ok" => false, "error" => "SMTP greeting failed: " . $response["message"]];
    }

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
            return ["ok" => false, "error" => "SMTP TLS negotiation failed."];
        }

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
            return ["ok" => false, "error" => "SMTP authentication failed."];
        }
    }

    $encodedSubject = "=?UTF-8?B?" . base64_encode($subject) . "?=";
    $headers = [
        "From: " . $fromName . " <" . $fromEmail . ">",
        "To: <" . $toEmail . ">",
        "Reply-To: " . $replyTo,
        "Subject: " . $encodedSubject,
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=UTF-8",
        "Content-Transfer-Encoding: 8bit",
        "Date: " . date(DATE_RFC2822),
    ];
    $message = implode("\r\n", $headers) . "\r\n\r\n" . servitech_mail_dot_stuff($textBody);

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
        return ["ok" => false, "error" => "SMTP message delivery failed: " . $response["message"]];
    }

    return ["ok" => true, "error" => ""];
}

function servitech_send_password_reset_mail(string $toEmail, string $resetUrl): array
{
    $subject = "Reset your ServiTech password";
    $body = "We received a request to reset your ServiTech password.\n\n"
        . "Open this link to choose a new password:\n{$resetUrl}\n\n"
        . "This link expires in 1 hour. If you did not request this, you can ignore this email.";

    $smtpHost = servitech_mail_config_value("SMTP_HOST", "host");
    if ($smtpHost !== "") {
        return servitech_send_smtp_mail($toEmail, $subject, $body);
    }

    $fromEmail = servitech_mail_config_value("SMTP_FROM_EMAIL", "from_email", "servitech@gmail.com");
    $headers = [
        "From: ServiTech <" . $fromEmail . ">",
        "Reply-To: " . $fromEmail,
        "Content-Type: text/plain; charset=UTF-8",
    ];

    $sent = @mail($toEmail, $subject, $body, implode("\r\n", $headers), "-f" . $fromEmail);
    return [
        "ok" => $sent,
        "error" => $sent ? "" : "PHP mail() failed and SMTP_HOST is not configured.",
    ];
}
