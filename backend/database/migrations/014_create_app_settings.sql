-- 014_create_app_settings.sql
-- Tek satırlık sistem ayarları (firma bilgisi)

IF OBJECT_ID(N'dbo.AppSettings', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.AppSettings (
        id INT NOT NULL CONSTRAINT PK_AppSettings PRIMARY KEY,
        firma_unvani NVARCHAR(200) NULL,
        firma_adres NVARCHAR(500) NULL,
        firma_telefon NVARCHAR(50) NULL,
        updated_at DATETIME NULL,
        updated_by INT NULL,
        CONSTRAINT CK_AppSettings_SingleRow CHECK (id = 1)
    );

    ALTER TABLE dbo.AppSettings
        ADD CONSTRAINT FK_AppSettings_UpdatedBy
            FOREIGN KEY (updated_by) REFERENCES dbo.Users (id);

    INSERT INTO dbo.AppSettings (id, firma_unvani, firma_adres, firma_telefon)
    VALUES (1, NULL, NULL, NULL);
END
GO

IF OBJECT_ID(N'dbo.AppSettings', N'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM dbo.AppSettings WHERE id = 1)
BEGIN
    INSERT INTO dbo.AppSettings (id, firma_unvani, firma_adres, firma_telefon)
    VALUES (1, NULL, NULL, NULL);
END
GO
