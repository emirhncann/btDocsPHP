# btDocs Backend

Logo ERP entegrasyonlu döküman yönetim API'si (Slim 4 + MSSQL).

## Kurulum

```powershell
composer install
copy config\.env.example config\.env
# .env değerlerini doldurun
.\database\run_migrations.ps1
php seed_admin.php --username=admin --password="GucluSifre123"
```

Admin panel: `/admin/`  
Public belge: `/belge/{uid}`

## Yerel sunucu

PHP varsayılan `upload_max_filesize` çoğu kurulumda **2M** gelir; uygulama limiti 20MB
iken PHP önce dosyayı reddeder. Yerelde:

```powershell
.\serve.ps1
```

Bu komut varsayılan `php.ini` (pdo_sqlsrv vb.) ile çalışır ve yalnızca
`upload_max_filesize=22M` / `post_max_size=25M` yükseltir.
**`php -c php-dev.ini` kullanmayın** — tüm eklentileri düşürüp `could not find driver`
hatası üretir.

IIS'de `web.config` içinde `maxAllowedContentLength` ~22MB ayarlıdır; PHP-CGI
için sistem `php.ini` içinde de en az 22M kullanın.

## Güvenlik notları (production)

### JWT_SECRET
`config/.env.example` içindeki değer yalnızca placeholder'dır. Deployment'ta mutlaka
güçlü/rastgele bir secret kullanın (örn. 32+ byte):

```powershell
[Convert]::ToBase64String((1..48 | ForEach-Object { Get-Random -Maximum 256 }) -as [byte[]])
```

JWT süre varsayılanı **8 saat** (`JWT_TTL_HOURS`).

### HTTPS
Bu proje şu an yerel/HTTP ortamı için yapılandırılmıştır; `web.config` içinde
HTTP→HTTPS zorunlu yönlendirme yoktur. İnternete açılacak bir kurulumda IIS
seviyesinde HTTPS (SSL bağlama + redirect) ayrıca eklenmelidir.

### CORS
Production'da wildcard (`*`) kullanılmaz. İzin verilen origin'leri virgülle yazın:

```
CORS_ALLOWED_ORIGINS=https://admin.sirketiniz.local
```

Admin panel aynı origin'den servis ediliyorsa ekstra CORS gerekmez.
Android native istemciler CORS'tan etkilenmez.

### Rate limit
- Login: 10 başarısız deneme / 15 dk → HTTP 429
- Public `/belge/*`: IP başına 30 istek / dk → HTTP 429

Sayaçlar `storage/ratelimit/` altında dosya olarak tutulur.

### APP_ENV
`APP_ENV=production` iken API hata yanıtlarında stack trace / DB detayı dönmez;
detaylar sunucu loguna yazılır.

## Yedekleme (backup)

App DB (`BTDOCS_DB`) ile `DOC_STORAGE_ROOT` (DocStorage) klasörü **birlikte**
yedeklenmelidir:

1. MSSQL backup: `BTDOCS_DB` (full + tercihen transaction log)
2. Aynı zaman penceresinde DocStorage klasörünün dosya yedeği

`DocumentFiles.file_path` DocStorage içindeki göreli yollara işaret eder; yalnızca
DB veya yalnızca dosya yedeklenirse referans bütünlüğü bozulur. Geri yüklemede
önce DB, sonra DocStorage'ı aynı kesit noktasından restore edin (veya tersi ama
eş zamanlı snapshot tercih edilir).

## Acil Durum: .env Geri Yükleme

Eğer Sistem Ayarları ekranından yanlış bir veritabanı bilgisi kaydedilir
ve panel erişilemez hale gelirse:

1. Sunucuya doğrudan erişin (RDP/dosya paylaşımı)
2. `config/backups/` klasörüne gidin
3. En son tarihli `.env.backup-{timestamp}` dosyasını bulun (dosya adındaki
   timestamp'e göre sıralayın; en yenisi, son değişiklikten hemen önceki
   sağlıklı halinizdir)
4. Bu dosyayı `config/.env` üzerine kopyalayın (üzerine yazın)
5. Bir sonraki istekte sistem otomatik olarak eski, çalışan ayarları
   kullanmaya başlar — IIS restart gerekmez

Yedekler otomatik silinmez, zamanla birikir — periyodik olarak eski
yedekleri (örn. 30 günden eski) elle temizleyebilirsiniz.

Not: Sistem Ayarları kaydından önce APP_DB değişikliği için sunucu otomatik
bağlantı + `Users` tablosu doğrulaması yapar; başarısızsa `.env` hiç
değiştirilmez. Bu acil yol, çoğunlukla elle bozulmuş `.env` veya olağanüstü
durumlar içindir.

## Logo şema
Tablo adlandırma ve TRCODE ayarları için bkz. `database/LOGO_SCHEMA.md`.
