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
        "walk-in printing",
        "walk-in document printing",
        "walk-in document print",
        "walkin printing",
        "print walk-in",
    ];

    if (
        in_array($normalized, $legacyPrintingLabels, true)
        || (str_contains($normalized, "document") && str_contains($normalized, "print"))
        || (str_contains($normalized, "print") && str_contains($normalized, "order"))
    ) return "Document Print";
    if (strcasecmp(trim($serviceLabel), "xerox") === 0) return "Photocopy";
    if (strcasecmp(trim($serviceLabel), "lamination") === 0) return "Laminating";
    return trim($serviceLabel);
}

function queue_ui_status_label($status): string
{
    $status = strtoupper(trim((string)$status));
    $status = preg_replace('/[\s_]+/', ' ', $status);
    return match ($status) {
        "APPROVED" => "Approved",
        "ONGOING" => "Ongoing",
        "FOR PICK-UP", "FOR PICK UP", "FOR PICKUP" => "For Pick-up",
        "DONE", "COMPLETED" => "Done",
        "CANCELLED", "CANCELED" => "Cancelled",
        default => "Pending",
    };
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
    return strtolower(trim((string)($row["payment_method"] ?? ($details["payment_method_snapshot"] ?? ($details["payment_method"] ?? "")))));
}

function queue_ui_payment_summary(array $row): string
{
    $details = queue_ui_details_array($row["details"] ?? null);
    $method = queue_ui_payment_method($row);
    $category = strtolower(trim((string)($row["category"] ?? "")));
    if ($method === "" && in_array($category, ["repair", "installation"], true)) {
        return "Payment to be assessed after review";
    }
    $methodLabel = match ($method) {
        "cash" => "Cash",
        "gcash" => "GCash",
        "online", "online_payment", "online payment" => "Online Payment",
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
    $serviceLabel = strtolower(queue_ui_detail_value($details, ["service_name_snapshot", "service_label", "service", "service_type"]));
    $unitPriceLabel = str_contains($serviceLabel, "scan")
        ? "Price Per Scan"
        : (str_contains($serviceLabel, "laminat") ? "Unit Price" : "Price Per Page");
    $map = [
        "Paper Size" => ["paper_size_snapshot", "paper_size", "paper"],
        "Quantity / Copies" => ["quantity_snapshot", "quantity", "copies"],
        "Color Option" => ["color_option_snapshot", "color_option", "color"],
        "Device" => ["device_snapshot", "device", "device_type", "unit"],
        "Repair Type" => ["service_type_snapshot", "repair_type"],
        "Installation Type" => ["installation_type_snapshot", "installation_type"],
        "Package" => ["package_snapshot", "package_label", "package"],
        "Lamination Type" => ["lamination_type_snapshot", "lamination_type"],
        "Total Pages" => ["total_pages", "page_count"],
        $unitPriceLabel => ["price_snapshot", "price_per_page"],
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
    $snapshotServiceLabel = queue_ui_detail_value($details, ["service_name_snapshot", "service_label", "catalog_service_name"]);
    if ($snapshotServiceLabel !== "") $serviceLabel = $snapshotServiceLabel;
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
        "comments" => queue_ui_detail_value($details, ["customer_notes_snapshot", "notes", "additional_instructions", "comments"]),
        "payment" => $paymentSummary,
        "paymentReference" => trim((string)($row["reference_number"] ?? ($details["reference_number"] ?? ""))),
        "paymentMethod" => match (queue_ui_payment_method($row)) {
            "gcash" => "GCash",
            "cash" => "Cash",
            "online", "online_payment", "online payment" => "Online Payment",
            default => "",
        },
        "paymentStatus" => queue_ui_payment_method($row) !== "" ? strtoupper(trim((string)($row["payment_status"] ?? ""))) : "",
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
    $filterStatus = strtoupper(trim((string)($row["status"] ?? "PENDING")));
    $filterStatus = preg_replace('/[\s_]+/', ' ', $filterStatus);
    $filterStatus = match ($filterStatus) {
        "FOR PICK UP", "FOR PICKUP" => "FOR PICK-UP",
        "COMPLETED" => "DONE",
        "CANCELED" => "CANCELLED",
        default => $filterStatus,
    };
    $attrs = [
        "data-queue-id" => (string)($row["queue_code"] ?? ""),
        "data-queue-record-id" => (string)($row["id"] ?? ""),
        "data-queue-search-id" => strtolower((string)($row["queue_code"] ?? "")),
        "data-queue-customer" => strtolower((string)($row["fullname"] ?? "")),
        "data-queue-customer-email" => strtolower((string)($row["customer_email"] ?? "")),
        "data-queue-customer-phone" => strtolower((string)($row["customer_phone"] ?? "")),
        "data-queue-status" => $filterStatus,
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

function queue_ui_status_class($status): string
{
    $key = strtolower(trim((string)$status));
    $key = preg_replace('/[\s_]+/', '-', $key);
    return match ($key) {
        "approved" => "status-approved",
        "ongoing" => "status-ongoing",
        "for-pick-up", "for-pickup" => "status-pickup",
        "done", "completed" => "status-done",
        "cancelled", "canceled" => "status-cancelled",
        default => "status-pending",
    };
}

function queue_ui_service_label_for_scope(array $row, string $scope): string
{
    $details = queue_ui_details_array($row["details"] ?? null);
    $snapshot = queue_ui_detail_value($details, ["service_name_snapshot", "service_label", "catalog_service_name"]);
    if ($snapshot !== "") {
        return queue_ui_normalize_service_label($snapshot);
    }

    if ($scope === "queue_repair") {
        return "Repair Service";
    }
    if ($scope === "queue_installation") {
        return "Installation Service";
    }

    $category = strtolower(trim((string)($row["category"] ?? "")));
    $labels = [
        "printing" => "Document Print",
        "online_printorder" => "Document Print",
        "printing_online" => "Document Print",
        "walkin" => "Document Print",
        "printing_walkin" => "Document Print",
        "xerox" => "Photocopy",
        "photocopy" => "Photocopy",
        "rush-id" => "Rush ID",
        "laminating" => "Laminating",
        "scanning" => "Scanning",
    ];
    return $labels[$category] ?? ($category !== "" ? ucwords(str_replace(["_", "-"], " ", $category)) : "Print Service");
}

function queue_ui_render_table_rows(array $rows, string $scope): void
{
    $isPrinting = $scope === "queue_printing";
    $columnCount = $isPrinting ? 6 : 5;
    $emptyLabel = match ($scope) {
        "queue_repair" => "No repair queues yet.",
        "queue_installation" => "No installation queues yet.",
        default => "No print queues yet.",
    };

    if ($rows === []) {
        ?>
        <tr class="queue-server-empty-row">
          <td colspan="<?= $columnCount ?>" style="text-align:center;padding:18px;color:#666;"><?= htmlspecialchars($emptyLabel, ENT_QUOTES, "UTF-8") ?></td>
        </tr>
        <?php
        return;
    }

    foreach ($rows as $row) {
        $serviceLabel = queue_ui_service_label_for_scope($row, $scope);
        $paymentSummary = $isPrinting ? queue_ui_payment_summary($row) : "";
        $contact = trim(implode(" | ", array_filter([
            (string)($row["customer_email"] ?? ""),
            (string)($row["customer_phone"] ?? ""),
        ], static fn($value): bool => trim($value) !== "")));
        ?>
        <tr<?= queue_ui_row_attrs($row) ?>>
          <td><?= htmlspecialchars((string)($row["queue_code"] ?? ""), ENT_QUOTES, "UTF-8") ?></td>
          <td>
            <span class="customer-stack">
              <strong><?= htmlspecialchars((string)($row["fullname"] ?? ""), ENT_QUOTES, "UTF-8") ?></strong>
              <?php if ($contact !== ""): ?>
                <small><?= htmlspecialchars($contact, ENT_QUOTES, "UTF-8") ?></small>
              <?php endif; ?>
            </span>
          </td>
          <?php if ($isPrinting): ?>
            <td class="payment-cell"><?= htmlspecialchars($paymentSummary, ENT_QUOTES, "UTF-8") ?></td>
          <?php endif; ?>
          <td>
            <span class="submitted-stack">
              <strong><?= htmlspecialchars(admin_queue_submitted_date($row["created_at"] ?? null), ENT_QUOTES, "UTF-8") ?></strong>
              <small><?= htmlspecialchars(admin_queue_submitted_time($row["created_at"] ?? null), ENT_QUOTES, "UTF-8") ?></small>
            </span>
          </td>
          <td class="status-cell">
            <span class="status-badge <?= htmlspecialchars(queue_ui_status_class($row["status"] ?? "PENDING"), ENT_QUOTES, "UTF-8") ?>">
              <?= htmlspecialchars(queue_ui_status_label($row["status"] ?? "PENDING"), ENT_QUOTES, "UTF-8") ?>
            </span>
          </td>
          <td class="actions">
            <button
              class="queue-view-btn"
              type="button"
              data-queue="<?= queue_ui_payload_attr($row, $serviceLabel, $paymentSummary) ?>"
            >View</button>
            <div class="queue-inline-actions">
              <div class="actions-group">
                <?php queue_ui_render_transition_buttons($row); ?>
                <button
                  class="btn-message admin-file-action"
                  type="button"
                  data-id="<?= (int)($row["id"] ?? 0) ?>"
                  data-queue-code="<?= htmlspecialchars((string)($row["queue_code"] ?? ""), ENT_QUOTES, "UTF-8") ?>"
                  data-customer="<?= htmlspecialchars((string)($row["fullname"] ?? ""), ENT_QUOTES, "UTF-8") ?>"
                  data-service="<?= htmlspecialchars($serviceLabel, ENT_QUOTES, "UTF-8") ?>"
                >Message</button>
              </div>
            </div>
          </td>
        </tr>
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
