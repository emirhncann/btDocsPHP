# App DB Migrations

Migration dosyaları `migrations/` altında numaralı sırayla çalıştırılmalıdır.

## Önkoşullar

- App DB oluşturulmuş olmalı (`config/.env` içindeki `APP_DB_NAME`)
- `sqlcmd` erişilebilir olmalı
- Bağlantı bilgileri `config/.env` ile aynı olmalı

## sqlcmd ile sırayla çalıştırma

`backend` klasöründen:

```powershell
# .env değerlerinizi girin
$server = "localhost"
$db     = "BTDOCS_DB"
$user   = "sa"
$pass   = "YOUR_PASSWORD"

sqlcmd -S $server -d $db -U $user -P $pass -C -i "database\migrations\001_create_users.sql"
sqlcmd -S $server -d $db -U $user -P $pass -C -i "database\migrations\002_create_documents.sql"
sqlcmd -S $server -d $db -U $user -P $pass -C -i "database\migrations\003_create_document_files.sql"
sqlcmd -S $server -d $db -U $user -P $pass -C -i "database\migrations\004_create_access_tokens.sql"
sqlcmd -S $server -d $db -U $user -P $pass -C -i "database\migrations\005_create_audit_log.sql"
```

Windows Authentication kullanıyorsanız `-U` / `-P` yerine `-E` kullanın:

```powershell
sqlcmd -S $server -d $db -E -C -i "database\migrations\001_create_users.sql"
# ... aynı sırayla diğerleri
```

Veya tüm migration'ları otomatik çalıştırmak için:

```powershell
.\database\run_migrations.ps1
```

## İlk admin kullanıcısı

Migration'lardan sonra:

```powershell
php seed_admin.php --username=admin --password="GucluSifre123" --full-name="Sistem Admin"
```
