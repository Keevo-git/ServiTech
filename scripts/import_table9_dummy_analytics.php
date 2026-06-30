<?php
declare(strict_types=1);

const TABLE9_DEFAULT_WORKBOOK = "/../documents/servitech_table8_table9_dummy_raw_data (1) - Copy.xlsx";

function table9_usage(): void
{
    echo "Usage: php scripts/import_table9_dummy_analytics.php [--workbook=path] [--dry-run]\n";
}

function table9_parse_args(array $argv): array
{
    $args = [
        "workbook" => realpath(__DIR__ . TABLE9_DEFAULT_WORKBOOK) ?: (__DIR__ . TABLE9_DEFAULT_WORKBOOK),
        "dry_run" => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === "--help" || $arg === "-h") {
            table9_usage();
            exit(0);
        }
        if ($arg === "--dry-run") {
            $args["dry_run"] = true;
            continue;
        }
        if (str_starts_with($arg, "--workbook=")) {
            $args["workbook"] = substr($arg, strlen("--workbook="));
            continue;
        }
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        table9_usage();
        exit(1);
    }

    return $args;
}

function table9_cell_column_index(string $cellRef): int
{
    preg_match('/^[A-Z]+/', strtoupper($cellRef), $matches);
    $letters = $matches[0] ?? "";
    $index = 0;
    foreach (str_split($letters) as $letter) {
        $index = ($index * 26) + (ord($letter) - 64);
    }
    return max(0, $index - 1);
}

function table9_shared_strings(ZipArchive $zip): array
{
    $xml = $zip->getFromName("xl/sharedStrings.xml");
    if ($xml === false || trim($xml) === "") {
        return [];
    }

    $document = simplexml_load_string($xml);
    if (!$document) {
        return [];
    }

    $strings = [];
    foreach ($document->si as $item) {
        if (isset($item->t)) {
            $strings[] = (string)$item->t;
            continue;
        }

        $text = "";
        foreach ($item->r as $run) {
            $text .= (string)($run->t ?? "");
        }
        $strings[] = $text;
    }

    return $strings;
}

function table9_sheet_paths(ZipArchive $zip): array
{
    $workbookXml = $zip->getFromName("xl/workbook.xml");
    $relsXml = $zip->getFromName("xl/_rels/workbook.xml.rels");
    if ($workbookXml === false || $relsXml === false) {
        throw new RuntimeException("Workbook relationships are missing.");
    }

    $workbook = simplexml_load_string($workbookXml);
    $rels = simplexml_load_string($relsXml);
    if (!$workbook || !$rels) {
        throw new RuntimeException("Workbook XML could not be parsed.");
    }

    $relMap = [];
    foreach ($rels->Relationship as $rel) {
        $attrs = $rel->attributes();
        $target = ltrim((string)$attrs["Target"], "/");
        $relMap[(string)$attrs["Id"]] = str_starts_with($target, "xl/") ? $target : "xl/" . $target;
    }

    $workbook->registerXPathNamespace("main", "http://schemas.openxmlformats.org/spreadsheetml/2006/main");
    $paths = [];
    foreach ($workbook->xpath("//main:sheet") ?: [] as $sheet) {
        $attrs = $sheet->attributes();
        $rAttrs = $sheet->attributes("http://schemas.openxmlformats.org/officeDocument/2006/relationships");
        $name = (string)$attrs["name"];
        $relId = (string)$rAttrs["id"];
        if ($name !== "" && isset($relMap[$relId])) {
            $paths[$name] = $relMap[$relId];
        }
    }

    return $paths;
}

function table9_cell_value(SimpleXMLElement $cell, array $sharedStrings): string
{
    $attrs = $cell->attributes();
    $type = (string)($attrs["t"] ?? "");
    $value = (string)($cell->v ?? "");

    if ($type === "s") {
        return trim((string)($sharedStrings[(int)$value] ?? ""));
    }
    if ($type === "inlineStr") {
        return trim((string)($cell->is->t ?? ""));
    }
    if ($type === "b") {
        return $value === "1" ? "1" : "0";
    }

    return trim($value);
}

