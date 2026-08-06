-- 004_create_access_tokens.sql
IF OBJECT_ID(N'dbo.AccessTokens', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.AccessTokens (
        id INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_AccessTokens PRIMARY KEY,
        document_id INT NOT NULL,
        token NVARCHAR(255) NOT NULL,
        expires_at DATETIME NULL,
        is_revoked BIT NOT NULL CONSTRAINT DF_AccessTokens_is_revoked DEFAULT (0),
        created_at DATETIME NOT NULL CONSTRAINT DF_AccessTokens_created_at DEFAULT (GETDATE()),
        CONSTRAINT UQ_AccessTokens_token UNIQUE (token),
        CONSTRAINT FK_AccessTokens_document_id FOREIGN KEY (document_id) REFERENCES dbo.Documents (id)
    );

    CREATE INDEX IX_AccessTokens_document_id ON dbo.AccessTokens (document_id);
END
GO
