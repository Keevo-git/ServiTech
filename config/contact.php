<?php

function servitech_contact_raw_env_value(string $key): string
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

function servitech_contact_dotenv_paths(): array
{
    return [
        dirname(__DIR__) . "/.env",
        __DIR__ . "/.env",
    ];
}

function servitech_contact_parse_dotenv_value(string $value): string
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

function servitech_contact_dotenv_values(): array
{
    static $values = null;
    if (is_array($values)) {
        return $values;
    }

    $values = [];

    foreach (servitech_contact_dotenv_paths() as $path) {
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            continue;
        }

        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === "" || strpos($line, "#") === 0 || strpos($line, "=") === false) {
                continue;
            }

            [$key, $value] = explode("=", $line, 2);
            $key = trim($key);
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) || array_key_exists($key, $values)) {
                continue;
            }

            $values[$key] = servitech_contact_parse_dotenv_value($value);
        }
    }

    return $values;
}

function servitech_contact_env_value(string $key, string $default = ""): string
{
    $value = servitech_contact_raw_env_value($key);
    if ($value !== "") {
        return $value;
    }

    $dotenvValues = servitech_contact_dotenv_values();
    if (isset($dotenvValues[$key]) && trim((string)$dotenvValues[$key]) !== "") {
        return trim((string)$dotenvValues[$key]);
    }

    return $default;
}

function servitech_contact_email(): string
{
    return servitech_contact_env_value("SERVITECH_CONTACT_EMAIL", "theservitech.store@gmail.com");
}

function servitech_contact_phone(): string
{
    return servitech_contact_env_value("SERVITECH_CONTACT_PHONE", "+63 912 393 4321");
}

function servitech_contact_facebook_url(): string
{
    return servitech_contact_env_value("SERVITECH_CONTACT_FACEBOOK_URL", "https://www.facebook.com/JCstorebagbaguin");
}

function servitech_contact_facebook_label(): string
{
    return servitech_contact_env_value("SERVITECH_CONTACT_FACEBOOK_LABEL", "JC Store");
}

function servitech_gcash_account_name(): string
{
    return servitech_contact_env_value("SERVITECH_GCASH_ACCOUNT_NAME");
}

function servitech_gcash_account_number(): string
{
    return servitech_contact_env_value("SERVITECH_GCASH_ACCOUNT_NUMBER");
}

function servitech_contact_link_html(string $emptyMessage = "Contact email unavailable"): string
{
    $email = servitech_contact_email();
    if ($email === "") {
        return htmlspecialchars($emptyMessage, ENT_QUOTES, "UTF-8");
    }

    $safeEmail = htmlspecialchars($email, ENT_QUOTES, "UTF-8");
    return '<a href="mailto:' . $safeEmail . '">' . $safeEmail . '</a>';
}
