<?php
require_once __DIR__ . "/session_check.php";

const SERVITECH_JOIN_QUEUE_COMPLETION_KEY = "join_queue_completion";
const SERVITECH_JOIN_QUEUE_NEW_REQUEST_KEY = "join_queue_new_request_started";
const SERVITECH_SERVICE_PAYMENT_DRAFT_KEY = "service_payment_draft";
const SERVITECH_SERVICE_PAYMENT_DRAFT_TTL = 7200;

if (!function_exists("servitech_service_payment_draft")) {
    function servitech_service_payment_draft(): ?array
    {
        $draft = $_SESSION[SERVITECH_SERVICE_PAYMENT_DRAFT_KEY] ?? null;
        if (!is_array($draft)) {
            return null;
        }

        $createdAt = (int)($draft["created_at"] ?? 0);
        $userId = (int)($draft["user_id"] ?? 0);
        $token = trim((string)($draft["token"] ?? ""));
        $method = strtolower(trim((string)($draft["payment_method"] ?? "")));
        if (
            $createdAt <= 0
            || $createdAt < time() - SERVITECH_SERVICE_PAYMENT_DRAFT_TTL
            || $userId <= 0
            || $userId !== (int)($_SESSION["user_id"] ?? 0)
            || !preg_match('/^[a-f0-9]{64}$/', $token)
            || $method !== "gcash"
        ) {
            unset($_SESSION[SERVITECH_SERVICE_PAYMENT_DRAFT_KEY]);
            return null;
        }

        return $draft;
    }
}

if (!function_exists("servitech_service_payment_draft_url")) {
    function servitech_service_payment_draft_url(?array $draft = null, bool $incomplete = false): string
    {
        $draft = $draft ?? servitech_service_payment_draft();
        if (!is_array($draft)) {
            return servitech_url("/pages/customer/customer_dash.php");
        }
        $query = ["draft_token" => (string)$draft["token"]];
        if ($incomplete) $query["incomplete"] = "1";
        return servitech_url("/pages/customer/custo_service_payment.php?" . http_build_query($query));
    }
}

if (!function_exists("servitech_service_payment_draft_matches")) {
    function servitech_service_payment_draft_matches(string $token, ?array $draft = null): bool
    {
        $draft = $draft ?? servitech_service_payment_draft();
        $expected = is_array($draft) ? trim((string)($draft["token"] ?? "")) : "";
        return $expected !== "" && $token !== "" && hash_equals($expected, trim($token));
    }
}

if (!function_exists("servitech_mark_join_queue_completed")) {
    function servitech_mark_join_queue_completed(string $queueCode): void
    {
        unset(
            $_SESSION["print_order_draft"],
            $_SESSION["print_order_flash_error"],
            $_SESSION["print_order_form"],
            $_SESSION["service_payment_draft"],
            $_SESSION["service_payment_flash_error"],
            $_SESSION["service_payment_form"]
        );

        $_SESSION[SERVITECH_JOIN_QUEUE_COMPLETION_KEY] = [
            "queue_code" => trim($queueCode),
            "completed_at" => time(),
        ];
    }
}

if (!function_exists("servitech_join_queue_was_completed")) {
    function servitech_join_queue_was_completed(): bool
    {
        $completion = $_SESSION[SERVITECH_JOIN_QUEUE_COMPLETION_KEY] ?? null;
        if (!is_array($completion)) {
            return false;
        }

        $completedAt = (int)($completion["completed_at"] ?? 0);
        if ($completedAt <= 0 || $completedAt < time() - 7200) {
            unset($_SESSION[SERVITECH_JOIN_QUEUE_COMPLETION_KEY]);
            return false;
        }

        return true;
    }
}

if (!function_exists("servitech_clear_join_queue_completion")) {
    function servitech_clear_join_queue_completion(): void
    {
        unset($_SESSION[SERVITECH_JOIN_QUEUE_COMPLETION_KEY]);
    }
}

if (!function_exists("servitech_mark_new_join_queue_started")) {
    function servitech_mark_new_join_queue_started(): void
    {
        $_SESSION[SERVITECH_JOIN_QUEUE_NEW_REQUEST_KEY] = time();
    }
}

if (!function_exists("servitech_consume_new_join_queue_started")) {
    function servitech_consume_new_join_queue_started(): bool
    {
        $startedAt = (int)($_SESSION[SERVITECH_JOIN_QUEUE_NEW_REQUEST_KEY] ?? 0);
        unset($_SESSION[SERVITECH_JOIN_QUEUE_NEW_REQUEST_KEY]);

        return $startedAt > 0 && $startedAt >= time() - 120;
    }
}

if (!function_exists("servitech_start_new_join_queue_if_requested")) {
    function servitech_start_new_join_queue_if_requested(): void
    {
        if ((string)($_GET["new_queue"] ?? "") !== "1") {
            return;
        }

        servitech_clear_join_queue_completion();
        servitech_mark_new_join_queue_started();

        $requestUri = (string)($_SERVER["REQUEST_URI"] ?? "");
        $path = (string)(parse_url($requestUri, PHP_URL_PATH) ?: "/");
        $query = [];
        parse_str((string)(parse_url($requestUri, PHP_URL_QUERY) ?? ""), $query);
        unset($query["new_queue"]);

        $location = $path;
        if ($query) {
            $location .= "?" . http_build_query($query);
        }

        header("Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0");
        header("Location: " . $location);
        exit();
    }
}

if (!function_exists("servitech_redirect_completed_join_queue")) {
    function servitech_redirect_completed_join_queue(): void
    {
        header("Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0");
        header("Pragma: no-cache");
        header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

        if (!servitech_join_queue_was_completed()) {
            return;
        }

        header("Location: " . servitech_url("/pages/customer/customer_dash.php"));
        exit();
    }
}
