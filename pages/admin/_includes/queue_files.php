<?php
require_once __DIR__ . "/../../../api/upload_helpers.php";

function admin_queue_details_array($details): array
{
    if (is_array($details)) {
        return $details;
    }

    if (is_string($details) && $details !== "") {
        $decoded = json_decode($details, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [];
}

function admin_queue_upload_file_path(string $path): string
{
    $path = trim($path);
    if ($path === "") {
        return "";
    }

    $pathOnly = parse_url($path, PHP_URL_PATH);
    if (!is_string($pathOnly) || $pathOnly === "") {
        return "";
    }

    $pathOnly = "/" . ltrim($pathOnly, "/");
    $base = function_exists("servitech_base_path") ? servitech_base_path() : "";
    if ($base !== "" && strpos($pathOnly, $base . "/") === 0) {
        $pathOnly = substr($pathOnly, strlen($base));
    }

    $allowedPrefixes = [
        "/uploads/printing/",
        "/uploads/print_orders/",
    ];
    $matchedPrefix = "";
    foreach ($allowedPrefixes as $prefix) {
        if (strpos($pathOnly, $prefix) === 0) {
            $matchedPrefix = $prefix;
            break;
        }
    }

    if ($matchedPrefix === "") {
        $basename = basename(rawurldecode($pathOnly));
        return $basename !== "" ? "/uploads/printing/" . $basename : "";
    }

    return $matchedPrefix . basename(rawurldecode($pathOnly));
}

function admin_queue_upload_file_exists(string $path): bool
{
    $safePath = admin_queue_upload_file_path($path);
    if ($safePath === "") {
        return false;
    }

    $fullPath = dirname(__DIR__, 3) . str_replace("/", DIRECTORY_SEPARATOR, $safePath);
    return is_file($fullPath);
}

function admin_queue_upload_file_url(string $path): string
{
    $safePath = admin_queue_upload_file_path($path);
    if ($safePath === "" || !admin_queue_upload_file_exists($safePath)) {
        return "";
    }

    $downloadPath = "/api/legacy_upload_download.php?path=" . rawurlencode($safePath) . "&disposition=inline";
    return function_exists("admin_url_raw") ? admin_url_raw($downloadPath) : $downloadPath;
}

function admin_queue_file_items($details): array
{
    $details = admin_queue_details_array($details);
    $items = [];

    $uploadedFiles = $details["uploaded_files"] ?? [];
    if (is_array($uploadedFiles)) {
        foreach ($uploadedFiles as $index => $file) {
            if (!is_array($file)) {
                continue;
            }

            $label = trim((string)($file["original_name"] ?? ""));
            if ($label === "") {
                $label = "File " . ((int)$index + 1);
            }

            $token = strtolower(trim((string)($file["upload_token"] ?? "")));
            if (preg_match('/^[a-f0-9]{64}$/', $token)) {
                $items[] = [
                    "label" => $label,
                    "url" => admin_url_raw(servitech_upload_download_path($token, true)),
                    "path" => "",
                ];
                continue;
            }

            $path = (string)($file["saved_path"] ?? $file["file_path"] ?? "");
            $items[] = [
                "label" => $label,
                "url" => admin_queue_upload_file_url($path),
                "path" => admin_queue_upload_file_path($path),
            ];
        }
    }

    if ($items) {
        return $items;
    }

    $fileName = trim((string)($details["file_name"] ?? ""));
    if ($fileName !== "") {
        $items[] = [
            "label" => basename($fileName),
            "url" => admin_queue_upload_file_url($fileName),
            "path" => admin_queue_upload_file_path($fileName),
        ];
    }

    return $items;
}

function admin_queue_render_file_items($details): void
{
    $fileItems = admin_queue_file_items($details);
    if (!$fileItems) {
        echo '<span class="admin-file-empty">No file</span>';
        return;
    }

    echo '<div class="admin-file-list">';
    foreach ($fileItems as $fileItem) {
        $label = htmlspecialchars((string)($fileItem["label"] ?? "File"), ENT_QUOTES, "UTF-8");
        $url = (string)($fileItem["url"] ?? "");

        if ($url !== "") {
            $safeUrl = htmlspecialchars($url, ENT_QUOTES, "UTF-8");
            echo '<div class="admin-file-chip">';
            echo '<span class="admin-file-name">' . $label . '</span>';
            echo '<span class="admin-file-actions">';
            echo '<a class="admin-file-action" href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer">Open</a>';
            echo '<a class="admin-file-action" href="' . $safeUrl . '" download>Download</a>';
            echo '</span>';
            echo '</div>';
            continue;
        }

        echo '<span class="admin-file-empty">';
        echo '<span class="admin-file-name">' . $label . '</span>';
        echo '<span class="admin-file-action">File unavailable</span>';
        echo '</span>';
    }
    echo '</div>';
}

function admin_queue_notification_count(PDO $pdo): int
{
    try {
        $stmt = $pdo->query("
            SELECT COUNT(*)
            FROM queues q
            LEFT JOIN LATERAL (
                SELECT payment_method, reference_number
                FROM payments
                WHERE queue_id = q.id
                ORDER BY id DESC
                LIMIT 1
            ) p ON TRUE
            WHERE UPPER(TRIM(COALESCE(q.status, 'PENDING'))) NOT IN ('DONE', 'CANCELLED', 'CANCELED')
              AND (
                jsonb_typeof(q.details::jsonb->'uploaded_files') = 'array'
                OR NULLIF(TRIM(COALESCE(q.details->>'file_name', '')), '') IS NOT NULL
                OR (
                    LOWER(TRIM(COALESCE(p.payment_method, q.details->>'payment_method', ''))) = 'gcash'
                    AND NULLIF(TRIM(COALESCE(p.reference_number, q.details->>'reference_number', '')), '') IS NOT NULL
                    AND UPPER(TRIM(COALESCE(q.status, 'PENDING'))) = 'PENDING'
                )
                OR q.created_at >= (NOW() - INTERVAL '1 day')
              )
        ");
        return max(0, (int)$stmt->fetchColumn());
    } catch (Throwable $exception) {
        error_log("admin_queue_notification_count error: " . $exception->getMessage());
        return 0;
    }
}

function admin_queue_notification_link(): string
{
    return function_exists("admin_url") ? admin_url("/pages/admin/queue_list/printing.php") : "/pages/admin/queue_list/printing.php";
}
