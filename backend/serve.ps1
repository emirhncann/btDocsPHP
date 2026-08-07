# Yerel PHP built-in sunucu (20MB upload ile)
# Kullanım (backend klasöründen): .\serve.ps1
#
# ÖNEMLİ: -c php-dev.ini KULLANMAYIN — tüm php.ini'yi değiştirir ve
# pdo_sqlsrv gibi eklentileri düşürür. Bunun yerine -d ile sadece limit yükseltilir.

$ErrorActionPreference = "Stop"
$root = $PSScriptRoot
$public = Join-Path $root "public"
$router = Join-Path $public "router.php"

Write-Host "btDocs API: http://0.0.0.0:8080  (upload_max_filesize=22M, default php.ini + drivers)"
php `
  -d upload_max_filesize=22M `
  -d post_max_size=25M `
  -d memory_limit=256M `
  -S 0.0.0.0:8080 `
  -t $public `
  $router
