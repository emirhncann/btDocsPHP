-- 018_app_settings_default_belge_tipi.sql
-- Belge aramasında varsayılan belge tipi (tumu / siparis / irsaliye / fatura)

IF COL_LENGTH('dbo.AppSettings', 'default_belge_tipi') IS NULL
BEGIN
    ALTER TABLE dbo.AppSettings
        ADD default_belge_tipi VARCHAR(20) NOT NULL CONSTRAINT DF_AppSettings_default_belge_tipi DEFAULT 'tumu';
END
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE name = 'CK_AppSettings_default_belge_tipi'
      AND parent_object_id = OBJECT_ID(N'dbo.AppSettings')
)
BEGIN
    ALTER TABLE dbo.AppSettings
        ADD CONSTRAINT CK_AppSettings_default_belge_tipi
        CHECK (default_belge_tipi IN ('tumu', 'siparis', 'irsaliye', 'fatura'));
END
GO
