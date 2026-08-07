-- 011_document_files_soft_delete.sql
-- Soft delete alanları (kanıt arşivi — fiziksel silme yok)

IF COL_LENGTH('dbo.DocumentFiles', 'is_deleted') IS NULL
BEGIN
    ALTER TABLE dbo.DocumentFiles
        ADD is_deleted BIT NOT NULL
            CONSTRAINT DF_DocumentFiles_is_deleted DEFAULT (0);
END
GO

IF COL_LENGTH('dbo.DocumentFiles', 'deleted_at') IS NULL
BEGIN
    ALTER TABLE dbo.DocumentFiles
        ADD deleted_at DATETIME NULL;
END
GO

IF COL_LENGTH('dbo.DocumentFiles', 'deleted_by') IS NULL
BEGIN
    ALTER TABLE dbo.DocumentFiles
        ADD deleted_by INT NULL;
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.foreign_keys
    WHERE name = N'FK_DocumentFiles_DeletedBy'
      AND parent_object_id = OBJECT_ID(N'dbo.DocumentFiles')
)
BEGIN
    ALTER TABLE dbo.DocumentFiles
        ADD CONSTRAINT FK_DocumentFiles_DeletedBy
            FOREIGN KEY (deleted_by) REFERENCES dbo.Users (id);
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_DocumentFiles_is_deleted'
      AND object_id = OBJECT_ID(N'dbo.DocumentFiles')
)
BEGIN
    CREATE INDEX IX_DocumentFiles_is_deleted
        ON dbo.DocumentFiles (document_id, is_deleted);
END
GO
