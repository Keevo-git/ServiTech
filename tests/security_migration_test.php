<?php

require_once __DIR__ . "/../config/supabase_auth.php";
require_once __DIR__ . "/../api/upload_helpers.php";

$failures = [];

function security_test_assert(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function security_test_jwt(array $claims): string
{
    $encode = static function (array $value): string {
        return rtrim(strtr(base64_encode(json_encode($value)), "+/", "-_"), "=");
    };
    return $encode(["alg" => "none", "typ" => "JWT"])
        . "." . $encode($claims)
        . ".signature";
}

$claims = [
    "sub" => "11111111-1111-4111-8111-111111111111",
    "role" => "authenticated",
    "exp" => time() + 3600,
];
security_test_assert(
    servitech_supabase_jwt_claims(security_test_jwt($claims)) === $claims,
    "JWT claims should decode without exposing or persisting the token."
);
security_test_assert(
    servitech_supabase_jwt_claims("invalid-token") === [],
    "Malformed JWTs must be rejected."
);

putenv("SERVITECH_REQUIRE_PRIVATE_UPLOAD_ROOT=1");
$_ENV["SERVITECH_REQUIRE_PRIVATE_UPLOAD_ROOT"] = "1";
$_SERVER["SERVITECH_REQUIRE_PRIVATE_UPLOAD_ROOT"] = "1";
$_SERVER["DOCUMENT_ROOT"] = "C:\\xampp\\htdocs";

$insideWebRootRejected = false;
putenv("SERVITECH_PRIVATE_UPLOAD_DIR=C:\\xampp\\htdocs\\ServiTech_Uploads");
$_ENV["SERVITECH_PRIVATE_UPLOAD_DIR"] = "C:\\xampp\\htdocs\\ServiTech_Uploads";
$_SERVER["SERVITECH_PRIVATE_UPLOAD_DIR"] = "C:\\xampp\\htdocs\\ServiTech_Uploads";
try {
    servitech_upload_private_dir();
} catch (RuntimeException $exception) {
    $insideWebRootRejected = str_contains($exception->getMessage(), "outside the public web root");
}
security_test_assert($insideWebRootRejected, "Uploads inside the web root must be rejected.");

putenv("SERVITECH_PRIVATE_UPLOAD_DIR=C:\\xampp\\ServiTech_Uploads");
$_ENV["SERVITECH_PRIVATE_UPLOAD_DIR"] = "C:\\xampp\\ServiTech_Uploads";
$_SERVER["SERVITECH_PRIVATE_UPLOAD_DIR"] = "C:\\xampp\\ServiTech_Uploads";
security_test_assert(
    servitech_upload_private_dir() === "C:\\xampp\\ServiTech_Uploads",
    "An absolute ServiTech_Uploads directory outside the web root should be accepted."
);

$traversalRejected = false;
try {
    servitech_upload_storage_path("../payload.php");
} catch (RuntimeException $exception) {
    $traversalRejected = true;
}
security_test_assert($traversalRejected, "Path traversal storage keys must be rejected.");

$executableRejected = false;
$temporaryExecutable = tempnam(sys_get_temp_dir(), "servitech-upload-");
if (is_string($temporaryExecutable)) {
    file_put_contents($temporaryExecutable, "<?php echo 'unsafe';");
    try {
        servitech_upload_validate_type($temporaryExecutable, "payload.php");
    } catch (DomainException $exception) {
        $executableRejected = true;
    } finally {
        @unlink($temporaryExecutable);
    }
}
security_test_assert($executableRejected, "Executable PHP uploads must be rejected.");

$loginSource = file_get_contents(__DIR__ . "/../auth/login.php") ?: "";
security_test_assert(
    !str_contains($loginSource, "servitech_supabase_admin_create_user"),
    "The login path must not auto-create Auth users from legacy credentials."
);
security_test_assert(
    !str_contains($loginSource, 'hash_equals($storedHash, $password)'),
    "The local fallback must not accept plaintext password values."
);

$registrationSource = file_get_contents(__DIR__ . "/../auth/register.php") ?: "";
security_test_assert(
    str_contains($registrationSource, '$hasSession')
        && str_contains($registrationSource, 'registered=verify'),
    "Supabase registration must handle email-confirmation responses without a session."
);

$cutoverMigration = file_get_contents(
    __DIR__ . "/../database/migrations/20260624_harden_supabase_auth_cutover.sql"
) ?: "";
foreach (["service_option_groups", "service_option_values", "service_pricing_rules", "remember_tokens"] as $rlsTable) {
    security_test_assert(
        str_contains($cutoverMigration, "ALTER TABLE public.{$rlsTable} ENABLE ROW LEVEL SECURITY"),
        "RLS must be enabled for {$rlsTable}."
    );
}
security_test_assert(
    str_contains($cutoverMigration, "public.servitech_is_trusted_backend()")
        && str_contains($cutoverMigration, "'aal2'"),
    "Cutover policies must require validated backend writes and AAL2 admin authority."
);

$htaccessExample = file_get_contents(__DIR__ . "/../.htaccess.example") ?: "";
foreach (["backups", "database", "docs", "supabase", "tests", "vendor", "legacy"] as $privatePath) {
    security_test_assert(
        str_contains($htaccessExample, "backups|database|docs|supabase|tests|vendor|legacy"),
        "The web-server example must block the {$privatePath} path."
    );
}

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Security migration unit checks passed.\n";
