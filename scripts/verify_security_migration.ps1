$ErrorActionPreference = "Stop"
$root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$migrations = @(
    (Join-Path $root "database\migrations\20260612_add_supabase_auth_rls_foundation.sql"),
    (Join-Path $root "database\migrations\20260624_harden_supabase_auth_cutover.sql")
)

$runtimePaths = @(
    (Join-Path $root "api"),
    (Join-Path $root "auth"),
    (Join-Path $root "components"),
    (Join-Path $root "config"),
    (Join-Path $root "pages")
)

$runtimeDdl = Get-ChildItem -Path $runtimePaths -Recurse -Filter *.php |
    Select-String -Pattern "\b(ALTER\s+TABLE|CREATE\s+TABLE|CREATE\s+INDEX|DROP\s+TABLE|DROP\s+INDEX)\b"
if ($runtimeDdl) {
    $runtimeDdl | ForEach-Object { Write-Error "Runtime DDL: $($_.Path):$($_.LineNumber)" }
    throw "Runtime database DDL is not allowed."
}

$destructiveMigration = Select-String -Path $migrations -Pattern "\b(DROP\s+(TABLE|SCHEMA|COLUMN)|DELETE\s+FROM|TRUNCATE|RENAME)\b"
if ($destructiveMigration) {
    $destructiveMigration | ForEach-Object { Write-Error "Destructive migration statement: $($_.LineNumber)" }
    throw "The additive foundation migration contains a destructive statement."
}

$clientFiles = Get-ChildItem -Path (Join-Path $root "assets") -Recurse -File
$serviceRoleLeak = $clientFiles | Select-String -Pattern "SUPABASE_SERVICE_ROLE_KEY|service_role"
if ($serviceRoleLeak) {
    throw "A service-role reference was found in a client asset."
}

& php (Join-Path $root "tests\security_migration_test.php")
if ($LASTEXITCODE -ne 0) {
    throw "Security migration PHP tests failed."
}

Write-Output "ServiTech security migration verification passed."
