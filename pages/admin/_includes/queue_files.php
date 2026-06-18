<?php
require_once __DIR__ . "/../../../api/upload_helpers.php";
require_once __DIR__ . "/../../../api/queue_helpers.php";

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

function admin_queue_file_items($details, ?PDO $pdo = null): array
{
    $details = admin_queue_details_array($details);
    $items = [];
    $pdo = $pdo instanceof PDO ? $pdo : (($GLOBALS["pdo"] ?? null) instanceof PDO ? $GLOBALS["pdo"] : null);

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
                $available = !($pdo instanceof PDO) || servitech_upload_token_is_available($pdo, $token);
                $items[] = [
                    "label" => $label,
                    "url" => $available ? admin_url_raw(servitech_upload_download_path($token, true)) : "",
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

function admin_notification_bool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    return in_array(strtolower(trim((string)$value)), ["1", "t", "true", "y", "yes", "on"], true);
}

function admin_notification_format_timestamp(?string $value): string
{
    $value = trim((string)$value);
    if ($value === "") {
        return "";
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? $value : date("M d, Y h:i A", $timestamp);
}

function admin_notification_category_label(string $category): string
{
    $category = strtolower(trim($category));
    return match ($category) {
        "online_printorder", "printing_online" => "Print",
        "printing", "walkin", "printing_walkin" => "Print",
        "repair" => "Repair",
        "installation" => "Installation",
        default => $category !== "" ? ucwords(str_replace(["_", "-"], " ", $category)) : "Admin Update",
    };
}

function admin_notification_service_label(array $queue): string
{
    $details = admin_queue_details_array($queue["details"] ?? null);
    $service = trim((string)($details["service_label"] ?? $details["package_label"] ?? ""));
    if ($service !== "") {
        $normalized = strtolower($service);
        if (in_array($normalized, [
            "document printing",
            "document print",
            "online document printing",
            "online document print",
            "online print order",
            "online printing",
            "walk-in document printing",
            "walk-in document print",
            "walk-in printing",
            "walkin printing",
            "print walk-in",
            "print online",
        ], true)) {
            return "Document Print";
        }
        if ($normalized === "xerox") {
            return "Photocopy";
        }
        return $service;
    }

    return admin_notification_category_label((string)($queue["category"] ?? $queue["queue_category"] ?? ""));
}

function admin_notifications_cleanup_duplicates(PDO $pdo): void
{
    static $cleaned = false;
    if ($cleaned) {
        return;
    }
    $cleaned = true;

    try {
        $pdo->exec("
            UPDATE notifications n
            SET deleted_at = NOW()
            FROM queues q
            WHERE q.id = n.reference_id
              AND n.deleted_at IS NULL
              AND LOWER(TRIM(COALESCE(n.type, 'queue'))) = 'admin_stalled'
              AND UPPER(TRIM(COALESCE(q.status, ''))) IN ('FOR PICK-UP', 'FOR PICK UP', 'DONE', 'COMPLETED', 'CANCELLED', 'CANCELED')
              AND n.user_id IN (
                  SELECT id
                  FROM users
                  WHERE LOWER(TRIM(COALESCE(role, 'customer'))) = 'admin'
              )
        ");

        $pdo->exec("
            WITH ranked_admin_notifications AS (
                SELECT
                    n.id,
                    ROW_NUMBER() OVER (
                        PARTITION BY LOWER(TRIM(COALESCE(n.type, 'queue'))), COALESCE(n.reference_id, 0),
                          COALESCE(NULLIF(TRIM(n.event_key), ''), MD5(TRIM(COALESCE(n.message, ''))))
                        ORDER BY n.created_at ASC, n.id ASC
                    ) AS duplicate_rank,
                    COUNT(*) OVER (
                        PARTITION BY LOWER(TRIM(COALESCE(n.type, 'queue'))), COALESCE(n.reference_id, 0),
                          COALESCE(NULLIF(TRIM(n.event_key), ''), MD5(TRIM(COALESCE(n.message, ''))))
                    ) AS duplicate_count,
                    FIRST_VALUE(n.id) OVER (
                        PARTITION BY LOWER(TRIM(COALESCE(n.type, 'queue'))), COALESCE(n.reference_id, 0),
                          COALESCE(NULLIF(TRIM(n.event_key), ''), MD5(TRIM(COALESCE(n.message, ''))))
                        ORDER BY n.created_at ASC, n.id ASC
                    ) AS keep_id,
                    BOOL_AND(COALESCE(n.is_read, FALSE)) OVER (
                        PARTITION BY LOWER(TRIM(COALESCE(n.type, 'queue'))), COALESCE(n.reference_id, 0),
                          COALESCE(NULLIF(TRIM(n.event_key), ''), MD5(TRIM(COALESCE(n.message, ''))))
                    ) AS all_copies_read
                FROM notifications n
                WHERE n.deleted_at IS NULL
                  AND LOWER(TRIM(COALESCE(n.type, 'queue'))) LIKE 'admin\_%' ESCAPE '\'
                  AND n.user_id IN (
                      SELECT id
                      FROM users
                      WHERE LOWER(TRIM(COALESCE(role, 'customer'))) = 'admin'
                  )
            ),
            preserved_notifications AS (
                UPDATE notifications n
                SET is_read = r.all_copies_read
                FROM ranked_admin_notifications r
                WHERE n.id = r.keep_id
                  AND r.duplicate_count > 1
                  AND n.is_read IS DISTINCT FROM r.all_copies_read
                RETURNING n.id
            )
            UPDATE notifications n
            SET deleted_at = NOW()
            FROM ranked_admin_notifications r
            WHERE n.id = r.id
              AND r.duplicate_rank > 1
              AND n.deleted_at IS NULL
        ");
    } catch (Throwable $exception) {
        error_log("admin_notifications_cleanup_duplicates error: " . $exception->getMessage());
    }
}

function admin_notifications_sync_stalled(PDO $pdo): void
{
    static $synced = false;
    if ($synced) {
        return;
    }
    $synced = true;

    try {
        servitech_ensure_queue_lifecycle_schema($pdo);

        $stmt = $pdo->query("
            SELECT
                q.id,
                q.queue_code,
                q.category,
                q.status,
                q.details::text AS details,
                GREATEST(q.created_at, COALESCE(q.updated_at, q.created_at), COALESCE(last_admin_action.last_action_at, q.created_at)) AS last_action_at,
                FLOOR(EXTRACT(EPOCH FROM (NOW() - GREATEST(q.created_at, COALESCE(q.updated_at, q.created_at), COALESCE(last_admin_action.last_action_at, q.created_at)))) / 86400)::int AS waiting_days,
                FLOOR(EXTRACT(EPOCH FROM (NOW() - GREATEST(q.created_at, COALESCE(q.updated_at, q.created_at), COALESCE(last_admin_action.last_action_at, q.created_at)))) / (86400 * 14))::int AS reminder_cycle,
                last_stalled_notification.last_reminded_at
            FROM queues q
            LEFT JOIN LATERAL (
                SELECT MAX(h.created_at) AS last_action_at
                FROM queue_status_history h
                WHERE h.queue_id = q.id
                  AND h.admin_id IS NOT NULL
            ) last_admin_action ON TRUE
            LEFT JOIN LATERAL (
                SELECT MAX(n.created_at) AS last_reminded_at
                FROM notifications n
                WHERE n.reference_id = q.id
                  AND n.deleted_at IS NULL
                  AND LOWER(TRIM(COALESCE(n.type, 'queue'))) = 'admin_stalled'
                  AND n.user_id IN (
                      SELECT id
                      FROM users
                      WHERE LOWER(TRIM(COALESCE(role, 'customer'))) = 'admin'
                  )
            ) last_stalled_notification ON TRUE
            WHERE UPPER(TRIM(COALESCE(q.status, 'PENDING'))) IN ('PENDING', 'APPROVED', 'ONGOING')
              AND GREATEST(q.created_at, COALESCE(q.updated_at, q.created_at), COALESCE(last_admin_action.last_action_at, q.created_at)) <= NOW() - INTERVAL '14 days'
              AND (
                  last_stalled_notification.last_reminded_at IS NULL
                  OR last_stalled_notification.last_reminded_at <= NOW() - INTERVAL '14 days'
              )
            ORDER BY GREATEST(q.created_at, COALESCE(q.updated_at, q.created_at), COALESCE(last_admin_action.last_action_at, q.created_at)) ASC
            LIMIT 100
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $queue) {
            $queueId = (int)($queue["id"] ?? 0);
            $queueCode = trim((string)($queue["queue_code"] ?? ""));
            $status = strtoupper(trim((string)($queue["status"] ?? "PENDING")));
            $lastAction = trim((string)($queue["last_action_at"] ?? ""));
            $waitingDays = max(14, (int)($queue["waiting_days"] ?? 14));
            $reminderCycle = max(1, (int)($queue["reminder_cycle"] ?? 1));
            $service = admin_notification_service_label($queue);
            $eventStamp = strtotime($lastAction);
            $eventVersion = $eventStamp === false ? sha1($lastAction) : date("YmdHis", $eventStamp);

            if ($queueId <= 0 || $queueCode === "") {
                continue;
            }

            servitech_notify_admins(
                $pdo,
                "admin_stalled",
                $queueId,
                "Queue {$queueCode}: Order/request has been waiting {$waitingDays} days without admin action. Service: {$service}. Status: {$status}.",
                "admin_stalled:{$queueId}:{$status}:{$eventVersion}:cycle:{$reminderCycle}",
                true
            );
        }
    } catch (Throwable $exception) {
        error_log("admin_notifications_sync_stalled error: " . $exception->getMessage());
    }

    admin_notifications_cleanup_duplicates($pdo);
}

function admin_notification_unread_count(PDO $pdo): int
{
    admin_notifications_sync_stalled($pdo);

    $stmt = $pdo->query("
        WITH ranked_notifications AS (
            SELECT
                n.is_read,
                ROW_NUMBER() OVER (
                    PARTITION BY LOWER(TRIM(COALESCE(n.type, 'queue'))), COALESCE(n.reference_id, 0),
                      COALESCE(NULLIF(TRIM(n.event_key), ''), MD5(TRIM(COALESCE(n.message, ''))))
                    ORDER BY n.created_at DESC, n.id DESC
                ) AS duplicate_rank
            FROM notifications n
            WHERE n.user_id IN (
                SELECT id
                FROM users
                WHERE LOWER(TRIM(COALESCE(role, 'customer'))) = 'admin'
            )
              AND n.deleted_at IS NULL
        )
        SELECT COUNT(*)
        FROM ranked_notifications
        WHERE duplicate_rank = 1
          AND COALESCE(is_read, FALSE) = FALSE
    ");

    return max(0, (int)$stmt->fetchColumn());
}

function admin_queue_notification_count(PDO $pdo): int
{
    try {
        return admin_notification_unread_count($pdo);
    } catch (Throwable $exception) {
        error_log("admin_queue_notification_count error: " . $exception->getMessage());
        return 0;
    }
}

function admin_queue_notification_link(): string
{
    return function_exists("admin_url") ? admin_url("/pages/admin/admin_notifications.php") : "/pages/admin/admin_notifications.php";
}
