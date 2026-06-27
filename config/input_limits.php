<?php

const SERVITECH_LIMIT_FULLNAME = 160;
const SERVITECH_LIMIT_EMAIL = 255;
const SERVITECH_LIMIT_CONTACT = 13;
const SERVITECH_LIMIT_ADDRESS = 500;
const SERVITECH_LIMIT_EMERGENCY_RELATIONSHIP = 80;
const SERVITECH_LIMIT_POSITION_TITLE = 120;
const SERVITECH_LIMIT_EMPLOYEE_NOTES = 1000;
const SERVITECH_LIMIT_SERVICE_NAME = 120;
const SERVITECH_LIMIT_SERVICE_DESCRIPTION = 255;
const SERVITECH_LIMIT_SERVICE_OPTION_KEY = 80;
const SERVITECH_LIMIT_SERVICE_OPTION_LABEL = 120;
const SERVITECH_LIMIT_SERVICE_OPTION_DESCRIPTION = 255;
const SERVITECH_LIMIT_ANNOUNCEMENT_TITLE = 90;
const SERVITECH_LIMIT_ANNOUNCEMENT_MESSAGE = 420;
const SERVITECH_LIMIT_MESSAGE_SUBJECT = 120;
const SERVITECH_LIMIT_MESSAGE_BODY = 1000;
const SERVITECH_LIMIT_QUEUE_NOTES = 1000;
const SERVITECH_LIMIT_SEND_BACK_REASON = 1000;
const SERVITECH_LIMIT_STATUS_NOTES = 1000;
const SERVITECH_LIMIT_SEARCH = 120;
const SERVITECH_LIMIT_HOLIDAY_TITLE = 120;
const SERVITECH_LIMIT_HOLIDAY_NOTE = 500;

function servitech_text_length(string $value): int
{
    return function_exists("mb_strlen") ? mb_strlen($value, "UTF-8") : strlen($value);
}

function servitech_length_error(string $value, string $label, int $maxLength): string
{
    return servitech_text_length($value) > $maxLength
        ? "{$label} must not exceed {$maxLength} characters."
        : "";
}

function servitech_assert_max_length(string $value, string $label, int $maxLength): void
{
    $error = servitech_length_error($value, $label, $maxLength);
    if ($error !== "") {
        throw new DomainException($error);
    }
}

function servitech_email_validation_error(string $email): string
{
    $email = trim($email);
    if ($email === "") {
        return "Email address is required.";
    }
    if (servitech_text_length($email) > SERVITECH_LIMIT_EMAIL) {
        return "Email address must not exceed " . SERVITECH_LIMIT_EMAIL . " characters.";
    }
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? "" : "Enter a valid email address.";
}

function servitech_person_name_validation_error(string $value, string $label = "Full name", bool $required = true): string
{
    $value = trim($value);
    if ($value === "") {
        return $required ? "{$label} is required." : "";
    }
    if (servitech_text_length($value) > SERVITECH_LIMIT_FULLNAME) {
        return "{$label} must not exceed " . SERVITECH_LIMIT_FULLNAME . " characters.";
    }
    return preg_match('/^[\pL\s.\'-]+$/u', $value) ? "" : "Enter a valid {$label}.";
}

function servitech_ph_mobile_validation_error(string $value, string $label = "contact number", bool $required = true): string
{
    $value = trim($value);
    if ($value === "") {
        return $required ? ucfirst($label) . " is required." : "";
    }
    return preg_match('/^\+639\d{9}$/', $value) ? "" : "Enter a valid Philippine mobile {$label}.";
}
