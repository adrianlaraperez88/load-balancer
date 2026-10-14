-- ============================================================================
-- ISG Load Balancer: MSSQL Seed Initial Backend Cluster
-- Target RDBMS: Microsoft SQL Server
-- Description: Registers initial backend processing servers (app01, app02, ..., appN)
--              for the front gateway server (app03) to dispatch to.
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

-- Adjust URLs and weights according to your internal network layout:
-- e.g. http://10.0.0.11:8000 or http://app01.internal

MERGE dbo.upstream_nodes AS target
USING (
    VALUES 
        (N'app01', N'http://10.0.0.11:8000', 1, 1, 0),
        (N'app02', N'http://10.0.0.12:8000', 1, 1, 0),
        (N'app04', N'http://10.0.0.14:8000', 2, 1, 0)
) AS source ([name], [url], [weight], [is_active], [is_primary])
ON (target.[name] = source.[name])
WHEN MATCHED THEN
    UPDATE SET 
        target.[url] = source.[url],
        target.[weight] = source.[weight],
        target.[is_active] = source.[is_active],
        target.[is_primary] = source.[is_primary],
        target.[updated_at] = SYSUTCDATETIME()
WHEN NOT MATCHED THEN
    INSERT ([name], [url], [weight], [is_active], [is_primary], [created_at], [updated_at])
    VALUES (source.[name], source.[url], source.[weight], source.[is_active], source.[is_primary], SYSUTCDATETIME(), SYSUTCDATETIME());
GO

PRINT 'Initial backend cluster nodes merged into dbo.upstream_nodes.';

-- Verify current cluster
SELECT [id], [name], [url], [weight], [is_active], [is_primary], [created_at] 
FROM dbo.upstream_nodes;
GO