function table9_read_sheet(ZipArchive $zip, string $sheetPath, array $sharedStrings): array
{
    $xml = $zip->getFromName($sheetPath);
    if ($xml === false) {
        throw new RuntimeException("Sheet not found: {$sheetPath}");
    }

    $sheet = simplexml_load_string($xml);
    if (!$sheet) {
        throw new RuntimeException("Sheet XML could not be parsed: {$sheetPath}");
    }

    $rows = [];
    $headers = [];
    $rowIndex = 0;
    foreach ($sheet->sheetData->row as $row) {
        $values = [];
        foreach ($row->c as $cell) {
            $attrs = $cell->attributes();
            $column = table9_cell_column_index((string)$attrs["r"]);
            $values[$column] = table9_cell_value($cell, $sharedStrings);
        }

        if ($rowIndex === 0) {
            ksort($values);
            $headers = array_map(static fn($value): string => trim((string)$value), $values);
            $rowIndex++;
            continue;
        }

        $assoc = [];
        foreach ($headers as $column => $header) {
            if ($header === "") {
                continue;
            }
            $assoc[$header] = trim((string)($values[$column] ?? ""));
        }

        if (implode("", $assoc) !== "") {
            $rows[] = $assoc;
        }
        $rowIndex++;
    }

    return $rows;
}

function table9_load_workbook(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("Workbook not found: {$path}");
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException("Unable to open workbook: {$path}");
    }

    $paths = table9_sheet_paths($zip);
    foreach (["Table9_After_Orders", "Table9_Status_Events"] as $requiredSheet) {
        if (!isset($paths[$requiredSheet])) {
            throw new RuntimeException("Required sheet is missing: {$requiredSheet}");
        }
    }

    $shared = table9_shared_strings($zip);
    $rows = [
        "after_orders" => table9_read_sheet($zip, $paths["Table9_After_Orders"], $shared),
        "status_events" => table9_read_sheet($zip, $paths["Table9_Status_Events"], $shared),
    ];
    $zip->close();

    return $rows;
}

function table9_excel_datetime(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === "") {
        return null;
    }

    $timezone = new DateTimeZone("Asia/Manila");
    if (is_numeric($value)) {
        $seconds = (int)round(((float)$value) * 86400);
        $base = new DateTimeImmutable("1899-12-30 00:00:00", $timezone);
        return $base->modify("+{$seconds} seconds")->format("Y-m-d H:i:sP");
    }

    try {
        return (new DateTimeImmutable($value, $timezone))->format("Y-m-d H:i:sP");
    } catch (Throwable) {
        return null;
    }
}

function table9_decimal(?string $value): ?float
{
    $value = trim((string)$value);
    return $value !== "" && is_numeric($value) ? round((float)$value, 2) : null;
}

function table9_int(?string $value): int
{
    $value = trim((string)$value);
    return $value !== "" && is_numeric($value) ? (int)$value : 0;
}

function table9_normalize_status(string $status): string
{
    return servitech_queue_normalize_status($status);
}

function table9_payment_method(string $method): string
{
    $method = strtolower(trim($method));
    return in_array($method, ["cash", "gcash"], true) ? $method : "";
}

function table9_service_category(string $serviceType): string
{
    $serviceType = strtolower(trim($serviceType));
    if (str_contains($serviceType, "repair")) {
        return "repair";
    }
    if (str_contains($serviceType, "install")) {
        return "installation";
    }
    return "printing";
}

