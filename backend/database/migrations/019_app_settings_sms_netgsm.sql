-- 019_app_settings_sms_netgsm.sql
-- NetGSM SMS ayarları (aktif/pasif + kimlik bilgileri)

IF COL_LENGTH('dbo.AppSettings', 'sms_enabled') IS NULL
BEGIN
    ALTER TABLE dbo.AppSettings
        ADD sms_enabled BIT NOT NULL CONSTRAINT DF_AppSettings_sms_enabled DEFAULT 0;
END
GO

IF COL_LENGTH('dbo.AppSettings', 'netgsm_username') IS NULL
BEGIN
    ALTER TABLE dbo.AppSettings
        ADD netgsm_username NVARCHAR(100) NULL;
END
GO

IF COL_LENGTH('dbo.AppSettings', 'netgsm_password') IS NULL
BEGIN
    ALTER TABLE dbo.AppSettings
        ADD netgsm_password NVARCHAR(100) NULL;
END
GO

IF COL_LENGTH('dbo.AppSettings', 'netgsm_msgheader') IS NULL
BEGIN
    ALTER TABLE dbo.AppSettings
        ADD netgsm_msgheader NVARCHAR(20) NULL;
END
GO

IF COL_LENGTH('dbo.AppSettings', 'sms_api_url') IS NULL
BEGIN
    ALTER TABLE dbo.AppSettings
        ADD sms_api_url NVARCHAR(500) NULL;
END
GO
