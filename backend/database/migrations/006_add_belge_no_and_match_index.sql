-- 006_add_belge_no_and_match_index.sql
-- Match idempotency + klasör yolu için belge_no

IF COL_LENGTH('dbo.Documents', 'belge_no') IS NULL
BEGIN
    ALTER TABLE dbo.Documents
        ADD belge_no NVARCHAR(50) NULL;
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'UQ_Documents_logo_match'
      AND object_id = OBJECT_ID(N'dbo.Documents')
)
BEGIN
    CREATE UNIQUE INDEX UQ_Documents_logo_match
        ON dbo.Documents (firma_no, donem_no, belge_tipi, logo_logicalref);
END
GO
