# Yerel PHP built-in sunucu (20MB upload ile)
# Kullanım (backend klasöründen): .\serve.ps1
#
# ÖNEMLİ: -c php-dev.ini KULLANMAYIN — tüm php.ini'yi değiştirir ve
# pdo_sqlsrv gibi eklentileri düşürür. Bunun yerine -d ile sadece limit yükseltilir.

$ErrorActionPreference = "Stop"
$root = $PSScriptRoot
$public = Join-Path $root "public"
$router = Join-Path $public "router.php"

# Müşteri sunucusu ile aynı yol: D:\btDocs\php-error.log
# Bu makinede D: yoksa C:\btDocs üzerinden subst ile eşle.
$logDir = 'D:\btDocs'
if (-not (Test-Path 'D:\')) {
  $fallback = 'C:\btDocs'
  if (-not (Test-Path $fallback)) {
    New-Item -ItemType Directory -Path $fallback -Force | Out-Null
  }
  subst D: C:\ 2>$null
}
if (-not (Test-Path $logDir)) {
  New-Item -ItemType Directory -Path $logDir -Force | Out-Null
}
$phpTemp = Join-Path $logDir 'php-temp'
if (-not (Test-Path $phpTemp)) {
  New-Item -ItemType Directory -Path $phpTemp -Force | Out-Null
}
$phpErrorLog = Join-Path $logDir 'php-error.log'

Write-Host "btDocs API: http://0.0.0.0:8080  (upload_max_filesize=22M, default php.ini + drivers)"
Write-Host "PHP error_log: $phpErrorLog"
Write-Host "PHP upload_tmp_dir: $phpTemp"
php `
  -d upload_max_filesize=22M `
  -d post_max_size=25M `
  -d memory_limit=256M `
  -d log_errors=1 `
  -d "error_log=$phpErrorLog" `
  -d "upload_tmp_dir=$phpTemp" `
  -S 0.0.0.0:8080 `
  -t $public `
  $router
