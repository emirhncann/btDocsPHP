-- 010_stats_indexes.sql
-- Dashboard istatistik sorguları için indeks (uploaded_at / created_at)

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_DocumentFiles_uploaded_at'
      AND object_id = OBJECT_ID(N'dbo.DocumentFiles')
)
BEGIN
    CREATE INDEX IX_DocumentFiles_uploaded_at
        ON dbo.DocumentFiles (uploaded_at DESC)
        INCLUDE (file_type, document_id, uploaded_by);
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_DocumentFiles_type_current'
      AND object_id = OBJECT_ID(N'dbo.DocumentFiles')
)
BEGIN
    CREATE INDEX IX_DocumentFiles_type_current
        ON dbo.DocumentFiles (file_type, is_current);
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_Documents_created_at'
      AND object_id = OBJECT_ID(N'dbo.Documents')
)
BEGIN
    CREATE INDEX IX_Documents_created_at
        ON dbo.Documents (created_at DESC);
END
GO
