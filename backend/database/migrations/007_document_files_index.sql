-- 007_document_files_index.sql
-- DocumentFiles tablosu 003'te oluşturuldu; yoksa oluştur, composite index ekle.

IF OBJECT_ID(N'dbo.DocumentFiles', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.DocumentFiles (
        id INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_DocumentFiles PRIMARY KEY,
        document_id INT NOT NULL,
        file_path NVARCHAR(500) NOT NULL,
        file_type VARCHAR(30) NOT NULL,
        version INT NULL,
        is_current BIT NOT NULL CONSTRAINT DF_DocumentFiles_is_current DEFAULT (1),
        original_name NVARCHAR(255) NOT NULL,
        file_size BIGINT NOT NULL,
        uploaded_by INT NOT NULL,
        uploaded_at DATETIME NOT NULL CONSTRAINT DF_DocumentFiles_uploaded_at DEFAULT (GETDATE()),
        CONSTRAINT CK_DocumentFiles_file_type CHECK (file_type IN ('photo', 'attachment', 'signed_document')),
        CONSTRAINT FK_DocumentFiles_document_id FOREIGN KEY (document_id) REFERENCES dbo.Documents (id),
        CONSTRAINT FK_DocumentFiles_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES dbo.Users (id)
    );

    CREATE INDEX IX_DocumentFiles_document_id ON dbo.DocumentFiles (document_id);
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_DocumentFiles_doc_type_current'
      AND object_id = OBJECT_ID(N'dbo.DocumentFiles')
)
BEGIN
    CREATE INDEX IX_DocumentFiles_doc_type_current
        ON dbo.DocumentFiles (document_id, file_type, is_current);
END
GO
