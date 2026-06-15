<?php
require_once __DIR__ . "/../config/store_availability.php";

function availability_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function availability_snapshot(string $status = "open"): array
{
    $snapshot = servitech_store_default_snapshot();
    $snapshot["store_status"] = $status;
    $snapshot["settings_available"] = true;
    $snapshot["queue_cutoff_time"] = "16:30";
    return $snapshot;
}

$timezone = new DateTimeZone("Asia/Manila");
$mondayBeforeCutoff = new DateTimeImmutable("2026-06-15 15:00:00", $timezone);
$mondayPastCutoff = new DateTimeImmutable("2026-06-15 16:31:00", $timezone);
$mondayBeforeOpening = new DateTimeImmutable("2026-06-15 07:30:00", $timezone);

$open = servitech_store_evaluate(availability_snapshot(), $mondayBeforeCutoff);
availability_assert($open["regular_queue_allowed"], "Open store before cutoff should accept regular queues.");

$pastCutoff = servitech_store_evaluate(availability_snapshot(), $mondayPastCutoff);
availability_assert(!$pastCutoff["regular_queue_allowed"] && $pastCutoff["reason_code"] === "past_cutoff", "Past cutoff should block regular queues.");

$outsideHours = servitech_store_evaluate(availability_snapshot(), $mondayBeforeOpening);
availability_assert(!$outsideHours["regular_queue_allowed"] && $outsideHours["reason_code"] === "outside_hours", "Outside shop hours should block regular queues.");

foreach (["closed", "paused", "fully_booked"] as $status) {
    $result = servitech_store_evaluate(availability_snapshot($status), $mondayBeforeCutoff);
    availability_assert(!$result["regular_queue_allowed"], ucfirst($status) . " should block regular queues.");
    availability_assert($result["document_printing_allowed"], "Document Printing should remain available while {$status}.");
}

$holidaySnapshot = availability_snapshot();
$holidaySnapshot["holidays"] = [[
    "holiday_date" => "2026-06-15",
    "title" => "Special Closure",
    "note" => "",
]];
$holiday = servitech_store_evaluate($holidaySnapshot, $mondayBeforeCutoff);
availability_assert(!$holiday["regular_queue_allowed"] && $holiday["reason_code"] === "holiday", "Holiday should block regular queues.");
availability_assert($holiday["document_printing_allowed"], "Document Printing should remain available on holidays.");

availability_assert(servitech_store_is_document_printing("printing", "Document Printing"), "Document Printing label should be exempt.");
availability_assert(servitech_store_is_document_printing("online_printorder", "Anything"), "Online print orders should be exempt.");
availability_assert(!servitech_store_is_document_printing("printing", "Xerox"), "Xerox should not be exempt.");

echo "Store availability tests passed.\n";
