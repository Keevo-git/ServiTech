<?php
require_once __DIR__ . "/../../../api/queue_payment.php";

function queue_ui_details_array($details): array
{
    return admin_queue_details_array($details);
}

function queue_ui_detail_value(array $details, array $keys): string
{
    foreach ($keys as $key) {
        $value = $details[$key] ?? "";
        if (!is_array($value) && trim((string)$value) !== "") {
            return trim((string)$value);
        }
    }

    return "";
}

function queue_ui_normalize_service_label(string $serviceLabel): string
{
    $normalized = strtolower(trim($serviceLabel));
    $legacyPrintingLabels = [
        "document printing",
        "document print",
        "online print order",
        "online document printing",
        "online document print",
        "online printing",
        "walk-in printing",
        "walk-in document printing",
        "walk-in document print",
        "walkin printing",
        "print walk-in",
        "print online",
    ];

    if (in_array($normalized, $legacyPrintingLabels, true)) return "Document Print";
    if (strcasecmp(trim($serviceLabel), "xerox") === 0) return "Photocopy";
    if (strcasecmp(trim($serviceLabel), "lamination") === 0) return "Laminating";
    return trim($serviceLabel);
}

function queue_ui_category_label(array $row, string $serviceLabel): string
{
    $category = strtolower(trim((string)($row["category"] ?? "")));
    $normalizedService = strtolower(queue_ui_normalize_service_label($serviceLabel));

    if (
        in_array($category, ["printing", "online_printorder", "printing_online", "walkin", "printing_walkin", "xerox", "photocopy", "rush-id", "laminating"], true)
        || in_array($normalizedService, ["document print", "xerox", "photocopy", "rush id", "laminating"], true)
    ) {
        return "Print";
    }
    if ($category === "installation" || str_contains($normalizedService, "installation")) {
        return "Installation";
    }
    if ($category === "repair" || str_contains($normalizedService, "repair")) {
        return "Repair";
    }

    return $category !== "" ? ucwords(str_replace(["_", "-"], " ", $category)) : "";
}

function queue_ui_filter_date($value): string
{
    $submittedAt = trim((string)$value);
    if ($submittedAt === "") {
        return "";
    }

    try {
        return (new DateTimeImmutable($submittedAt))
            ->setTimezone(new DateTimeZone("Asia/Manila"))
            ->format("Y-m-d");
    } catch (Throwable $exception) {
        return "";
    }
}

function queue_ui_payment_method(array $row): string
{
    $details = queue_ui_details_array($row["details"] ?? null);
    return strtolower(trim((string)($row["payment_method"] ?? ($details["payment_method"] ?? ""))));
}

function queue_ui_payment_summary(array $row): string
{
    $details = queue_ui_details_array($row["details"] ?? null);
    $method = queue_ui_payment_method($row);
    $methodLabel = match ($method) {
        "cash" => "Cash",
        "gcash" => "GCash",
        default => "",
    };
    $amount = $row["amount"] ?? ($details["estimated_total"] ?? null);
    $amountLabel = is_numeric($amount) && (float)$amount > 0
        ? "PHP " . number_format((float)$amount, 2)
        : "";

    if ($methodLabel !== "" && $amountLabel !== "") {
        return $methodLabel . ": " . $amountLabel;
    }

    return $methodLabel !== "" ? $methodLabel : $amountLabel;
}

