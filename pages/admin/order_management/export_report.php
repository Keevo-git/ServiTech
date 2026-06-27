<?php
require_once __DIR__ . "/../_includes/admin_auth.php";
require_once __DIR__ . "/../_includes/admin_db.php";
require_once __DIR__ . "/../_includes/url.php";
require_once __DIR__ . "/../_includes/queue_files.php";
require_once __DIR__ . "/_order_modal_helpers.php";
require_once __DIR__ . "/../../../config/activity_log.php";

function order_export_clean(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value) ?? "");
}

function order_export_service_path(string $service): string
{
    return match ($service) {
        "repair" => "/pages/admin/order_management/repairM.php",
        "installation" => "/pages/admin/order_management/installationM.php",
        default => "/pages/admin/order_management/printM.php",
    };
}

function order_export_service_label(string $service): string
{
    return match ($service) {
        "repair" => "Repair",
        "installation" => "Installation",
        default => "Print",
    };
}

function order_export_date_label($value): string
{
    $value = trim((string)$value);
    if ($value === "") {
        return "";
    }

    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone("Asia/Manila"))
            ->format("M d, Y h:i A");
    } catch (Throwable $exception) {
        return "";
    }
}

function order_export_status_label(string $status): string
{
    $status = strtoupper(trim($status));
    return match ($status) {
        "FOR PICK-UP", "FOR PICKUP", "FOR PICK UP" => "For Pick-up",
        "APPROVED" => "Approved",
        "ONGOING" => "Ongoing",
        "DONE", "COMPLETED" => "Done",
        "CANCEL", "CANCELED", "CANCELLED" => "Cancelled",
        "PENDING" => "Pending",
        default => $status !== "" ? ucwords(strtolower(str_replace("_", " ", $status))) : "",
    };
}

function order_export_month_name(string $month): string
{
    $months = [
        "01" => "January",
        "02" => "February",
        "03" => "March",
        "04" => "April",
        "05" => "May",
        "06" => "June",
        "07" => "July",
        "08" => "August",
        "09" => "September",
        "10" => "October",
        "11" => "November",
        "12" => "December",
    ];
    return $months[$month] ?? "";
}

function order_export_status_predicate(array $statuses, array &$params): string
{
    $groups = [];
    $index = 0;
    foreach ($statuses as $status) {
        $status = strtoupper(trim($status));
        if ($status === "") {
            continue;
        }

        $variants = match ($status) {
            "FOR PICK-UP" => ["FOR PICK-UP", "FOR PICKUP", "FOR PICK UP"],
            "CANCELLED" => ["CANCELLED", "CANCELED", "CANCEL"],
            default => [$status],
        };

        $placeholders = [];
        foreach ($variants as $variant) {
            $key = ":status_" . $index++;
            $placeholders[] = $key;
            $params[$key] = $variant;
        }
        $groups[] = "UPPER(REPLACE(TRIM(COALESCE(q.status, '')), '_', ' ')) IN (" . implode(", ", $placeholders) . ")";
    }

    return $groups ? "(" . implode(" OR ", $groups) . ")" : "";
}

function order_export_filename(string $service, array $filters): string
{
    $parts = ["servitech", "orders", $service];

    if (($filters["statuses"] ?? []) !== []) {
        $parts[] = strtolower(str_replace([" ", "-"], "_", implode("_", $filters["statuses"])));
    }
    if (($filters["payment"] ?? "") !== "") {
        $parts[] = strtolower($filters["payment"]);
    }
    if (($filters["submitted_date"] ?? "") !== "") {
        $parts[] = $filters["submitted_date"];
    } elseif (($filters["month"] ?? "") !== "" && ($filters["year"] ?? "") !== "") {
        $monthName = strtolower(order_export_month_name($filters["month"]));
        if ($monthName !== "") {
            $parts[] = $monthName;
        }
        $parts[] = $filters["year"];
    } elseif (($filters["year"] ?? "") !== "") {
        $parts[] = $filters["year"];
    } else {
        $parts[] = (new DateTimeImmutable("now", new DateTimeZone("Asia/Manila")))->format("Y-m-d");
    }

    $filename = strtolower(implode("_", array_filter($parts)));
    $filename = preg_replace('/[^a-z0-9_-]+/', "_", $filename) ?: "servitech_orders_report";
    return trim($filename, "_") . ".csv";
}

$service = strtolower(trim((string)($_GET["service"] ?? "print")));
if (!in_array($service, ["print", "repair", "installation"], true)) {
    $service = "print";
}

