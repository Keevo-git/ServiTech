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

function availability_set_hours(array $snapshot, int $day, bool $isOpen, ?string $opensAt, ?string $closesAt): array
{
    $snapshot["hours"][$day] = [
        "day_of_week" => $day,
        "is_open" => $isOpen,
        "opens_at" => $opensAt,
        "closes_at" => $closesAt,
    ];
    return $snapshot;
}

$timezone = new DateTimeZone("Asia/Manila");
$mondayBeforeCutoff = new DateTimeImmutable("2026-06-15 15:00:00", $timezone);
$mondayFivePm = new DateTimeImmutable("2026-06-15 17:00:00", $timezone);
$mondaySixPm = new DateTimeImmutable("2026-06-15 18:00:00", $timezone);
$mondayPastCutoff = new DateTimeImmutable("2026-06-15 16:31:00", $timezone);
$mondayBeforeOpening = new DateTimeImmutable("2026-06-15 07:30:00", $timezone);
$tuesdayMorning = new DateTimeImmutable("2026-06-16 10:00:00", $timezone);

$open = servitech_store_evaluate(availability_snapshot(), $mondayBeforeCutoff);
availability_assert($open["regular_queue_allowed"], "Open store before cutoff should accept regular queues.");
availability_assert($open["status_label"] === "Open", "Open store should display Open.");
availability_assert($open["can_accept_regular_queue"], "Open store should expose can_accept_regular_queue=true.");
availability_assert($open["can_accept_online_printing"], "Online Document Printing should be available while open.");

$extendedCutoff = availability_snapshot();
$extendedCutoff["queue_cutoff_time"] = "21:00";
$extendedCutoff = availability_set_hours($extendedCutoff, 1, true, "08:00", "22:00");
$openAtFive = servitech_store_evaluate($extendedCutoff, $mondayFivePm);
availability_assert($openAtFive["regular_queue_allowed"], "5:00 PM should be open when cutoff is 9:00 PM and closing is 10:00 PM.");
availability_assert($openAtFive["status_label"] === "Open", "5:00 PM with extended cutoff/hours should display Open.");

$pastNine = servitech_store_evaluate($extendedCutoff, new DateTimeImmutable("2026-06-15 21:01:00", $timezone));
availability_assert(!$pastNine["regular_queue_allowed"] && $pastNine["reason_code"] === "past_cutoff", "9:01 PM should be past cutoff when cutoff is 9:00 PM.");
availability_assert($pastNine["status_label"] === "Past Cutoff", "Past cutoff should not display Open.");

$extendedClosing = availability_snapshot();
$extendedClosing["queue_cutoff_time"] = "21:00";
$extendedClosing = availability_set_hours($extendedClosing, 1, true, "08:00", "22:00");
$openAtSix = servitech_store_evaluate($extendedClosing, $mondaySixPm);
availability_assert($openAtSix["regular_queue_allowed"], "6:00 PM should be open after closing time is extended to 10:00 PM.");

$pastCutoff = servitech_store_evaluate(availability_snapshot(), $mondayPastCutoff);
availability_assert(!$pastCutoff["regular_queue_allowed"] && $pastCutoff["reason_code"] === "past_cutoff", "Past cutoff should block regular queues.");
availability_assert($pastCutoff["status_label"] === "Past Cutoff", "Past cutoff should display Past Cutoff.");

$nextDaySnapshot = availability_snapshot();
$nextDaySnapshot["queue_cutoff_time"] = "16:30";
$nextDaySnapshot = availability_set_hours($nextDaySnapshot, 1, true, "08:00", "17:00");
$nextDaySnapshot = availability_set_hours($nextDaySnapshot, 2, true, "08:00", "17:00");
$mondayBlocked = servitech_store_evaluate($nextDaySnapshot, $mondayPastCutoff);
$tuesdayOpen = servitech_store_evaluate($nextDaySnapshot, $tuesdayMorning);
availability_assert(!$mondayBlocked["regular_queue_allowed"] && $mondayBlocked["reason_code"] === "past_cutoff", "Monday should be blocked after cutoff.");
availability_assert($tuesdayOpen["regular_queue_allowed"] && $tuesdayOpen["reason_code"] === "open", "Tuesday should reopen based on Tuesday schedule.");
availability_assert($tuesdayOpen["current_day"] === "Tuesday", "Next-day evaluation should use Tuesday's schedule.");

$outsideHours = servitech_store_evaluate(availability_snapshot(), $mondayBeforeOpening);
availability_assert(!$outsideHours["regular_queue_allowed"] && $outsideHours["reason_code"] === "outside_hours", "Outside shop hours should block regular queues.");
availability_assert($outsideHours["status_label"] === "Outside Hours", "Outside hours should display Outside Hours.");

foreach (["closed", "paused", "fully_booked"] as $status) {
    $result = servitech_store_evaluate(availability_snapshot($status), $mondayBeforeCutoff);
    availability_assert(!$result["regular_queue_allowed"], ucfirst($status) . " should block regular queues.");
    availability_assert($result["document_printing_allowed"], "Document Printing should remain available while {$status}.");
    availability_assert($result["effective_status"] === $status, ucfirst($status) . " should be the final effective status.");
    availability_assert($result["status_label"] !== "Open", ucfirst($status) . " should not display Open while blocked.");
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
availability_assert($holiday["status_label"] === "Holiday", "Holiday should display Holiday.");

$holidayBeatsManualOpen = servitech_store_evaluate($holidaySnapshot, $mondayBeforeCutoff);
availability_assert($holidayBeatsManualOpen["reason_code"] === "holiday", "Holiday should be evaluated before manual Open status.");

$holidayBeatsManualClosed = $holidaySnapshot;
$holidayBeatsManualClosed["store_status"] = "closed";
$holidayClosedResult = servitech_store_evaluate($holidayBeatsManualClosed, $mondayBeforeCutoff);
availability_assert($holidayClosedResult["reason_code"] === "holiday", "Holiday should be evaluated before manual Closed status.");

availability_assert(servitech_store_is_document_printing("printing", "Document Printing"), "Document Printing label should be exempt.");
availability_assert(servitech_store_is_document_printing("online_printorder", "Anything"), "Online print orders should be exempt.");
availability_assert(!servitech_store_is_document_printing("printing", "Xerox"), "Xerox should not be exempt.");

echo "Store availability tests passed.\n";
