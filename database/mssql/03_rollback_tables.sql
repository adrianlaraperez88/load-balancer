-- ============================================================================
-- ISG Load Balancer: MSSQL Rollback Script
-- Target RDBMS: Microsoft SQL Server
-- Description: Drops request_metrics, ip_rules, and upstream_nodes tables
--              in reverse foreign-key order.
-- WARNING: This will permanently delete all cluster node configurations
--          and request metrics. Run only when destroying the schema.
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

-- 1. Drop request_metrics
IF OBJECT_ID(N'dbo.request_metrics', N'U') IS NOT NULL
BEGIN
    DROP TABLE dbo.request_metrics;
    PRINT 'Dropped table: dbo.request_metrics';
END
GO

-- 2. Drop ip_rules
IF OBJECT_ID(N'dbo.ip_rules', N'U') IS NOT NULL
BEGIN
    DROP TABLE dbo.ip_rules;
    PRINT 'Dropped table: dbo.ip_rules';
END
GO

-- 3. Drop upstream_nodes
IF OBJECT_ID(N'dbo.upstream_nodes', N'U') IS NOT NULL
BEGIN
    DROP TABLE dbo.upstream_nodes;
    PRINT 'Dropped table: dbo.upstream_nodes';
END
GO

-- 4. Clean migration records if applicable
IF OBJECT_ID(N'dbo.migrations', N'U') IS NOT NULL
BEGIN
    DELETE FROM dbo.migrations WHERE migration LIKE '%create_upstream_nodes_table%';
    DELETE FROM dbo.migrations WHERE migration LIKE '%create_ip_rules_table%';
    DELETE FROM dbo.migrations WHERE migration LIKE '%create_request_metrics_table%';
    PRINT 'Removed migration entries from dbo.migrations';
END
GO

PRINT '============================================================================';
PRINT 'ISG Load Balancer MSSQL Schema successfully rolled back!';
PRINT '============================================================================';
GO
