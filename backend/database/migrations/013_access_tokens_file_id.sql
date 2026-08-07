-- 013_access_tokens_file_id.sql
-- Token kapsamı: file_id NULL = belge sayfası; dolu = tek dosya viewer

IF COL_LENGTH('dbo.AccessTokens', 'file_id') IS NULL
BEGIN
    ALTER TABLE dbo.AccessTokens
        ADD file_id INT NULL;
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.foreign_keys
    WHERE name = N'FK_AccessTokens_File'
      AND parent_object_id = OBJECT_ID(N'dbo.AccessTokens')
)
BEGIN
    ALTER TABLE dbo.AccessTokens
        ADD CONSTRAINT FK_AccessTokens_File
            FOREIGN KEY (file_id) REFERENCES dbo.DocumentFiles (id);
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_AccessTokens_file_id'
      AND object_id = OBJECT_ID(N'dbo.AccessTokens')
)
BEGIN
    CREATE INDEX IX_AccessTokens_file_id
        ON dbo.AccessTokens (file_id);
END
GO
