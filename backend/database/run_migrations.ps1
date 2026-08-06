# App DB migration'larını sırayla çalıştırır.
# Kullanım: .\database\run_migrations.ps1
# Opsiyonel: .\database\run_migrations.ps1 -Server "host" -Database "BTDOCS_DB" -User "sa" -Password "..."

param(
    [string]$Server,
    [string]$Database,
    [string]$User,
    [string]$Password,
    [switch]$TrustedConnection
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$envFile = Join-Path $root "config\.env"

function Get-EnvValue([string]$key) {
    if (-not (Test-Path $envFile)) { return $null }
    $line = Get-Content $envFile | Where-Object { $_ -match "^\s*$key\s*=" } | Select-Object -First 1
    if (-not $line) { return $null }
    return ($line -split "=", 2)[1].Trim()
}

if (-not $Server)   { $Server   = Get-EnvValue "APP_DB_HOST" }
if (-not $Database) { $Database = Get-EnvValue "APP_DB_NAME" }
if (-not $User)     { $User     = Get-EnvValue "APP_DB_USER" }
if (-not $Password) { $Password = Get-EnvValue "APP_DB_PASS" }

if (-not $Server -or -not $Database) {
    throw "APP_DB_HOST ve APP_DB_NAME gerekli (.env veya parametre)."
}

$migrations = Get-ChildItem (Join-Path $PSScriptRoot "migrations\*.sql") | Sort-Object Name
if ($migrations.Count -eq 0) {
    throw "Migration dosyası bulunamadı."
}

foreach ($file in $migrations) {
    Write-Host ">>> $($file.Name)" -ForegroundColor Cyan
    if ($TrustedConnection -or [string]::IsNullOrWhiteSpace($User)) {
        & sqlcmd -S $Server -d $Database -E -C -b -i $file.FullName
    } else {
        & sqlcmd -S $Server -d $Database -U $User -P $Password -C -b -i $file.FullName
    }
    if ($LASTEXITCODE -ne 0) {
        throw "Migration başarısız: $($file.Name)"
    }
}

Write-Host "Tüm migration'lar başarıyla uygulandı." -ForegroundColor Green
