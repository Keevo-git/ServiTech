param(
    [string]$DatabaseUrl = $env:SUPABASE_DB_URL,
    [string]$UploadRoot = $env:SERVITECH_PRIVATE_UPLOAD_DIR,
    [string]$ProjectRef = $env:SUPABASE_PROJECT_REF,
    [string]$ManagementAccessToken = $env:SUPABASE_ACCESS_TOKEN,
    [string]$PostgresBin = $env:POSTGRES_BIN,
    [string]$OutputRoot = ""
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($DatabaseUrl)) {
    throw "Set SUPABASE_DB_URL to the exact Supabase session-pooler connection string."
}

if ([string]::IsNullOrWhiteSpace($OutputRoot)) {
    $OutputRoot = Join-Path $PSScriptRoot "..\backups"
}

$timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$backupDir = Join-Path $OutputRoot "supabase-$timestamp"
New-Item -ItemType Directory -Path $backupDir -Force | Out-Null

function Require-Command([string]$Name) {
    if (-not [string]::IsNullOrWhiteSpace($PostgresBin)) {
        $candidate = Join-Path $PostgresBin "$Name.exe"
        if (Test-Path -LiteralPath $candidate -PathType Leaf) {
            return (Resolve-Path -LiteralPath $candidate).Path
        }
    }

    $command = Get-Command $Name -ErrorAction SilentlyContinue
    if (-not $command) {
        throw "$Name is required. Install PostgreSQL command-line tools, then set POSTGRES_BIN to their bin directory or pass -PostgresBin."
    }
    return $command.Source
}

$pgDump = Require-Command "pg_dump"
$pgRestore = Require-Command "pg_restore"

$fullDump = Join-Path $backupDir "servitech-full.dump"
$schemaDump = Join-Path $backupDir "servitech-schema.sql"
$dataDump = Join-Path $backupDir "servitech-data.sql"
$restoreList = Join-Path $backupDir "servitech-restore-list.txt"

& $pgDump $DatabaseUrl --format=custom --no-owner --file=$fullDump
if ($LASTEXITCODE -ne 0) { throw "Full pg_dump failed." }

& $pgDump $DatabaseUrl --schema-only --no-owner --no-privileges --file=$schemaDump
if ($LASTEXITCODE -ne 0) { throw "Schema pg_dump failed." }

& $pgDump $DatabaseUrl --data-only --no-owner --inserts --file=$dataDump
if ($LASTEXITCODE -ne 0) { throw "Data pg_dump failed." }

& $pgRestore --list $fullDump | Set-Content -LiteralPath $restoreList -Encoding UTF8
if ($LASTEXITCODE -ne 0) { throw "pg_restore validation failed." }

$authConfigExported = $false
if (
    -not [string]::IsNullOrWhiteSpace($ProjectRef) -and
    -not [string]::IsNullOrWhiteSpace($ManagementAccessToken)
) {
    $authConfig = Invoke-RestMethod `
        -Uri "https://api.supabase.com/v1/projects/$ProjectRef/config/auth" `
        -Method Get `
        -Headers @{ Authorization = "Bearer $ManagementAccessToken" }
    $authConfig |
        ConvertTo-Json -Depth 20 |
        Set-Content -LiteralPath (Join-Path $backupDir "supabase-auth-config.json") -Encoding UTF8
    $authConfigExported = $true
} else {
    @"
Supabase Auth service configuration was not exported.
Set temporary SUPABASE_PROJECT_REF and SUPABASE_ACCESS_TOKEN environment variables,
or export and retain the Auth settings manually before approving production migration.
"@ | Set-Content -LiteralPath (Join-Path $backupDir "auth-config-warning.txt") -Encoding UTF8
}

$uploadInventory = Join-Path $backupDir "upload-inventory.csv"
if (-not [string]::IsNullOrWhiteSpace($UploadRoot) -and (Test-Path -LiteralPath $UploadRoot)) {
    $resolvedUploadRoot = (Resolve-Path -LiteralPath $UploadRoot).Path
    Get-ChildItem -LiteralPath $resolvedUploadRoot -File -Recurse |
        ForEach-Object {
            [PSCustomObject]@{
                RelativePath = $_.FullName.Substring($resolvedUploadRoot.Length).TrimStart("\", "/")
                Bytes = $_.Length
                LastWriteUtc = $_.LastWriteTimeUtc.ToString("o")
                Sha256 = (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
            }
        } |
        Export-Csv -LiteralPath $uploadInventory -NoTypeInformation -Encoding UTF8
} else {
    "Upload root was not configured or did not exist at backup time." |
        Set-Content -LiteralPath (Join-Path $backupDir "upload-inventory-warning.txt") -Encoding UTF8
}

$manifest = Get-ChildItem -LiteralPath $backupDir -File |
    Where-Object { $_.Name -ne "manifest.json" } |
    ForEach-Object {
        [PSCustomObject]@{
            File = $_.Name
            Bytes = $_.Length
            Sha256 = (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
        }
    }

[PSCustomObject]@{
    CreatedAtUtc = (Get-Date).ToUniversalTime().ToString("o")
    BackupDirectory = (Resolve-Path -LiteralPath $backupDir).Path
    Database = @{
        FullDump = "servitech-full.dump"
        SchemaDump = "servitech-schema.sql"
        DataDump = "servitech-data.sql"
        RestoreList = "servitech-restore-list.txt"
    }
    UploadRoot = $UploadRoot
    AuthConfigExported = $authConfigExported
    Files = $manifest
} | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath (Join-Path $backupDir "manifest.json") -Encoding UTF8

Write-Output "Backup completed and validated: $backupDir"
if (-not $authConfigExported) {
    Write-Warning "Database backup succeeded, but Supabase Auth service configuration is still required."
}