$search = order_export_clean(strtolower((string)($_GET["search"] ?? "")));
$submittedDate = trim((string)($_GET["submitted_date"] ?? ""));
$month = str_pad((string)($_GET["month"] ?? ""), 2, "0", STR_PAD_LEFT);
$year = trim((string)($_GET["year"] ?? ""));
$payment = strtolower(trim((string)($_GET["payment"] ?? "")));
$statuses = array_values(array_filter(array_map(
    static fn($value): string => strtoupper(trim((string)$value)),
    explode(",", (string)($_GET["statuses"] ?? ""))
)));

if ($month === "00") {
    $month = "";
}
if ($month !== "" && !preg_match('/^(0[1-9]|1[0-2])$/', $month)) {
    $month = "";
}
if ($year !== "" && !preg_match('/^\d{4}$/', $year)) {
    $year = "";
}
if ($submittedDate !== "" && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $submittedDate)) {
    $submittedDate = "";
}
if ($payment !== "" && !in_array($payment, ["cash", "gcash"], true)) {
    $payment = "";
}

if ($month !== "" && $year === "") {
    servitech_admin_flash_toast("Please select a year for the monthly filter.", "warning");
    header("Location: " . admin_url_raw(order_export_service_path($service)));
    exit();
}

$params = [];
$visibilityPredicate = admin_order_soft_delete_column_ready($pdo)
    ? "AND q.deleted_at IS NULL AND q.permanently_hidden_at IS NULL"
    : "";

$servicePredicate = match ($service) {
    "repair" => "LOWER(TRIM(COALESCE(q.category, ''))) = 'repair'",
    "installation" => "LOWER(TRIM(COALESCE(q.category, ''))) = 'installation'",
    default => "(
      LOWER(TRIM(COALESCE(q.category, ''))) IN (
        'online_printorder', 'printing_online', 'printing', 'walkin', 'printing_walkin',
        'xerox', 'photocopy', 'rush-id', 'laminating', 'scanning'
      )
      OR UPPER(TRIM(COALESCE(q.queue_code, ''))) LIKE 'OP%'
    )",
};

$where = [
    "UPPER(TRIM(COALESCE(q.lifecycle_stage, 'QUEUE'))) = 'ORDER'",
    $servicePredicate,
];

if ($visibilityPredicate !== "") {
    $where[] = substr($visibilityPredicate, 4);
}
if ($search !== "") {
    $where[] = "(
        LOWER(COALESCE(q.queue_code, '')) LIKE :search
        OR LOWER(COALESCE(u.fullname, '')) LIKE :search
        OR LOWER(COALESCE(u.email, '')) LIKE :search
        OR LOWER(COALESCE(NULLIF(to_jsonb(u)->>'contact', ''), NULLIF(to_jsonb(u)->>'contacts', ''), '')) LIKE :search
    )";
    $params[":search"] = "%" . $search . "%";
}
if ($submittedDate !== "") {
    $where[] = "(q.created_at AT TIME ZONE 'Asia/Manila')::date = CAST(:submitted_date AS date)";
    $params[":submitted_date"] = $submittedDate;
}
if ($month !== "" && $year !== "") {
    $where[] = "EXTRACT(MONTH FROM q.created_at AT TIME ZONE 'Asia/Manila') = :month";
    $where[] = "EXTRACT(YEAR FROM q.created_at AT TIME ZONE 'Asia/Manila') = :year";
    $params[":month"] = (int)$month;
    $params[":year"] = (int)$year;
} elseif ($year !== "") {
    $where[] = "EXTRACT(YEAR FROM q.created_at AT TIME ZONE 'Asia/Manila') = :year";
    $params[":year"] = (int)$year;
}
$statusPredicate = order_export_status_predicate($statuses, $params);
if ($statusPredicate !== "") {
    $where[] = $statusPredicate;
}
if ($payment !== "") {
    $where[] = "LOWER(TRIM(COALESCE(p.payment_method, q.details->>'payment_method', ''))) = :payment";
    $params[":payment"] = $payment;
}

