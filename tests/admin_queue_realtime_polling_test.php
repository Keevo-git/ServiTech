<?php

$failures = [];

function queue_polling_assert(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function queue_polling_source(string $path): string
{
    $source = file_get_contents(__DIR__ . "/../" . $path);
    return is_string($source) ? $source : "";
}

$poller = queue_polling_source("pages/admin/queue_list/realtime-polling.js");
$queueUi = queue_polling_source("pages/admin/queue_list/queueL.js");
$messageModal = queue_polling_source("pages/admin/queue_list/_queue_message_modal.php");
$snapshot = queue_polling_source("pages/admin/_includes/admin_realtime_snapshot.php");
$helpers = queue_polling_source("pages/admin/queue_list/_queue_ui_helpers.php");

queue_polling_assert(str_contains($poller, "const POLL_INTERVAL = 5000"), "Queue polling must use a responsible five-second interval.");
queue_polling_assert(str_contains($poller, "document.hidden") && str_contains($poller, 'cache: "no-store"'), "Hidden pages must not poll and snapshots must bypass browser caches.");
queue_polling_assert(str_contains($poller, "requestInFlight") && str_contains($poller, "if (!scope || requestInFlight"), "Overlapping queue poll requests must be prevented.");
queue_polling_assert(str_contains($poller, "syncQueueTable(data.table_html)"), "Queue scopes must synchronize table rows in place.");
queue_polling_assert(str_contains($poller, "incomingById = new Map()") && str_contains($poller, "existingById = new Map"), "Queue rows must be reconciled by stable record ID to prevent duplicates.");
queue_polling_assert(str_contains($poller, "!incomingById.has(id)") && str_contains($poller, "row.remove()"), "Records absent from the current server view must be removed.");
queue_polling_assert(str_contains($poller, "captureRowState") && str_contains($poller, "restoreRowState"), "Checkbox and row selection state must survive row updates.");
queue_polling_assert(str_contains($poller, "protectedQueueIds") && str_contains($poller, "queueDetailsOverlay") && str_contains($poller, "queueMessageModal"), "Open Queue Management dialogs must protect their active record.");
queue_polling_assert(str_contains($poller, 'window.addEventListener("pagehide"') && str_contains($poller, 'window.addEventListener("pageshow"'), "Polling must stop during navigation and resume after back-forward cache restoration.");

queue_polling_assert(str_contains($queueUi, 'table.addEventListener("servitech:queue-table-updated", applyFilters)'), "Active Queue Management filters must be reapplied after synchronization.");
queue_polling_assert(str_contains($queueUi, 'const rows = Array.from(tbody.querySelectorAll(".queue-data-row"))'), "Filtering must discover newly inserted queue rows dynamically.");
queue_polling_assert(str_contains($queueUi, 'event.target.closest(".queue-view-btn")'), "New queue rows must retain delegated View behavior.");
queue_polling_assert(str_contains($messageModal, 'event.target.closest(".btn-message")'), "New queue rows must retain delegated Message behavior.");

queue_polling_assert(str_contains($snapshot, '"table_html" => $tableHtml') && str_contains($snapshot, "queue_ui_render_table_rows"), "The authenticated snapshot must return canonical queue row markup.");
queue_polling_assert(str_contains($snapshot, "ORDER BY q.created_at ASC, q.id ASC"), "Polled queue rows must retain deterministic Queue Management ordering.");
queue_polling_assert(str_contains($helpers, 'data-queue-record-id') && str_contains($helpers, "queue_ui_render_table_rows"), "Initial and polled rows must share the same stable-ID renderer.");
queue_polling_assert(!str_contains($snapshot, '"notification_count"'), "Queue polling must remain independent from admin notification polling.");

foreach (["printing", "repair", "installation"] as $page) {
    $source = queue_polling_source("pages/admin/queue_list/{$page}.php");
    queue_polling_assert(str_contains($source, "queue_ui_render_table_rows"), "{$page}.php must use the shared live-row renderer.");
    queue_polling_assert(str_contains($source, "20260624-queue-inplace-sync"), "{$page}.php must load the current in-place poller asset.");
    queue_polling_assert(str_contains($source, 'event.target.closest(".queue-data-row [data-action]")'), "{$page}.php must delegate actions for newly inserted rows.");
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Admin Queue Management realtime polling tests passed.\n";
