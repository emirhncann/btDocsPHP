-- 002_create_documents.sql
IF OBJECT_ID(N'dbo.Documents', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Documents (
        id INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_Documents PRIMARY KEY,
        uid UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_Documents_uid DEFAULT (NEWID()),
        firma_no INT NOT NULL,
        donem_no INT NOT NULL,
        cari_kod NVARCHAR(50) NOT NULL,
        belge_tipi VARCHAR(20) NOT NULL,
        logo_logicalref INT NOT NULL,
        status VARCHAR(50) NOT NULL,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL CONSTRAINT DF_Documents_created_at DEFAULT (GETDATE()),
        CONSTRAINT UQ_Documents_uid UNIQUE (uid),
        CONSTRAINT CK_Documents_belge_tipi CHECK (belge_tipi IN ('siparis', 'irsaliye', 'fatura')),
        CONSTRAINT FK_Documents_created_by FOREIGN KEY (created_by) REFERENCES dbo.Users (id)
    );

    CREATE INDEX IX_Documents_logo_logicalref ON dbo.Documents (logo_logicalref);
END
GO
