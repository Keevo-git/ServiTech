<?php
require_once __DIR__ . "/app.php";

function servitech_store_default_hours(): array
{
    $hours = [];
    for ($day = 0; $day <= 6; $day++) {
        $hours[$day] = [
            "day_of_week" => $day,
            "is_open" => $day !== 0,
            "opens_at" => $day === 0 ? null : "08:00",
            "closes_at" => $day === 0 ? null : "17:00",
        ];
    }
    return $hours;
}

function servitech_store_default_snapshot(): array
{
    return [
        "store_status" => "open",
        "queue_cutoff_time" => "16:30",
        "hours" => servitech_store_default_hours(),
        "holidays" => [],
        "settings_available" => false,
    ];
}

function servitech_store_normalize_time(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === "") {
        return null;
    }
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $value, $matches)) {
        return null;
    }
    return $matches[1] . ":" . $matches[2];
}

function servitech_store_fetch_snapshot(PDO $pdo, int $upcomingLimit = 8): array
{
    $snapshot = servitech_store_default_snapshot();

    try {
        $settings = $pdo->query("
            SELECT store_status, queue_cutoff_time::text AS queue_cutoff_time
            FROM store_availability_settings
            WHERE id = 1
        ")->fetch(PDO::FETCH_ASSOC);

        if (is_array($settings)) {
            $status = strtolower(trim((string)($settings["store_status"] ?? "")));
            if (in_array($status, ["open", "closed", "paused", "fully_booked"], true)) {
                $snapshot["store_status"] = $status;
            }
            $cutoff = servitech_store_normalize_time((string)($settings["queue_cutoff_time"] ?? ""));
            if ($cutoff !== null) {
                $snapshot["queue_cutoff_time"] = $cutoff;
            }
        }

        $hoursRows = $pdo->query("
            SELECT day_of_week, is_open, opens_at::text AS opens_at, closes_at::text AS closes_at
            FROM store_hours
            ORDER BY day_of_week
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($hoursRows as $row) {
            $day = (int)($row["day_of_week"] ?? -1);
            if ($day < 0 || $day > 6) {
                continue;
            }
            $snapshot["hours"][$day] = [
                "day_of_week" => $day,
                "is_open" => filter_var($row["is_open"] ?? false, FILTER_VALIDATE_BOOLEAN),
                "opens_at" => servitech_store_normalize_time($row["opens_at"] ?? null),
                "closes_at" => servitech_store_normalize_time($row["closes_at"] ?? null),
            ];
        }

        $holidayStmt = $pdo->prepare("
            SELECT id, holiday_date::text AS holiday_date, title, note
            FROM store_holidays
            WHERE holiday_date >= (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila')::date
            ORDER BY holiday_date, id
            LIMIT :holiday_limit
        ");
        $holidayStmt->bindValue(":holiday_limit", max(1, min(50, $upcomingLimit)), PDO::PARAM_INT);
        $holidayStmt->execute();
        $snapshot["holidays"] = $holidayStmt->fetchAll(PDO::FETCH_ASSOC);
        $snapshot["settings_available"] = true;
    } catch (Throwable $exception) {
        error_log("store availability fallback: " . $exception->getMessage());
    }

    return $snapshot;
}

function servitech_store_status_label(string $status): string
{
    return [
        "open" => "Open",
        "closed" => "Closed",
        "paused" => "Paused",
        "fully_booked" => "Fully Booked",
        "holiday" => "Holiday",
        "closed_today" => "Closed Today",
        "outside_hours" => "Outside Hours",
        "past_cutoff" => "Past Cutoff",
    ][$status] ?? "Closed";
}

function servitech_store_format_time(?string $time): string
{
    $time = servitech_store_normalize_time($time);
    if ($time === null) {
        return "Not set";
    }
    $date = DateTimeImmutable::createFromFormat("!H:i", $time);
    return $date ? $date->format("g:i A") : $time;
}

function servitech_store_day_label(int $day): string
{
    return ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"][$day] ?? "Unknown";
}

function servitech_store_send_no_cache_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header("Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
}

function servitech_store_evaluate(array $snapshot, ?DateTimeImmutable $now = null): array
{
    $timezone = new DateTimeZone("Asia/Manila");
    $now = ($now ?? new DateTimeImmutable("now", $timezone))->setTimezone($timezone);
    $date = $now->format("Y-m-d");
    $day = (int)$now->format("w");
    $currentTime = $now->format("H:i");
    $configuredStatus = strtolower((string)($snapshot["store_status"] ?? "open"));
    $hours = $snapshot["hours"][$day] ?? servitech_store_default_hours()[$day];
    $cutoff = servitech_store_normalize_time($snapshot["queue_cutoff_time"] ?? null) ?? "16:30";
    $todayHoliday = null;

    foreach ((array)($snapshot["holidays"] ?? []) as $holiday) {
        if ((string)($holiday["holiday_date"] ?? "") === $date) {
            $todayHoliday = $holiday;
            break;
        }
    }

    $reasonCode = "open";
    $regularQueueAllowed = true;
    $effectiveStatus = "open";
    $opensAt = servitech_store_normalize_time($hours["opens_at"] ?? null);
    $closesAt = servitech_store_normalize_time($hours["closes_at"] ?? null);

    if ($todayHoliday !== null) {
        $regularQueueAllowed = false;
        $effectiveStatus = "holiday";
        $reasonCode = "holiday";
    } elseif ($configuredStatus !== "open") {
        $regularQueueAllowed = false;
        $effectiveStatus = $configuredStatus;
        $reasonCode = $configuredStatus;
    } elseif (empty($hours["is_open"])) {
        $regularQueueAllowed = false;
        $effectiveStatus = "closed_today";
        $reasonCode = "closed_today";
    } else {
        if ($opensAt === null || $closesAt === null || $currentTime < $opensAt || $currentTime >= $closesAt) {
            $regularQueueAllowed = false;
            $effectiveStatus = "outside_hours";
            $reasonCode = "outside_hours";
        } elseif ($currentTime > $cutoff) {
            $regularQueueAllowed = false;
            $effectiveStatus = "past_cutoff";
            $reasonCode = "past_cutoff";
        }
    }

    $messages = [
        "open" => "We are open today. Queue requests are accepted until " . servitech_store_format_time($cutoff) . ".",
        "closed" => "Regular queue requests are unavailable right now. Online Document Printing is still available.",
        "paused" => "Queue requests are temporarily paused. Online Document Printing is still available.",
        "fully_booked" => "We are fully booked today. Online Document Printing is still available.",
        "holiday" => "We are closed today" . ($todayHoliday ? " for " . trim((string)$todayHoliday["title"]) : "") . ". Online Document Printing is still available.",
        "closed_today" => "We are closed today. Online Document Printing is still available.",
        "outside_hours" => "Regular queue requests are outside today's shop hours. Online Document Printing is still available.",
        "past_cutoff" => "Regular queue is closed for today. Online Document Printing is still available.",
    ];

    $todayHours = empty($hours["is_open"])
        ? "Closed"
        : servitech_store_format_time($hours["opens_at"] ?? null) . " - " . servitech_store_format_time($hours["closes_at"] ?? null);

    return [
        "configured_status" => $configuredStatus,
        "effective_status" => $effectiveStatus,
        "status_label" => servitech_store_status_label($effectiveStatus),
        "is_open" => $regularQueueAllowed,
        "regular_queue_allowed" => $regularQueueAllowed,
        "can_accept_regular_queue" => $regularQueueAllowed,
        "document_printing_allowed" => true,
        "can_accept_online_printing" => true,
        "reason_code" => $reasonCode,
        "reason" => $reasonCode,
        "message" => $messages[$reasonCode] ?? $messages["closed"],
        "customer_message" => $messages[$reasonCode] ?? $messages["closed"],
        "today_hours" => $todayHours,
        "today_hours_raw" => [
            "day_of_week" => $day,
            "day_label" => servitech_store_day_label($day),
            "is_open" => !empty($hours["is_open"]),
            "opens_at" => $opensAt,
            "closes_at" => $closesAt,
        ],
        "current_date" => $date,
        "current_day" => servitech_store_day_label($day),
        "current_time" => $currentTime,
        "current_datetime" => $now->format(DateTimeInterface::ATOM),
        "shop_timezone" => $timezone->getName(),
        "queue_cutoff_time" => $cutoff,
        "cutoff_time" => $cutoff,
        "queue_cutoff_label" => servitech_store_format_time($cutoff),
        "today_holiday" => $todayHoliday,
        "upcoming_holidays" => (array)($snapshot["holidays"] ?? []),
        "settings_available" => !empty($snapshot["settings_available"]),
    ];
}

function servitech_store_current_availability(PDO $pdo, ?DateTimeImmutable $now = null): array
{
    return servitech_store_evaluate(servitech_store_fetch_snapshot($pdo), $now);
}

function servitech_store_is_document_printing(string $category, string $serviceLabel): bool
{
    $category = strtolower(trim($category));
    $label = strtolower(trim((string)preg_replace('/\s+/', ' ', $serviceLabel)));
    return $category === "online_printorder"
        || $label === "document printing"
        || $label === "online document printing"
        || $label === "online print order";
}

function servitech_store_assert_queue_available(PDO $pdo, string $category, string $serviceLabel): void
{
    if (servitech_store_is_document_printing($category, $serviceLabel)) {
        return;
    }

    $availability = servitech_store_current_availability($pdo);
    if (!$availability["regular_queue_allowed"]) {
        throw new DomainException($availability["message"]);
    }
}
