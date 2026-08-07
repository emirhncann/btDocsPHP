-- 012_documents_notes.sql

IF COL_LENGTH('dbo.Documents', 'notes') IS NULL
BEGIN
    ALTER TABLE dbo.Documents
        ADD notes NVARCHAR(MAX) NULL;
END
GO
