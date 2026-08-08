-- 016_app_settings_firma_logo.sql
-- AppSettings: firma logosu yolu (DOC_STORAGE_ROOT/_brand/company-logo.*)

IF COL_LENGTH('dbo.AppSettings', 'firma_logo_path') IS NULL
BEGIN
    ALTER TABLE dbo.AppSettings
        ADD firma_logo_path NVARCHAR(300) NULL;
END
GO
