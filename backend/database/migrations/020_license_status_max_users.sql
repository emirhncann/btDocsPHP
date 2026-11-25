-- 020_license_status_max_users.sql
-- Lisans panelinden gelen kullanıcı kotası (NULL = sınırsız)

IF COL_LENGTH('dbo.LicenseStatus', 'max_users') IS NULL
BEGIN
    ALTER TABLE dbo.LicenseStatus ADD max_users INT NULL;
END
GO