function queue_ui_detail_rows(array $details): array
{
    $serviceLabel = strtolower(queue_ui_detail_value($details, ["service_label", "service", "service_type"]));
    $unitPriceLabel = str_contains($serviceLabel, "scan")
        ? "Price Per Scan"
        : (str_contains($serviceLabel, "laminat") ? "Unit Price" : "Price Per Page");
    $map = [
        "Paper Size" => ["paper_size", "paper"],
        "Quantity / Copies" => ["quantity", "copies"],
        "Color Option" => ["color_option", "color"],
        "Device" => ["device", "device_type", "unit"],
        "Repair Type" => ["repair_type"],
        "Installation Type" => ["installation_type"],
        "Package" => ["package_label", "package"],
        "Lamination Type" => ["lamination_type"],
        "Total Pages" => ["total_pages", "page_count"],
        $unitPriceLabel => ["price_per_page", "price_snapshot"],
    ];

    $rows = [];
    foreach ($map as $label => $keys) {
        $value = queue_ui_detail_value($details, $keys);
        if ($value !== "") {
            if (in_array($label, ["Price Per Page", "Price Per Scan", "Unit Price"], true) && is_numeric($value)) {
                $value = "PHP " . number_format((float)$value, 2);
            }
            $rows[] = ["label" => $label, "value" => $value];
        }
    }

    $addOns = $details["add_ons_snapshot"] ?? [];
    if (is_array($addOns)) {
        $addOnNames = [];
        foreach ($addOns as $addOn) {
            if (is_array($addOn) && trim((string)($addOn["name"] ?? "")) !== "") {
                $addOnNames[] = trim((string)$addOn["name"]);
            }
        }
        if ($addOnNames !== []) {
            $rows[] = ["label" => "Add-ons", "value" => implode(", ", $addOnNames)];
        }
    }

    return $rows;
}

function queue_ui_payload(array $row, string $serviceLabel, string $paymentSummary = ""): array
{
    $details = queue_ui_details_array($row["details"] ?? null);
    $payment = servitech_queue_payment_values($row);
    $serviceLabel = queue_ui_normalize_service_label($serviceLabel);
    $categoryLabel = queue_ui_category_label($row, $serviceLabel);
    if ($categoryLabel === "Print" && in_array(strtolower($serviceLabel), ["online", "walk-in", "walk in", "walkin"], true)) {
        $serviceLabel = "Document Print";
    }

    return [
        "id" => (int)($row["id"] ?? 0),
        "queueCode" => (string)($row["queue_code"] ?? ""),
        "customer" => (string)($row["fullname"] ?? ""),
        "customerEmail" => (string)($row["customer_email"] ?? ""),
        "customerPhone" => (string)($row["customer_phone"] ?? ""),
        "category" => $categoryLabel,
        "service" => $serviceLabel,
        "status" => (string)($row["status"] ?? "PENDING"),
        "submitted" => trim(admin_queue_submitted_date($row["created_at"] ?? null) . " " . admin_queue_submitted_time($row["created_at"] ?? null)),
        "completed" => admin_queue_has_timestamp($row["completed_at"] ?? null)
            ? trim(admin_queue_completed_date($row["completed_at"]) . " " . admin_queue_completed_time($row["completed_at"]))
            : "-",
        "comments" => queue_ui_detail_value($details, ["notes", "additional_instructions", "comments"]),
        "payment" => $paymentSummary,
        "paymentReference" => trim((string)($row["reference_number"] ?? ($details["reference_number"] ?? ""))),
        "paymentMethod" => match (queue_ui_payment_method($row)) {
            "gcash" => "GCash",
            "cash" => "Cash",
            default => "",
        },
        "paymentStatus" => strtoupper(trim((string)($row["payment_status"] ?? ""))),
        "price" => $payment["price"],
        "paidAmount" => $payment["paid_amount"],
        "paidPending" => $payment["paid_pending"],
        "customerEditRequired" => !empty($row["customer_edit_required"]),
        "sendBackMessage" => trim((string)($row["send_back_message"] ?? "")),
        "files" => admin_queue_file_items($row["details"] ?? null),
        "details" => queue_ui_detail_rows($details),
        "allowedStatuses" => servitech_queue_allowed_transitions($row),
    ];
}

