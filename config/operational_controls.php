<?php
require_once __DIR__ . "/app.php";
require_once __DIR__ . "/store_availability.php";
require_once __DIR__ . "/../api/service_catalog.php";

function servitech_operational_schema_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $stmt = $pdo->query("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = ANY(current_schemas(false))
              AND table_name IN (
                'operational_control_settings',
                'operational_service_settings',
                'operational_payment_method_settings'
              )
        ");
        $ready = (int)$stmt->fetchColumn() === 3;
    } catch (Throwable $exception) {
        error_log("operational controls schema check failed: " . $exception->getMessage());
        $ready = false;
    }

    return $ready;
}

function servitech_operational_payment_method_label(string $key): string
{
    return [
        "cash" => "Cash",
        "gcash" => "GCash / Online Payment",
    ][strtolower(trim($key))] ?? ucwords(str_replace("_", " ", strtolower(trim($key))));
}

function servitech_operational_default_payment_methods(): array
{
    return [
        "cash" => [
            "payment_method_key" => "cash",
            "payment_method_name" => "Cash",
            "is_enabled" => true,
            "disabled_reason" => "",
            "updated_at" => null,
        ],
        "gcash" => [
            "payment_method_key" => "gcash",
            "payment_method_name" => "GCash / Online Payment",
            "is_enabled" => true,
            "disabled_reason" => "",
            "updated_at" => null,
        ],
    ];
}

function servitech_operational_fetch_overall(PDO $pdo): array
{
    $fallback = [
        "all_services_closed" => false,
        "all_services_closure_reason" => "",
        "updated_at" => null,
        "updated_by" => null,
    ];
    if (!servitech_operational_schema_ready($pdo)) {
        return $fallback;
    }

    try {
        $row = $pdo->query("
            SELECT all_services_closed, all_services_closure_reason, updated_by, updated_at
            FROM operational_control_settings
            WHERE id = 1
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return $fallback;
        }

        return [
            "all_services_closed" => filter_var($row["all_services_closed"] ?? false, FILTER_VALIDATE_BOOLEAN),
            "all_services_closure_reason" => trim((string)($row["all_services_closure_reason"] ?? "")),
            "updated_at" => $row["updated_at"] ?? null,
            "updated_by" => $row["updated_by"] ?? null,
        ];
    } catch (Throwable $exception) {
        error_log("operational overall fetch failed: " . $exception->getMessage());
        return $fallback;
    }
}

