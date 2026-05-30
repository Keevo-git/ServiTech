<?php

function om_detail_value(array $details, array $keys, string $fallback = ""): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $details)) {
            continue;
        }

        $value = $details[$key];
        if (is_array($value)) {
            continue;
        }

        $value = trim((string)$value);
        if ($value !== "") {
            return $value;
        }
    }

    return $fallback;
}

function om_payment_method_label($value): string
{
    $key = strtolower(trim((string)$value));
    if ($key === "gcash") {
        return "GCash";
    }
    if ($key === "cash") {
        return "Cash";
    }
    return "-";
}

function om_payment_status_label($method, $paymentStatus = null, $detailsStatus = null): string
{
    $method = strtolower(trim((string)$method));
    $status = strtoupper(trim((string)($paymentStatus ?? $detailsStatus ?? "")));

    if ($method === "gcash") {
        if (in_array($status, ["PENDING", "SUBMITTED", "PENDING VERIFICATION"], true)) {
            return "Payment Submitted";
        }
        if (in_array($status, ["VERIFIED", "PAID", "COMPLETE"], true)) {
            return "Verified / Paid";
        }
        if (in_array($status, ["DECLINED", "REJECTED", "FAILED"], true)) {
            return "Rejected";
        }
    }

    if ($method === "cash") {
        if ($status === "" || $status === "PAY AT STORE") {
            return "Pay at Store";
        }
        if (in_array($status, ["PENDING", "UNPAID"], true)) {
            return "Pending Payment";
        }
        if (in_array($status, ["PAID", "VERIFIED", "COMPLETE", "DONE"], true)) {
            return "Paid";
        }
    }

    return $status !== "" ? ucfirst(strtolower($status)) : "-";
}

function om_payment_amount_label($amount, $detailsTotal = null): string
{
    if (is_numeric($amount) && (float)$amount > 0) {
        return "PHP " . number_format((float)$amount, 2);
    }

    if (is_string($detailsTotal) && trim($detailsTotal) !== "" && is_numeric(trim($detailsTotal))) {
        return "PHP " . number_format((float)trim($detailsTotal), 2);
    }

    if (is_numeric($detailsTotal) && (float)$detailsTotal > 0) {
        return "PHP " . number_format((float)$detailsTotal, 2);
    }

    return "";
}

function om_payment_summary(array $row): string
{
    $details = admin_queue_details_array($row["details"] ?? null);
    $method = $row["payment_method"] ?? ($details["payment_method"] ?? "");
    $methodLabel = om_payment_method_label($method);
    $amountLabel = om_payment_amount_label($row["amount"] ?? null, $row["details_total"] ?? ($details["estimated_total"] ?? null));

    if ($methodLabel === "-" && $amountLabel === "") {
        return "-";
    }

    return trim($methodLabel . " " . $amountLabel);
}

function om_service_label(array $details, string $fallback): string
{
    return om_detail_value($details, ["service_label", "service", "service_type", "installation_type", "repair_type"], $fallback);
}

function om_additional_comments(array $details): string
{
    $comments = [];
    foreach (["additional_comments", "additional_comment", "comments", "notes", "additional_instructions", "instructions", "edit_request", "request_notes"] as $key) {
        $value = om_detail_value($details, [$key]);
        if ($value !== "" && !in_array($value, $comments, true)) {
            $comments[] = $value;
        }
    }

    return implode("\n\n", $comments);
}

function om_extra_detail_rows(array $details): array
{
    $map = [
        "Paper Size" => ["paper_size", "paper"],
        "Quantity / Copies" => ["quantity", "copies"],
        "Color Option" => ["color_option", "color"],
        "Device" => ["device", "device_type", "unit"],
        "Package" => ["package_label", "package"],
        "Lamination Type" => ["lamination_type"],
        "Total Pages" => ["total_pages", "page_count"],
        "Order Type" => ["order_type"],
    ];

    $rows = [];
    foreach ($map as $label => $keys) {
        $value = om_detail_value($details, $keys);
        if ($value !== "") {
            $rows[] = ["label" => $label, "value" => $value];
        }
    }

    return $rows;
}

function om_order_payload(array $row, string $serviceType, string $fallbackService): array
{
    $details = admin_queue_details_array($row["details"] ?? null);
    $paymentMethod = $row["payment_method"] ?? ($details["payment_method"] ?? "");
    $paymentStatus = $row["payment_status"] ?? ($details["payment_status"] ?? null);
    $detailsPaymentStatus = $row["details_payment_status"] ?? ($details["payment_status"] ?? null);
    $referenceNumber = $row["reference_number"] ?? ($details["reference_number"] ?? "");
    $detailsTotal = $row["details_total"] ?? ($details["estimated_total"] ?? null);

    return [
        "id" => (int)($row["id"] ?? 0),
        "queueCode" => (string)($row["queue_code"] ?? ""),
        "customer" => (string)($row["fullname"] ?? ""),
        "status" => (string)($row["status"] ?? "PENDING"),
        "serviceType" => $serviceType,
        "serviceLabel" => om_service_label($details, $fallbackService),
        "submitted" => trim(admin_queue_submitted_date($row["created_at"] ?? null) . " " . admin_queue_submitted_time($row["created_at"] ?? null)),
        "completed" => admin_queue_has_timestamp($row["completed_at"] ?? null)
            ? trim(admin_queue_completed_date($row["completed_at"]) . " " . admin_queue_completed_time($row["completed_at"]))
            : "-",
        "paymentMethod" => om_payment_method_label($paymentMethod),
        "paymentReference" => trim((string)$referenceNumber),
        "paymentStatus" => om_payment_status_label($paymentMethod, $paymentStatus, $detailsPaymentStatus),
        "price" => om_payment_amount_label($row["amount"] ?? null, $detailsTotal),
        "files" => admin_queue_file_items($row["details"] ?? null),
        "details" => om_extra_detail_rows($details),
        "comments" => om_additional_comments($details),
        "canMessage" => !empty($row["canMessage"]),
    ];
}

function om_order_payload_attr(array $row, string $serviceType, string $fallbackService): string
{
    $json = json_encode(om_order_payload($row, $serviceType, $fallbackService), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        $json = "{}";
    }

    return htmlspecialchars(
        $json,
        ENT_QUOTES,
        "UTF-8"
    );
}
