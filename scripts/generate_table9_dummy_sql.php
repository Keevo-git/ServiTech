<?php
declare(strict_types=1);

$workbookPath = realpath(__DIR__ . "/../documents/servitech_table8_table9_dummy_raw_data (1) - Copy.xlsx");
$outputPath = __DIR__ . "/../database/seeds/table9_dummy_analytics_import.sql";
if (!$workbookPath || !is_file($workbookPath)) {
    fwrite(STDERR, "Workbook not found.\n");
    exit(1);
}

function g_col_index(string $cellRef): int {
    preg_match('/^[A-Z]+/', strtoupper($cellRef), $matches);
    $letters = $matches[0] ?? "";
    $index = 0;
    foreach (str_split($letters) as $letter) {
        $index = ($index * 26) + (ord($letter) - 64);
    }
    return max(0, $index - 1);
}

function g_shared_strings(ZipArchive $zip): array {
    $xml = $zip->getFromName("xl/sharedStrings.xml");
    if ($xml === false || trim($xml) === "") return [];
    $document = simplexml_load_string($xml);
    if (!$document) return [];
    $strings = [];
    foreach ($document->si as $item) {
        if (isset($item->t)) {
            $strings[] = (string)$item->t;
            continue;
        }
        $text = "";
        foreach ($item->r as $run) $text .= (string)($run->t ?? "");
        $strings[] = $text;
    }
    return $strings;
}

function g_sheet_paths(ZipArchive $zip): array {
    $workbook = simplexml_load_string((string)$zip->getFromName("xl/workbook.xml"));
    $rels = simplexml_load_string((string)$zip->getFromName("xl/_rels/workbook.xml.rels"));
    if (!$workbook || !$rels) throw new RuntimeException("Workbook XML could not be parsed.");
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
        $paths[(string)$attrs["name"]] = $relMap[(string)$rAttrs["id"]] ?? "";
    }
    return $paths;
}

function g_cell_value(SimpleXMLElement $cell, array $sharedStrings): string {
    $attrs = $cell->attributes();
    $type = (string)($attrs["t"] ?? "");
    $value = (string)($cell->v ?? "");
    if ($type === "s") return trim((string)($sharedStrings[(int)$value] ?? ""));
    if ($type === "inlineStr") return trim((string)($cell->is->t ?? ""));
    if ($type === "b") return $value === "1" ? "1" : "0";
    return trim($value);
}

function g_read_sheet(ZipArchive $zip, string $sheetPath, array $sharedStrings): array {
    $sheet = simplexml_load_string((string)$zip->getFromName($sheetPath));
    if (!$sheet) throw new RuntimeException("Sheet XML could not be parsed: {$sheetPath}");
    $rows = [];
    $headers = [];
    $rowIndex = 0;
    foreach ($sheet->sheetData->row as $row) {
        $values = [];
        foreach ($row->c as $cell) {
            $attrs = $cell->attributes();
            $values[g_col_index((string)$attrs["r"])] = g_cell_value($cell, $sharedStrings);
        }
        if ($rowIndex === 0) {
            ksort($values);
            $headers = array_map(static fn($value): string => trim((string)$value), $values);
            $rowIndex++;
            continue;
        }
        $assoc = [];
        foreach ($headers as $column => $header) {
            if ($header !== "") $assoc[$header] = trim((string)($values[$column] ?? ""));
        }
        if (implode("", $assoc) !== "") $rows[] = $assoc;
        $rowIndex++;
    }
    return $rows;
}