function queue_ui_payload_attr(array $row, string $serviceLabel, string $paymentSummary = ""): string
{
    $json = json_encode(queue_ui_payload($row, $serviceLabel, $paymentSummary), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return htmlspecialchars(is_string($json) ? $json : "{}", ENT_QUOTES, "UTF-8");
}

function queue_ui_row_attrs(array $row): string
{
    $attrs = [
        "data-queue-id" => (string)($row["queue_code"] ?? ""),
        "data-queue-record-id" => (string)($row["id"] ?? ""),
        "data-queue-search-id" => strtolower((string)($row["queue_code"] ?? "")),
        "data-queue-customer" => strtolower((string)($row["fullname"] ?? "")),
        "data-queue-customer-email" => strtolower((string)($row["customer_email"] ?? "")),
        "data-queue-customer-phone" => strtolower((string)($row["customer_phone"] ?? "")),
        "data-queue-status" => strtoupper(trim((string)($row["status"] ?? "PENDING"))),
        "data-queue-payment" => queue_ui_payment_method($row),
        "data-queue-date" => queue_ui_filter_date($row["created_at"] ?? null),
        "data-queue-submitted-at" => (string)($row["created_at"] ?? ""),
    ];

    $html = ' class="queue-data-row"';
    foreach ($attrs as $key => $value) {
        $html .= " " . $key . '="' . htmlspecialchars($value, ENT_QUOTES, "UTF-8") . '"';
    }

    return $html;
}

function queue_ui_render_transition_buttons(array $row): void
{
    $buttons = [
        "APPROVED" => ["approved", "Approve", "btn-approved"],
        "ONGOING" => ["ongoing", "Start", "btn-start"],
        "FOR PICK-UP" => ["pickup", "For Pick-up", "btn-pickup"],
        "DONE" => ["done", "Done", "btn-done"],
        "CANCELLED" => ["cancel", "Cancel", "btn-cancel"],
    ];

    foreach (servitech_queue_allowed_transitions($row) as $status) {
        if (!isset($buttons[$status])) {
            continue;
        }
        [$action, $label, $class] = $buttons[$status];
        if ($status === "APPROVED" && queue_ui_payment_method($row) === "gcash") {
            $label = "Approve GCash";
        }
        ?>
        <button
          class="<?= htmlspecialchars($class, ENT_QUOTES, "UTF-8") ?> admin-file-action"
          data-id="<?= (int)($row["id"] ?? 0) ?>"
          data-action="<?= htmlspecialchars($action, ENT_QUOTES, "UTF-8") ?>"
        ><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></button>
        <?php
    }
}

function queue_ui_render_filter_toolbar(string $tableId, bool $includePayment = false): void
{
    $statuses = [
        "PENDING" => "Pending",
        "APPROVED" => "Approved",
        "ONGOING" => "Ongoing",
        "FOR PICK-UP" => "For Pick-up",
        "DONE" => "Done",
        "CANCELLED" => "Cancelled",
    ];
    ?>
    <div class="queue-filter-toolbar<?= $includePayment ? " queue-filter-toolbar--payment" : "" ?>" data-queue-filter-toolbar data-table-id="<?= htmlspecialchars($tableId, ENT_QUOTES, "UTF-8") ?>">
      <div class="queue-filter-grid">
        <label class="queue-filter-control queue-filter-control--search">
          <span>Search</span>
          <input type="search" data-queue-filter-search placeholder="Search by Customer Name or Queue ID" autocomplete="off">
        </label>

        <label class="queue-filter-control">
          <span>Queued Date</span>
          <input type="date" data-queue-filter-date>
        </label>

        <div class="queue-filter-control">
          <span>Status</span>
          <details class="queue-status-filter">
            <summary><span data-queue-filter-status-label>All statuses</span></summary>
            <div class="queue-status-filter__menu">
              <?php foreach ($statuses as $value => $label): ?>
                <label>
                  <input type="checkbox" value="<?= htmlspecialchars($value, ENT_QUOTES, "UTF-8") ?>" data-queue-filter-status>
                  <span><?= htmlspecialchars($label, ENT_QUOTES, "UTF-8") ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </details>
        </div>

        <?php if ($includePayment): ?>
          <label class="queue-filter-control">
            <span>Mode of Payment</span>
            <select data-queue-filter-payment>
              <option value="">All payment modes</option>
              <option value="cash">Cash</option>
              <option value="gcash">GCash</option>
            </select>
          </label>
        <?php endif; ?>

        <button class="queue-filter-clear" type="button" data-queue-filter-clear>Clear Filters</button>
      </div>
      <p class="queue-filter-results" data-queue-filter-results aria-live="polite">0 results found</p>
    </div>
    <?php
}
