-- 005_create_audit_log.sql
IF OBJECT_ID(N'dbo.AuditLog', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.AuditLog (
        id INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_AuditLog PRIMARY KEY,
        user_id INT NULL,
        document_id INT NULL,
        action NVARCHAR(100) NOT NULL,
        ip_address VARCHAR(45) NULL,
        created_at DATETIME NOT NULL CONSTRAINT DF_AuditLog_created_at DEFAULT (GETDATE()),
        CONSTRAINT FK_AuditLog_user_id FOREIGN KEY (user_id) REFERENCES dbo.Users (id),
        CONSTRAINT FK_AuditLog_document_id FOREIGN KEY (document_id) REFERENCES dbo.Documents (id)
    );

    CREATE INDEX IX_AuditLog_user_id ON dbo.AuditLog (user_id);
    CREATE INDEX IX_AuditLog_document_id ON dbo.AuditLog (document_id);
END
GO
