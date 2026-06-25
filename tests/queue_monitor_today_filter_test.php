<?php

$failures = [];

function qm_today_filter_assert(bool $condition, string $message): void {
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function qm_today_filter_source(string $path): string {
    $source = file_get_contents(__DIR__ . "/../" . $path);
    return is_string($source) ? $source : "";
}

function qm_today_filter_function_body(string $source, string $functionName): string {
    $needle = "function " . $functionName . "(";
    $start = strpos($source, $needle);
    if ($start === false) {
        return "";
    }

    $brace = strpos($source, "{", $start);
    if ($brace === false) {
        return "";
    }

    $depth = 0;
    $length = strlen($source);
    for ($i = $brace; $i < $length; $i++) {
        if ($source[$i] === "{") {
            $depth++;
        } elseif ($source[$i] === "}") {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $i - $start + 1);
            }
        }
    }

    return "";
}

$files = [
    "pages/customer/custo_queue_monitor.php" => [
        "latest" => "qm_fetch_latest_queue_items",
        "user" => "qm_fetch_user_queue_items",
        "today" => "qm_store_today_sql",
    ],
    "pages/customer/get_latest_queues.php" => [
        "latest" => "fetch_latest_queue_items",
        "user" => "fetch_user_queue_items",
        "today" => "store_today_sql",
    ],
];

$todayPredicatePieces = [
    "q.queue_cycle_date",
    "q.created_at AT TIME ZONE 'Asia/Manila'",
    "CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila'",
];

foreach ($files as $path => $functions) {
    $source = qm_today_filter_source($path);
    qm_today_filter_assert($source !== "", "{$path} should be readable.");

    $latestBody = qm_today_filter_function_body($source, $functions["latest"]);
    qm_today_filter_assert($latestBody !== "", "{$path} should define {$functions['latest']}.");
    qm_today_filter_assert(
        str_contains($latestBody, $functions["today"] . "()"),
        "{$functions['latest']} in {$path} must use the store-day filter helper."
    );

    $todayBody = qm_today_filter_function_body($source, $functions["today"]);
    qm_today_filter_assert($todayBody !== "", "{$path} should define {$functions['today']}.");
    foreach ($todayPredicatePieces as $piece) {
        qm_today_filter_assert(
            str_contains($todayBody, $piece),
            "{$functions['today']} in {$path} must limit JC Store Currently Serving to today's Asia/Manila store date."
        );
    }

    $userBody = qm_today_filter_function_body($source, $functions["user"]);
    qm_today_filter_assert($userBody !== "", "{$path} should define {$functions['user']}.");
    qm_today_filter_assert(
        !str_contains($userBody, "CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Manila'"),
        "{$functions['user']} in {$path} must not day-limit Your Queue Updates."
    );
}

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Queue monitor today filter tests passed.\n";