function table9_schema_ready(PDO $pdo): bool
{
    $queueColumns = [
        "request_created_at", "pending_at", "approved_at", "ongoing_at",
        "for_pickup_at", "done_at", "cancelled_at", "request_source",
    ];
    $placeholders = implode(",", array_fill(0, count($queueColumns), "?"));
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT column_name)
        FROM information_schema.columns
        WHERE table_schema = ANY(current_schemas(false))
          AND table_name = 'queues'
          AND column_name IN ({$placeholders})
    ");
    $stmt->execute($queueColumns);
    if ((int)$stmt->fetchColumn() !== count($queueColumns)) {
        return false;
    }

    $stmt = $pdo->query("SELECT to_regclass('public.queue_status_events') IS NOT NULL");
    return (bool)$stmt->fetchColumn();
}

function table9_summary(): array
{
    return [
        "after_order_rows_read" => 0,
        "status_event_rows_read" => 0,
        "queues_matched" => 0,
        "queues_updated" => 0,
        "payments_inserted_or_updated" => 0,
        "status_transition_events_inserted_or_updated" => 0,
        "missing_queue_ids" => [],
        "errors" => [],
    ];
}

function table9_find_queue(PDOStatement $stmt, string $queueCode): ?array
{
    $stmt->execute([":queue_code" => $queueCode]);
    $queue = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($queue) ? $queue : null;
}

function table9_update_payment(PDO $pdo, array $queue, string $paymentMethod, string $referenceNumber): bool
{
    if ($paymentMethod === "") {
        return false;
    }

    $paymentStatus = table9_normalize_status((string)($queue["status"] ?? "")) === "DONE" ? "PAID" : "PENDING";
    $existing = $pdo->prepare("SELECT id FROM payments WHERE queue_id = :queue_id ORDER BY id DESC LIMIT 1");
    $existing->execute([":queue_id" => (int)$queue["id"]]);
    $paymentId = (int)($existing->fetchColumn() ?: 0);

    if ($paymentId > 0) {
        $stmt = $pdo->prepare("
            UPDATE payments
            SET payment_method = :payment_method,
                reference_number = :reference_number,
                amount = COALESCE(NULLIF(amount, 0), :amount),
                status = :status,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ":payment_method" => $paymentMethod,
            ":reference_number" => $referenceNumber !== "" ? $referenceNumber : null,
            ":amount" => $queue["price"] !== null ? (float)$queue["price"] : 0,
            ":status" => $paymentStatus,
            ":id" => $paymentId,
        ]);
        return true;
    }

    $stmt = $pdo->prepare("
        INSERT INTO payments (queue_id, user_id, amount, payment_method, reference_number, status, created_at, updated_at)
        VALUES (:queue_id, :user_id, :amount, :payment_method, :reference_number, :status, NOW(), NOW())
    ");
    $stmt->execute([
        ":queue_id" => (int)$queue["id"],
        ":user_id" => (int)$queue["user_id"],
        ":amount" => $queue["price"] !== null ? (float)$queue["price"] : 0,
        ":payment_method" => $paymentMethod,
        ":reference_number" => $referenceNumber !== "" ? $referenceNumber : null,
        ":status" => $paymentStatus,
    ]);
    return true;
}

