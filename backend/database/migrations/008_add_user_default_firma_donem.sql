-- 008_add_user_default_firma_donem.sql
IF COL_LENGTH('dbo.Users', 'default_firma_no') IS NULL
BEGIN
    ALTER TABLE dbo.Users ADD default_firma_no INT NULL;
END
GO

IF COL_LENGTH('dbo.Users', 'default_donem_no') IS NULL
BEGIN
    ALTER TABLE dbo.Users ADD default_donem_no INT NULL;
END
GO
