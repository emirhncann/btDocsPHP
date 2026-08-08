-- 015_app_settings_vkn_email.sql
-- AppSettings: firma VKN + e-posta (belge şablonu Firma Bilgileri)

IF COL_LENGTH('dbo.AppSettings', 'firma_vkn') IS NULL
BEGIN
    ALTER TABLE dbo.AppSettings
        ADD firma_vkn NVARCHAR(20) NULL;
END
GO

IF COL_LENGTH('dbo.AppSettings', 'firma_email') IS NULL
BEGIN
    ALTER TABLE dbo.AppSettings
        ADD firma_email NVARCHAR(200) NULL;
END
GO