function table9_import_after_orders(PDO $pdo, array $rows, array &$summary): array
{
    $findQueue = $pdo->prepare("
        SELECT id, user_id, queue_code, category, status, details, price
        FROM queues
        WHERE queue_code = :queue_code
        LIMIT 1
        FOR UPDATE
    ");
    $updateQueue = $pdo->prepare("
        UPDATE queues
        SET category = :category,
            status = :status,
            lifecycle_stage = CASE WHEN :is_final = 1 THEN 'ORDER' ELSE COALESCE(lifecycle_stage, 'QUEUE') END,
            request_source = 'servitech_assisted',
            request_created_at = :request_created_at,
            pending_at = :pending_at,
            approved_at = :approved_at,
            ongoing_at = :ongoing_at,
            for_pickup_at = :for_pickup_at,
            done_at = :done_at,
            cancelled_at = CASE WHEN :cancelled_status = 'CANCELLED' THEN COALESCE(:cancelled_at, cancelled_at) ELSE cancelled_at END,
            created_at = COALESCE(:request_created_at_for_created, created_at),
            queue_cycle_date = COALESCE((CAST(:request_created_at_for_cycle AS timestamptz) AT TIME ZONE 'Asia/Manila')::date, queue_cycle_date),
            completed_at = CASE WHEN :done_status = 'DONE' THEN COALESCE(:done_at_for_completed, completed_at) ELSE completed_at END,
            closed_at = CASE WHEN :closed_status IN ('DONE', 'CANCELLED') THEN COALESCE(:done_at_for_closed, closed_at) ELSE closed_at END,
            details = COALESCE(details, '{}'::jsonb) || CAST(:analytics_details AS jsonb),
            updated_at = NOW()
        WHERE id = :id
    ");

    $queueMap = [];
    $previousStatusByQueue = [];
    foreach ($rows as $row) {
        $summary["after_order_rows_read"]++;
        $queueCode = trim((string)($row["Queue ID"] ?? ""));
        if ($queueCode === "") {
            continue;
        }

        $queue = table9_find_queue($findQueue, $queueCode);
        if ($queue === null) {
            $summary["missing_queue_ids"][$queueCode] = $queueCode;
            continue;
        }

        $serviceType = trim((string)($row["Type of Request"] ?? ""));
        $paymentMethod = table9_payment_method((string)($row["Payment Method"] ?? ""));
        $referenceNumber = trim((string)($row["GCash Reference No."] ?? ""));
        $status = table9_normalize_status((string)($row["Current Status"] ?: ($row["Final Status"] ?? "DONE")));
        $doneAt = table9_excel_datetime($row["Done At"] ?? null);
        $requestCreatedAt = table9_excel_datetime($row["Request Created At"] ?? null);
        $analyticsDetails = [
            "analytics_import_source" => "table9_dummy",
            "analytics_transaction_no" => trim((string)($row["Transaction No."] ?? "")),
            "customer_name_snapshot" => trim((string)($row["Customer Name"] ?? "")),
            "type_of_request" => $serviceType,
            "service_name_snapshot" => $serviceType,
            "service_label" => $serviceType,
            "payment_method" => $paymentMethod,
            "reference_number" => $referenceNumber,
            "completion_route" => trim((string)($row["Completion Route"] ?? "")),
            "workflow_handling_minutes_imported" => table9_decimal($row["Workflow Handling Time (min)"] ?? null),
            "queue_waiting_minutes_imported" => table9_decimal($row["Queue Waiting Time (min)"] ?? null),
            "service_processing_minutes_imported" => table9_decimal($row["Service Processing Time (min)"] ?? null),
            "pending_status_minutes_imported" => table9_decimal($row["Pending Status Min"] ?? null),
            "approved_status_minutes_imported" => table9_decimal($row["Approved Status Min"] ?? null),
            "ongoing_status_minutes_imported" => table9_decimal($row["Ongoing Status Min"] ?? null),
            "for_pickup_status_minutes_imported" => table9_decimal($row["For Pick-up Status Min"] ?? null),
            "completion_flag" => table9_int($row["Completion Flag"] ?? null),
            "system_status_flow" => trim((string)($row["System Status Flow"] ?? "")),
            "status_transition_history" => trim((string)($row["Status Transition History"] ?? "")),
            "analytics_notes" => trim((string)($row["Notes"] ?? "")),
        ];

        $updateQueue->execute([
            ":category" => table9_service_category($serviceType),
            ":status" => $status,
            ":is_final" => in_array($status, ["DONE", "CANCELLED"], true) ? 1 : 0,
            ":request_created_at" => $requestCreatedAt,
            ":pending_at" => table9_excel_datetime($row["Pending At"] ?? null),
            ":approved_at" => table9_excel_datetime($row["Approved At"] ?? null),
            ":ongoing_at" => table9_excel_datetime($row["Ongoing At"] ?? null),
            ":for_pickup_at" => table9_excel_datetime($row["For Pick-up At"] ?? null),
            ":done_at" => $doneAt,
            ":cancelled_status" => $status,
            ":cancelled_at" => $status === "CANCELLED" ? $doneAt : null,
            ":request_created_at_for_created" => $requestCreatedAt,
            ":request_created_at_for_cycle" => $requestCreatedAt,
            ":done_status" => $status,
            ":done_at_for_completed" => $doneAt,
            ":closed_status" => $status,
            ":done_at_for_closed" => $doneAt,
            ":analytics_details" => json_encode($analyticsDetails, JSON_UNESCAPED_SLASHES),
            ":id" => (int)$queue["id"],
        ]);

        $summary["queues_updated"]++;
        $queue["status"] = $status;
        $queue["category"] = table9_service_category($serviceType);
        $queueMap[$queueCode] = [
            "id" => (int)$queue["id"],
            "user_id" => (int)$queue["user_id"],
            "queue_code" => $queueCode,
            "customer_name" => $analyticsDetails["customer_name_snapshot"],
            "service_type" => $serviceType,
            "payment_method" => $paymentMethod,
            "price" => $queue["price"],
            "status" => $status,
        ];

        if (table9_update_payment($pdo, $queueMap[$queueCode], $paymentMethod, $referenceNumber)) {
            $summary["payments_inserted_or_updated"]++;
        }
    }

    $summary["queues_matched"] = count($queueMap);
    return $queueMap;
}

function table9_import_status_events(PDO $pdo, array $rows, array $queueMap, array &$summary): void
{
    $findQueue = $pdo->prepare("
        SELECT q.id, q.user_id, q.queue_code, q.price, q.status,
               COALESCE(NULLIF(TRIM(q.details->>'customer_name_snapshot'), ''), NULLIF(TRIM(u.fullname), ''), '') AS customer_name,
               COALESCE(NULLIF(TRIM(q.details->>'type_of_request'), ''), NULLIF(TRIM(q.details->>'service_name_snapshot'), ''), q.category, '') AS service_type
        FROM queues q
        LEFT JOIN users u ON u.id = q.user_id
        WHERE q.queue_code = :queue_code
        LIMIT 1
    ");
    $upsert = $pdo->prepare("
        INSERT INTO queue_status_events (
            queue_id, queue_code, customer_name_snapshot, service_type, payment_method,
            transition_no, previous_status, status, entered_at, exited_at, duration_minutes, next_status, updated_by, updated_by_name, remarks,
            created_at, updated_at
        )
        VALUES (
            :queue_id, :queue_code, :customer_name_snapshot, :service_type, :payment_method,
            :transition_no, :previous_status, :status, :entered_at, :exited_at, :duration_minutes, :next_status, NULL, '', :remarks,
            NOW(), NOW()
        )
        ON CONFLICT (queue_id, transition_no, status, entered_at)
        DO UPDATE SET
            queue_code = EXCLUDED.queue_code,
            customer_name_snapshot = EXCLUDED.customer_name_snapshot,
            service_type = EXCLUDED.service_type,
            payment_method = EXCLUDED.payment_method,
            previous_status = EXCLUDED.previous_status,
            exited_at = EXCLUDED.exited_at,
            duration_minutes = EXCLUDED.duration_minutes,
            next_status = EXCLUDED.next_status,
            remarks = EXCLUDED.remarks,
            updated_at = NOW()
    ");

    foreach ($rows as $row) {
        $summary["status_event_rows_read"]++;
        $queueCode = trim((string)($row["Queue ID"] ?? ""));
        if ($queueCode === "") {
            continue;
        }

        $queue = $queueMap[$queueCode] ?? null;
        if ($queue === null) {
            $queue = table9_find_queue($findQueue, $queueCode);
        }
        if ($queue === null) {
            $summary["missing_queue_ids"][$queueCode] = $queueCode;
            continue;
        }

        $enteredAt = table9_excel_datetime($row["Entered At"] ?? null);
        if ($enteredAt === null) {
            continue;
        }

        $status = table9_normalize_status((string)($row["Status"] ?? ""));
        $previousStatus = $previousStatusByQueue[$queueCode] ?? null;
        $upsert->execute([
            ":queue_id" => (int)$queue["id"],
            ":queue_code" => $queueCode,
            ":customer_name_snapshot" => trim((string)($row["Customer Name"] ?? ($queue["customer_name"] ?? ""))),
            ":service_type" => trim((string)($row["Type of Request"] ?? ($queue["service_type"] ?? ""))),
            ":payment_method" => table9_payment_method((string)($row["Payment Method"] ?? ($queue["payment_method"] ?? ""))),
            ":transition_no" => table9_int($row["Transition No."] ?? null),
            ":previous_status" => $previousStatus,
            ":status" => $status,
            ":entered_at" => $enteredAt,
            ":exited_at" => table9_excel_datetime($row["Exited At"] ?? null),
            ":duration_minutes" => table9_decimal($row["Duration Min"] ?? null),
            ":next_status" => ($row["Next Status"] ?? "") !== "" ? table9_normalize_status((string)$row["Next Status"]) : null,
            ":remarks" => trim((string)($row["Remarks"] ?? "")),
        ]);
        $previousStatusByQueue[$queueCode] = $status;
        $summary["status_transition_events_inserted_or_updated"]++;
    }
}

$args = table9_parse_args($argv);
$summary = table9_summary();

try {
    require_once __DIR__ . "/../config/db.php";
    require_once __DIR__ . "/../api/queue_state_machine.php";

    $pdo = ($GLOBALS["pdo"] ?? null) instanceof PDO ? $GLOBALS["pdo"] : servitech_db_connect_privileged();
    if (!table9_schema_ready($pdo)) {
        throw new RuntimeException("Analytics import schema is not installed. Run database/migrations/20260630_add_table9_analytics_import.sql first.");
    }

    $workbook = table9_load_workbook((string)$args["workbook"]);
    $pdo->beginTransaction();

    $queueMap = table9_import_after_orders($pdo, $workbook["after_orders"], $summary);
    table9_import_status_events($pdo, $workbook["status_events"], $queueMap, $summary);

    if ($args["dry_run"]) {
        $pdo->rollBack();
    } else {
        $pdo->commit();
    }
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $summary["errors"][] = $exception->getMessage();
}

$summary["missing_queue_ids"] = array_values($summary["missing_queue_ids"]);

echo "Table 9 Dummy Analytics Import Summary\n";
echo "Mode: " . ($args["dry_run"] ? "DRY RUN (rolled back)" : "COMMITTED") . "\n";
echo "Workbook: " . $args["workbook"] . "\n";
echo "Total rows read: " . ($summary["after_order_rows_read"] + $summary["status_event_rows_read"]) . "\n";
echo "After-order rows read: " . $summary["after_order_rows_read"] . "\n";
echo "Status event rows read: " . $summary["status_event_rows_read"] . "\n";
echo "Total queues matched: " . $summary["queues_matched"] . "\n";
echo "Total queues updated: " . $summary["queues_updated"] . "\n";
echo "Payments inserted/updated: " . $summary["payments_inserted_or_updated"] . "\n";
echo "Status transition events inserted/updated: " . $summary["status_transition_events_inserted_or_updated"] . "\n";
echo "Missing queue IDs: " . ($summary["missing_queue_ids"] ? implode(", ", $summary["missing_queue_ids"]) : "None") . "\n";
echo "Errors: " . ($summary["errors"] ? implode(" | ", $summary["errors"]) : "None") . "\n";

exit($summary["errors"] ? 1 : 0);
