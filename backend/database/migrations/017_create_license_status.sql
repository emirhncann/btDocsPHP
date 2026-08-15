-- 017_create_license_status.sql
-- Lisans paneli doğrulama sonucu (tek satır)

IF OBJECT_ID(N'dbo.LicenseStatus', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.LicenseStatus (
        id INT NOT NULL CONSTRAINT PK_LicenseStatus PRIMARY KEY,
        is_valid BIT NOT NULL CONSTRAINT DF_LicenseStatus_is_valid DEFAULT (1),
        last_checked_at DATETIME NULL,
        last_successful_check_at DATETIME NULL,
        bitis_tarihi DATE NULL,
        CONSTRAINT CK_LicenseStatus_SingleRow CHECK (id = 1)
    );

    INSERT INTO dbo.LicenseStatus (id, is_valid)
    VALUES (1, 1);
END
GO
