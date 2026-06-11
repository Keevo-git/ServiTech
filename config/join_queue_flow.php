<?php
require_once __DIR__ . "/session_check.php";

const SERVITECH_JOIN_QUEUE_COMPLETION_KEY = "join_queue_completion";

if (!function_exists("servitech_mark_join_queue_completed")) {
    function servitech_mark_join_queue_completed(string $queueCode): void
    {
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

if (!function_exists("servitech_redirect_completed_join_queue")) {
    function servitech_redirect_completed_join_queue(): void
    {
        if (!servitech_join_queue_was_completed()) {
            return;
        }

        header("Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0");
        header("Pragma: no-cache");
        header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
        header("Location: " . servitech_url("/pages/customer/custo_place_queueing.php"));
        exit();
    }
}