function servitech_operational_fetch_services(PDO $pdo): array
{
    if (!servitech_operational_schema_ready($pdo)) {
        return [];
    }

    try {
        $stmt = $pdo->query("
            SELECT
              s.id, s.category, s.name, s.description,
              CASE WHEN s.active THEN 1 ELSE 0 END AS active,
              s.sort_order,
              COALESCE(os.manual_status, 'open') AS manual_status,
              COALESCE(os.closure_reason, '') AS closure_reason,
              os.updated_by,
              os.updated_at
            FROM services s
            LEFT JOIN operational_service_settings os ON os.service_id = s.id
            ORDER BY s.category ASC, s.sort_order ASC, s.id ASC
        ");
        $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $supported = array_values(array_filter($services, static function (array $service): bool {
            return servitech_catalog_service_kind($service) !== "";
        }));
        return servitech_catalog_dedupe_services($supported, false);
    } catch (Throwable $exception) {
        error_log("operational service controls fetch failed: " . $exception->getMessage());
        return [];
    }
}

function servitech_operational_fetch_payment_methods(PDO $pdo): array
{
    $methods = servitech_operational_default_payment_methods();
    if (!servitech_operational_schema_ready($pdo)) {
        return $methods;
    }

    try {
        $stmt = $pdo->query("
            SELECT payment_method_key, payment_method_name, is_enabled, disabled_reason, updated_by, updated_at
            FROM operational_payment_method_settings
            ORDER BY CASE payment_method_key WHEN 'cash' THEN 1 WHEN 'gcash' THEN 2 ELSE 99 END, payment_method_key
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = strtolower(trim((string)($row["payment_method_key"] ?? "")));
            if ($key === "") {
                continue;
            }
            $methods[$key] = [
                "payment_method_key" => $key,
                "payment_method_name" => trim((string)($row["payment_method_name"] ?? "")) ?: servitech_operational_payment_method_label($key),
                "is_enabled" => filter_var($row["is_enabled"] ?? true, FILTER_VALIDATE_BOOLEAN),
                "disabled_reason" => trim((string)($row["disabled_reason"] ?? "")),
                "updated_by" => $row["updated_by"] ?? null,
                "updated_at" => $row["updated_at"] ?? null,
            ];
        }
    } catch (Throwable $exception) {
        error_log("operational payment controls fetch failed: " . $exception->getMessage());
    }

    return $methods;
}

function servitech_operational_payment_enabled(PDO $pdo, string $paymentMethod): bool
{
    $key = strtolower(trim($paymentMethod));
    if ($key === "") {
        return true;
    }
    $methods = servitech_operational_fetch_payment_methods($pdo);
    return !isset($methods[$key]) || !empty($methods[$key]["is_enabled"]);
}

function servitech_operational_at_least_one_payment_method_enabled(array $methods): bool
{
    foreach (["cash", "gcash"] as $key) {
        if (!empty($methods[$key]["is_enabled"])) {
            return true;
        }
    }
    return false;
}

function servitech_operational_assert_payment_methods_safe(array $methods): void
{
    if (!servitech_operational_at_least_one_payment_method_enabled($methods)) {
        throw new DomainException("At least one payment method must remain available.");
    }
}

function servitech_operational_customer_payment_options(PDO $pdo, bool $onlineOnly = false): array
{
    $methods = servitech_operational_fetch_payment_methods($pdo);
    $options = [];
    foreach (["cash", "gcash"] as $key) {
        if ($onlineOnly && $key !== "gcash") {
            continue;
        }
        $method = $methods[$key] ?? [
            "payment_method_key" => $key,
            "payment_method_name" => servitech_operational_payment_method_label($key),
            "is_enabled" => true,
        ];
        $options[$key] = [
            "value" => $key,
            "label" => $key === "gcash" ? "GCash" : "Cash",
            "enabled" => !empty($method["is_enabled"]),
        ];
    }
    return $options;
}

function servitech_operational_assert_payment_method_available(PDO $pdo, string $paymentMethod): void
{
    $key = strtolower(trim($paymentMethod));
    if ($key === "" || servitech_operational_payment_enabled($pdo, $key)) {
        return;
    }

    throw new DomainException("This payment method is currently unavailable.");
}

function servitech_operational_fetch_service_id_for_request(PDO $pdo, string $category, string $serviceLabel, int $requestedServiceId = 0): int
{
    if ($requestedServiceId > 0) {
        return $requestedServiceId;
    }

    $kind = servitech_catalog_service_kind([
        "category" => $category,
        "name" => $serviceLabel,
    ]);
    if ($kind === "") {
        return 0;
    }

    try {
        $service = servitech_catalog_fetch_service_by_kind($pdo, $kind, true);
        return is_array($service) ? (int)($service["id"] ?? 0) : 0;
    } catch (Throwable $exception) {
        error_log("operational service id lookup failed: " . $exception->getMessage());
        return 0;
    }
}

function servitech_operational_service_closed(PDO $pdo, int $serviceId): ?array
{
    if ($serviceId <= 0 || !servitech_operational_schema_ready($pdo)) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT manual_status, closure_reason, updated_at
            FROM operational_service_settings
            WHERE service_id = :service_id
            LIMIT 1
        ");
        $stmt->execute([":service_id" => $serviceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || strtolower(trim((string)($row["manual_status"] ?? "open"))) !== "closed") {
            return null;
        }
        return [
            "service_id" => $serviceId,
            "closure_reason" => trim((string)($row["closure_reason"] ?? "")),
            "updated_at" => $row["updated_at"] ?? null,
        ];
    } catch (Throwable $exception) {
        error_log("operational service status fetch failed: " . $exception->getMessage());
        return null;
    }
}

function servitech_operational_is_document_printing_request(string $category, string $serviceLabel, int $requestedServiceId = 0, ?PDO $pdo = null): bool
{
    if (servitech_catalog_service_kind([
        "category" => $category,
        "name" => $serviceLabel,
    ]) === "document_printing") {
        return true;
    }

    if ($requestedServiceId > 0 && $pdo instanceof PDO) {
        try {
            $service = servitech_catalog_fetch_service($pdo, $requestedServiceId, false);
            return is_array($service) && servitech_catalog_service_kind($service) === "document_printing";
        } catch (Throwable $exception) {
            error_log("operational document printing lookup failed: " . $exception->getMessage());
        }
    }

    return false;
}

function servitech_operational_document_printing_unavailable_message(): string
{
    return "Document Printing is unavailable while the store is closed and GCash payment is disabled.";
}

function servitech_operational_document_printing_requires_enabled_gcash(PDO $pdo, ?array $storeAvailability = null): bool
{
    $storeAvailability = $storeAvailability ?? servitech_store_current_availability($pdo);
    $closedStoreDocumentPrinting = !empty($storeAvailability["document_printing_requires_gcash"])
        || empty($storeAvailability["regular_queue_allowed"]);

    return $closedStoreDocumentPrinting && !servitech_operational_payment_enabled($pdo, "gcash");
}

function servitech_operational_assert_service_available(PDO $pdo, string $category, string $serviceLabel, int $requestedServiceId = 0): void
{
    if (!servitech_operational_schema_ready($pdo)) {
        return;
    }

    $overall = servitech_operational_fetch_overall($pdo);
    if (!empty($overall["all_services_closed"])) {
        throw new DomainException("Services are temporarily unavailable. Please check back later.");
    }

    $serviceId = servitech_operational_fetch_service_id_for_request($pdo, $category, $serviceLabel, $requestedServiceId);
    if (servitech_operational_service_closed($pdo, $serviceId) !== null) {
        throw new DomainException("This service is temporarily unavailable. Please try again later.");
    }

    if (
        servitech_operational_is_document_printing_request($category, $serviceLabel, $requestedServiceId, $pdo)
        && servitech_operational_document_printing_requires_enabled_gcash($pdo)
    ) {
        throw new DomainException(servitech_operational_document_printing_unavailable_message());
    }
}

function servitech_operational_customer_service_unavailable(PDO $pdo, string $category, string $serviceLabel, int $requestedServiceId = 0): string
{
    try {
        servitech_operational_assert_service_available($pdo, $category, $serviceLabel, $requestedServiceId);
        return "";
    } catch (DomainException $exception) {
        return $exception->getMessage();
    }
}

function servitech_operational_redirect_customer_unavailable_service(
    PDO $pdo,
    string $category,
    string $serviceLabel,
    string $redirectPath,
    int $requestedServiceId = 0
): void {
    $message = servitech_operational_customer_service_unavailable($pdo, $category, $serviceLabel, $requestedServiceId);
    if ($message === "") {
        return;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION["servitech_customer_toast"] = [
        "type" => "error",
        "tone" => "error",
        "message" => $message,
    ];
    header("Location: " . servitech_url($redirectPath), true, 302);
    exit;
}
