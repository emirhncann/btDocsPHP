-- 001_create_users.sql
IF OBJECT_ID(N'dbo.Users', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.Users (
        id INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_Users PRIMARY KEY,
        username NVARCHAR(100) NOT NULL,
        password_hash NVARCHAR(255) NOT NULL,
        full_name NVARCHAR(200) NOT NULL,
        role VARCHAR(20) NOT NULL,
        is_active BIT NOT NULL CONSTRAINT DF_Users_is_active DEFAULT (1),
        created_at DATETIME NOT NULL CONSTRAINT DF_Users_created_at DEFAULT (GETDATE()),
        CONSTRAINT UQ_Users_username UNIQUE (username),
        CONSTRAINT CK_Users_role CHECK (role IN ('admin', 'user', 'readonly'))
    );
END
GO