$sql = "
  SELECT
    q.id,
    q.queue_code,
    q.category,
    q.status,
    q.details,
    q.price,
    q.paid_amount,
    q.send_back_message,
    q.created_at,
    q.updated_at,
    u.fullname,
    u.email AS customer_email,
    COALESCE(NULLIF(to_jsonb(u)->>'contact', ''), NULLIF(to_jsonb(u)->>'contacts', '')) AS customer_phone,
    p.payment_method,
    p.reference_number,
    p.amount,
    p.status AS payment_status,
    q.details->>'estimated_total' AS details_total,
    h.admin_name AS processed_by
  FROM queues q
  JOIN users u ON u.id = q.user_id
  LEFT JOIN LATERAL (
    SELECT payment_method, reference_number, amount, status
    FROM payments
    WHERE queue_id = q.id
    ORDER BY id DESC
    LIMIT 1
  ) p ON TRUE
  LEFT JOIN LATERAL (
    SELECT admin_name
    FROM queue_status_history
    WHERE queue_id = q.id
      AND TRIM(COALESCE(admin_name, '')) <> ''
    ORDER BY created_at DESC, id DESC
    LIMIT 1
  ) h ON TRUE
  WHERE " . implode("\n    AND ", $where) . "
  ORDER BY
    CASE
      WHEN UPPER(TRIM(COALESCE(q.status, ''))) IN ('DONE', 'CANCEL', 'CANCELLED', 'CANCELED') THEN 1
      ELSE 0
    END,
    q.created_at ASC,
    q.id ASC
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    error_log("order export failed: " . $exception->getMessage());
    servitech_admin_flash_toast("Unable to export report. Please try again.", "error");
    header("Location: " . admin_url_raw(order_export_service_path($service)));
    exit();
}

if (!$orders) {
    servitech_admin_flash_toast("No orders found for the selected filters.", "warning");
    header("Location: " . admin_url_raw(order_export_service_path($service)));
    exit();
}

$filters = [
    "search" => $search,
    "submitted_date" => $submittedDate,
    "month" => $month,
    "year" => $year,
    "statuses" => $statuses,
    "payment" => $payment,
];

$serviceLabel = order_export_service_label($service);
$filterSummary = [];
if ($search !== "") $filterSummary[] = "search '{$search}'";
if ($submittedDate !== "") $filterSummary[] = "submitted date {$submittedDate}";
if ($month !== "" && $year !== "") $filterSummary[] = order_export_month_name($month) . " {$year}";
elseif ($year !== "") $filterSummary[] = "year {$year}";
if ($statuses) $filterSummary[] = "status " . implode(", ", $statuses);
if ($payment !== "") $filterSummary[] = "payment " . strtoupper($payment);
$filterSummaryText = $filterSummary ? implode("; ", $filterSummary) : "all visible records";

servitech_activity_log($pdo, [
    "actor_id" => (int)($_SESSION["user_id"] ?? 0),
    "role" => servitech_current_role(),
    "action_type" => "order_report_export",
    "module" => "order_management",
    "target_record_id" => strtolower($serviceLabel),
    "new_value" => [
        "service" => $service,
        "filters" => $filters,
        "row_count" => count($orders),
    ],
    "description" => "Admin exported the {$serviceLabel} orders report for {$filterSummaryText}.",
    "status" => "success",
]);

header("Content-Type: text/csv; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"" . order_export_filename($service, $filters) . "\"");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$output = fopen("php://output", "w");
if ($output === false) {
    exit();
}

fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, [
    "Order ID",
    "Customer Name",
    "Email",
    "Contact Number",
    "Service Type",
    "Order Status",
    "Mode of Payment",
    "Payment Amount",
    "Submitted Date",
    "Updated Date",
    "Processed By / Updated By",
    "Remarks",
]);

foreach ($orders as $order) {
    $details = admin_queue_details_array($order["details"] ?? null);
    $payment = servitech_queue_payment_values($order);
    $serviceType = om_service_label($details, $serviceLabel . " Service");
    $paymentMethod = om_payment_method_label($order["payment_method"] ?? ($details["payment_method"] ?? ""));
    $paymentAmount = om_payment_amount_label($payment["price"] ?? null, $order["details_total"] ?? ($details["estimated_total"] ?? null));
    $remarks = trim(implode("\n\n", array_filter([
        om_additional_comments($details),
        trim((string)($order["send_back_message"] ?? "")),
    ])));

    fputcsv($output, [
        (string)($order["queue_code"] ?? ""),
        (string)($order["fullname"] ?? ""),
        (string)($order["customer_email"] ?? ""),
        (string)($order["customer_phone"] ?? ""),
        $serviceType,
        order_export_status_label((string)($order["status"] ?? "")),
        $paymentMethod !== "" ? $paymentMethod : "For assessment",
        $paymentAmount,
        order_export_date_label($order["created_at"] ?? ""),
        order_export_date_label($order["updated_at"] ?? ""),
        (string)($order["processed_by"] ?? ""),
        $remarks,
    ]);
}

fclose($output);
exit();
