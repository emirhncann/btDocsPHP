# btDocs lisans kontrolü

Ana mekanizma artık **kendiliğinden** çalışır: her authenticated istek
(`AuthMiddleware`) `LicenseCheckService::ensureFreshCheck()` çağırır.
Çoğu istekte yalnızca `LicenseStatus.last_checked_at` okunur; **24 saatte bir**
ilk istekte Proje C'ye HTTP doğrulama gider.

Tam doğrulama URL: `{LICENSE_PANEL_URL}/api/license-verify.php?api_key={LICENSE_API_KEY}`

Admin panelden anında zorlamak: **Ayarlar → Lisansı Kontrol Et**
veya CLI: `php bin/check_our_license.php`

## Opsiyonel: Windows Task Scheduler (ekstra güvence)

Sistem uzun süre hiç kullanılmasa bile paneli sorgulamak isterseniz
(gerekli değil — kullanılmayan sistemde lisans kilidi önemli değildir):

```powershell
$php = (Get-Command php).Source
$workdir = "C:\Users\PC\Desktop\Projeler\btDocsPHP\backend"
$script  = Join-Path $workdir "bin\check_our_license.php"
$action  = New-ScheduledTaskAction -Execute $php -Argument "`"$script`"" -WorkingDirectory $workdir
$trigger = New-ScheduledTaskTrigger -Daily -At 08:00
Register-ScheduledTask -TaskName "btDocs License Check" -Action $action -Trigger $trigger -Description "btDocs lisans (opsiyonel)"
```