function g_excel_datetime(?string $value): ?string {
    $value = trim((string)$value);
    if ($value === "") return null;
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

function g_status(string $status): string {
    $status = strtoupper(trim((string)preg_replace('/[\s_]+/', ' ', $status)));
    return match ($status) {
        "", "PENDING PAYMENT" => "PENDING",
        "FOR PICK UP", "FOR PICKUP" => "FOR PICK-UP",
        "COMPLETED" => "DONE",
        "CANCELED", "CANCEL" => "CANCELLED",
        default => $status,
    };
}

function g_payment(string $method): string {
    $method = strtolower(trim($method));
    return in_array($method, ["cash", "gcash"], true) ? $method : "";
}

function g_number(?string $value): string {
    $value = trim((string)$value);
    return $value !== "" && is_numeric($value) ? (string)(float)$value : "NULL";
}

function g_int(?string $value): string {
    $value = trim((string)$value);
    return $value !== "" && is_numeric($value) ? (string)(int)$value : "0";
}

function g_sql(?string $value, string $cast = ""): string {
    if ($value === null || $value === "") return "NULL" . $cast;
    return "'" . str_replace("'", "''", $value) . "'" . $cast;
}

function g_tuple(array $values): string {
    return "(" . implode(", ", $values) . ")";
}

$zip = new ZipArchive();
if ($zip->open($workbookPath) !== true) {
    fwrite(STDERR, "Unable to open workbook.\n");
    exit(1);
}
$paths = g_sheet_paths($zip);
$shared = g_shared_strings($zip);
$afterRows = g_read_sheet($zip, $paths["Table9_After_Orders"], $shared);
$eventRows = g_read_sheet($zip, $paths["Table9_Status_Events"], $shared);
$zip->close();

$afterTuples = [];
foreach ($afterRows as $row) {
    $status = g_status((string)($row["Current Status"] ?: ($row["Final Status"] ?? "DONE")));
    $doneAt = g_excel_datetime($row["Done At"] ?? null);
    $afterTuples[] = g_tuple([
        g_int($row["Transaction No."] ?? null),
        g_sql($row["Customer Name"] ?? ""),
        g_sql($row["Queue ID"] ?? ""),
        g_sql($row["Type of Request"] ?? ""),
        g_sql(g_payment($row["Payment Method"] ?? "")),
        g_sql($row["GCash Reference No."] ?? ""),
        g_sql($row["Completion Route"] ?? ""),
        g_sql(g_excel_datetime($row["Request Created At"] ?? null), "::timestamptz"),
        g_sql(g_excel_datetime($row["Pending At"] ?? null), "::timestamptz"),
        g_sql(g_excel_datetime($row["Approved At"] ?? null), "::timestamptz"),
        g_sql(g_excel_datetime($row["Ongoing At"] ?? null), "::timestamptz"),
        g_sql(g_excel_datetime($row["For Pick-up At"] ?? null), "::timestamptz"),
        g_sql($doneAt, "::timestamptz"),
        g_sql(g_status($row["Final Status"] ?? "")),
        g_sql($status),
        g_number($row["Workflow Handling Time (min)"] ?? null),
        g_number($row["Queue Waiting Time (min)"] ?? null),
        g_number($row["Service Processing Time (min)"] ?? null),
        g_number($row["Pending Status Min"] ?? null),
        g_number($row["Approved Status Min"] ?? null),
        g_number($row["Ongoing Status Min"] ?? null),
        g_number($row["For Pick-up Status Min"] ?? null),
        g_int($row["Completion Flag"] ?? null),
        g_sql($row["System Status Flow"] ?? ""),
        g_sql($row["Status Transition History"] ?? ""),
        g_sql($row["Notes"] ?? ""),
        g_sql($status === "CANCELLED" ? $doneAt : null, "::timestamptz"),
    ]);
}

$eventTuples = [];
$previousByQueue = [];
foreach ($eventRows as $row) {
    $queueCode = trim((string)($row["Queue ID"] ?? ""));
    $status = g_status($row["Status"] ?? "");
    $eventTuples[] = g_tuple([
        g_int($row["Transaction No."] ?? null),
        g_sql($queueCode),
        g_sql($row["Customer Name"] ?? ""),
        g_sql($row["Type of Request"] ?? ""),
        g_sql(g_payment($row["Payment Method"] ?? "")),
        g_int($row["Transition No."] ?? null),
        g_sql($previousByQueue[$queueCode] ?? null),
        g_sql($status),
        g_sql(g_excel_datetime($row["Entered At"] ?? null), "::timestamptz"),
        g_sql(g_excel_datetime($row["Exited At"] ?? null), "::timestamptz"),
        g_number($row["Duration Min"] ?? null),
        g_sql(($row["Next Status"] ?? "") !== "" ? g_status($row["Next Status"]) : null),
        g_sql($row["Remarks"] ?? ""),
    ]);
    $previousByQueue[$queueCode] = $status;
}

$sql = "-- ServiTech Table 9 dummy analytics direct SQL import\n";
$sql .= "-- Generated from: " . basename($workbookPath) . "\n";
$sql .= "-- Safe to rerun. Matches existing queues by queues.queue_code and never inserts queue records.\n\n";
$sql .= "BEGIN;\n\n";
$sql .= "CREATE TEMP TABLE _table9_after_orders (\n";
$sql .= "  transaction_no int, customer_name text, queue_code text, service_type text, payment_method text,\n";
$sql .= "  reference_number text, completion_route text, request_created_at timestamptz, pending_at timestamptz,\n";
$sql .= "  approved_at timestamptz, ongoing_at timestamptz, for_pickup_at timestamptz, done_at timestamptz,\n";
$sql .= "  final_status text, current_status text, workflow_minutes numeric, queue_waiting_minutes numeric,\n";
$sql .= "  service_processing_minutes numeric, pending_minutes numeric, approved_minutes numeric,\n";
$sql .= "  ongoing_minutes numeric, for_pickup_minutes numeric, completion_flag int, system_status_flow text,\n";
$sql .= "  status_transition_history text, notes text, cancelled_at timestamptz\n";
$sql .= ") ON COMMIT DROP;\n\n";
$sql .= "INSERT INTO _table9_after_orders VALUES\n" . implode(",\n", $afterTuples) . ";\n\n";
$sql .= "CREATE TEMP TABLE _table9_status_events (\n";
$sql .= "  transaction_no int, queue_code text, customer_name text, service_type text, payment_method text,\n";
$sql .= "  transition_no int, previous_status text, status text, entered_at timestamptz, exited_at timestamptz,\n";
$sql .= "  duration_minutes numeric, next_status text, remarks text\n";
$sql .= ") ON COMMIT DROP;\n\n";
$sql .= "INSERT INTO _table9_status_events VALUES\n" . implode(",\n", $eventTuples) . ";\n\n";
$sql .= <<<'SQL'
CREATE TEMP TABLE _table9_missing_queue_ids ON COMMIT DROP AS
SELECT DISTINCT imported.queue_code
FROM (
  SELECT queue_code FROM _table9_after_orders
  UNION
  SELECT queue_code FROM _table9_status_events
) imported
LEFT JOIN queues q ON q.queue_code = imported.queue_code
WHERE q.id IS NULL;

CREATE TEMP TABLE _table9_matched_queues ON COMMIT DROP AS
SELECT DISTINCT q.id AS queue_id, q.user_id, q.queue_code, q.price
FROM queues q
INNER JOIN _table9_after_orders a ON a.queue_code = q.queue_code;

UPDATE queues q
SET category = CASE
      WHEN LOWER(a.service_type) LIKE '%repair%' THEN 'repair'
      WHEN LOWER(a.service_type) LIKE '%install%' THEN 'installation'
      ELSE 'printing'
    END,
    status = a.current_status,
    lifecycle_stage = CASE WHEN a.current_status IN ('DONE', 'CANCELLED') THEN 'ORDER' ELSE COALESCE(q.lifecycle_stage, 'QUEUE') END,
    request_source = 'servitech_assisted',
    request_created_at = a.request_created_at,
    pending_at = a.pending_at,
    approved_at = a.approved_at,
    ongoing_at = a.ongoing_at,
    for_pickup_at = a.for_pickup_at,
    done_at = a.done_at,
    cancelled_at = CASE WHEN a.current_status = 'CANCELLED' THEN COALESCE(a.cancelled_at, a.done_at, q.cancelled_at) ELSE q.cancelled_at END,
    created_at = COALESCE(a.request_created_at, q.created_at),
    queue_cycle_date = COALESCE((a.request_created_at AT TIME ZONE 'Asia/Manila')::date, q.queue_cycle_date),
    completed_at = CASE WHEN a.current_status = 'DONE' THEN COALESCE(a.done_at, q.completed_at) ELSE q.completed_at END,
    closed_at = CASE WHEN a.current_status IN ('DONE', 'CANCELLED') THEN COALESCE(a.done_at, a.cancelled_at, q.closed_at) ELSE q.closed_at END,
    details = COALESCE(q.details, '{}'::jsonb) || jsonb_build_object(
      'analytics_import_source', 'table9_dummy',
      'analytics_transaction_no', a.transaction_no,
      'customer_name_snapshot', a.customer_name,
      'type_of_request', a.service_type,
      'service_name_snapshot', a.service_type,
      'service_label', a.service_type,
      'payment_method', a.payment_method,
      'reference_number', a.reference_number,
      'completion_route', a.completion_route,
      'workflow_handling_minutes_imported', a.workflow_minutes,
      'queue_waiting_minutes_imported', a.queue_waiting_minutes,
      'service_processing_minutes_imported', a.service_processing_minutes,
      'pending_status_minutes_imported', a.pending_minutes,
      'approved_status_minutes_imported', a.approved_minutes,
      'ongoing_status_minutes_imported', a.ongoing_minutes,
      'for_pickup_status_minutes_imported', a.for_pickup_minutes,
      'completion_flag', a.completion_flag,
      'system_status_flow', a.system_status_flow,
      'status_transition_history', a.status_transition_history,
      'analytics_notes', a.notes
    ),
    updated_at = NOW()
FROM _table9_after_orders a
WHERE q.queue_code = a.queue_code;

CREATE TEMP TABLE _table9_latest_payments ON COMMIT DROP AS
SELECT DISTINCT ON (p.queue_id) p.id AS payment_id, p.queue_id
FROM payments p
INNER JOIN _table9_matched_queues m ON m.queue_id = p.queue_id
ORDER BY p.queue_id, p.id DESC;

UPDATE payments p
SET payment_method = a.payment_method,
    reference_number = NULLIF(a.reference_number, ''),
    amount = COALESCE(NULLIF(p.amount, 0), COALESCE(m.price, 0)),
    status = CASE WHEN a.current_status = 'DONE' THEN 'PAID' ELSE COALESCE(NULLIF(p.status, ''), 'PENDING') END,
    updated_at = NOW()
FROM _table9_after_orders a
INNER JOIN _table9_matched_queues m ON m.queue_code = a.queue_code
INNER JOIN _table9_latest_payments lp ON lp.queue_id = m.queue_id
WHERE p.id = lp.payment_id
  AND a.payment_method IN ('cash', 'gcash');

INSERT INTO payments (queue_id, user_id, amount, payment_method, reference_number, status, created_at, updated_at)
SELECT m.queue_id, m.user_id, COALESCE(m.price, 0), a.payment_method, NULLIF(a.reference_number, ''),
       CASE WHEN a.current_status = 'DONE' THEN 'PAID' ELSE 'PENDING' END,
       NOW(), NOW()
FROM _table9_after_orders a
INNER JOIN _table9_matched_queues m ON m.queue_code = a.queue_code
LEFT JOIN _table9_latest_payments lp ON lp.queue_id = m.queue_id
WHERE lp.payment_id IS NULL
  AND a.payment_method IN ('cash', 'gcash');

INSERT INTO queue_status_events (
  queue_id, queue_code, customer_name_snapshot, service_type, payment_method,
  transition_no, previous_status, status, entered_at, exited_at, duration_minutes,
  next_status, updated_by, updated_by_name, remarks, created_at, updated_at
)
SELECT q.id, e.queue_code, e.customer_name, e.service_type, e.payment_method,
       e.transition_no, e.previous_status, e.status, e.entered_at, e.exited_at, e.duration_minutes,
       e.next_status, NULL, '', e.remarks, NOW(), NOW()
FROM _table9_status_events e
INNER JOIN queues q ON q.queue_code = e.queue_code
WHERE e.entered_at IS NOT NULL
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
  updated_at = NOW();

SELECT
  (SELECT COUNT(*) FROM _table9_after_orders) AS after_order_rows_read,
  (SELECT COUNT(*) FROM _table9_status_events) AS status_event_rows_read,
  (SELECT COUNT(*) FROM _table9_after_orders) + (SELECT COUNT(*) FROM _table9_status_events) AS total_rows_read,
  (SELECT COUNT(*) FROM _table9_matched_queues) AS total_queues_matched,
  (SELECT COUNT(*) FROM _table9_matched_queues) AS total_queues_updated,
  (SELECT COUNT(*) FROM queue_status_events e INNER JOIN _table9_status_events i ON i.queue_code = e.queue_code AND i.transition_no = e.transition_no AND i.status = e.status AND i.entered_at = e.entered_at) AS status_transition_events_inserted_or_updated,
  COALESCE((SELECT string_agg(queue_code, ', ' ORDER BY queue_code) FROM _table9_missing_queue_ids), 'None') AS missing_queue_ids,
  'None' AS errors;

COMMIT;
SQL;

file_put_contents($outputPath, $sql);
echo "Generated {$outputPath}\n";
echo "After-order rows: " . count($afterRows) . "\n";
echo "Status event rows: " . count($eventRows) . "\n";
