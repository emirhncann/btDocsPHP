-- 009_rename_saha_role_to_user.sql
IF EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE name = N'CK_Users_role'
      AND parent_object_id = OBJECT_ID(N'dbo.Users')
)
BEGIN
    ALTER TABLE dbo.Users DROP CONSTRAINT CK_Users_role;
END
GO

UPDATE dbo.Users
SET role = 'user'
WHERE role = 'saha';
GO

ALTER TABLE dbo.Users
ADD CONSTRAINT CK_Users_role CHECK (role IN ('admin', 'user', 'readonly'));
GO
