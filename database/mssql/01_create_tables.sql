-- ============================================================================
-- ISG Load Balancer: MSSQL DDL Deployment Script
-- Target RDBMS: Microsoft SQL Server (2016, 2017, 2019, 2022, Azure SQL Database)
-- Description: Creates upstream_nodes, ip_rules, and request_metrics tables
--              with all required indexes, defaults, and foreign keys.
-- Idempotency: Safe to re-run; checks OBJECT_ID before creating objects.
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

-- ----------------------------------------------------------------------------
-- 1. Table: upstream_nodes
-- Stores backend processing nodes (app01, app02, ..., appN)
-- ----------------------------------------------------------------------------
IF OBJECT_ID(N'dbo.upstream_nodes', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.upstream_nodes (
        [id]            BIGINT IDENTITY(1,1) NOT NULL,
        [name]          NVARCHAR(255)        NOT NULL,
        [url]           NVARCHAR(2048)       NOT NULL,
        [weight]        INT                  NOT NULL CONSTRAINT DF_upstream_nodes_weight DEFAULT (1),
        [is_active]     BIT                  NOT NULL CONSTRAINT DF_upstream_nodes_is_active DEFAULT (1),
        [is_primary]    BIT                  NOT NULL CONSTRAINT DF_upstream_nodes_is_primary DEFAULT (0),
        [created_at]    DATETIME2(0)         NULL,
        [updated_at]    DATETIME2(0)         NULL,

        CONSTRAINT PK_upstream_nodes PRIMARY KEY CLUSTERED ([id] ASC)
    );

    PRINT 'Created table: dbo.upstream_nodes';
END
ELSE
BEGIN
    PRINT 'Table dbo.upstream_nodes already exists. Skipping.';
END
GO

-- Performance Indexes for upstream_nodes
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_upstream_nodes_is_active' AND object_id = OBJECT_ID(N'dbo.upstream_nodes'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_upstream_nodes_is_active
        ON dbo.upstream_nodes ([is_active])
        INCLUDE ([id], [name], [url], [weight], [is_primary]);
    PRINT 'Created index: IX_upstream_nodes_is_active';
END
GO

-- ----------------------------------------------------------------------------
-- 2. Table: ip_rules
-- Stores static IP routing bindings to designated upstream nodes
-- ----------------------------------------------------------------------------
IF OBJECT_ID(N'dbo.ip_rules', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.ip_rules (
        [id]                BIGINT IDENTITY(1,1) NOT NULL,
        [ip_address]        NVARCHAR(45)         NOT NULL,
        [upstream_node_id]  BIGINT               NOT NULL,
        [created_at]        DATETIME2(0)         NULL,
        [updated_at]        DATETIME2(0)         NULL,

        CONSTRAINT PK_ip_rules PRIMARY KEY CLUSTERED ([id] ASC),
        CONSTRAINT UQ_ip_rules_ip_address UNIQUE NONCLUSTERED ([ip_address] ASC),
        CONSTRAINT FK_ip_rules_upstream_node_id FOREIGN KEY ([upstream_node_id])
            REFERENCES dbo.upstream_nodes ([id])
            ON DELETE CASCADE
    );

    PRINT 'Created table: dbo.ip_rules';
END
ELSE
BEGIN
    PRINT 'Table dbo.ip_rules already exists. Skipping.';
END
GO

-- ----------------------------------------------------------------------------
-- 3. Table: request_metrics
-- High-scale asynchronous request and latency tracking
-- ----------------------------------------------------------------------------
IF OBJECT_ID(N'dbo.request_metrics', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.request_metrics (
        [id]                BIGINT IDENTITY(1,1) NOT NULL,
        [incoming_ip]       NVARCHAR(45)         NOT NULL,
        [upstream_node_id]  BIGINT               NULL,
        [method]            NVARCHAR(10)         NOT NULL,
        [uri]               NVARCHAR(2048)       NOT NULL,
        [status_code]       INT                  NOT NULL,
        [response_time_ms]  INT                  NOT NULL,
        [created_at]        DATETIME2(0)         NOT NULL CONSTRAINT DF_request_metrics_created_at DEFAULT (SYSUTCDATETIME()),

        CONSTRAINT PK_request_metrics PRIMARY KEY CLUSTERED ([id] ASC),
        CONSTRAINT FK_request_metrics_upstream_node_id FOREIGN KEY ([upstream_node_id])
            REFERENCES dbo.upstream_nodes ([id])
            ON DELETE SET NULL
    );

    PRINT 'Created table: dbo.request_metrics';
END
ELSE
BEGIN
    PRINT 'Table dbo.request_metrics already exists. Skipping.';
END
GO

-- Performance Indexes for request_metrics
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_request_metrics_upstream_node_id' AND object_id = OBJECT_ID(N'dbo.request_metrics'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_request_metrics_upstream_node_id
        ON dbo.request_metrics ([upstream_node_id])
        INCLUDE ([status_code], [response_time_ms], [created_at]);
    PRINT 'Created index: IX_request_metrics_upstream_node_id';
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_request_metrics_created_at' AND object_id = OBJECT_ID(N'dbo.request_metrics'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_request_metrics_created_at
        ON dbo.request_metrics ([created_at] DESC);
    PRINT 'Created index: IX_request_metrics_created_at';
END
GO

-- ----------------------------------------------------------------------------
-- 4. Optional: Register migrations in Laravel's migrations table
-- If your host application uses a [migrations] table, this prevents
-- 'php artisan migrate' from attempting to re-run these migrations.
-- ----------------------------------------------------------------------------
IF OBJECT_ID(N'dbo.migrations', N'U') IS NOT NULL
BEGIN
    DECLARE @batch INT;
    SELECT @batch = ISNULL(MAX(batch), 0) + 1 FROM dbo.migrations;

    IF NOT EXISTS (SELECT 1 FROM dbo.migrations WHERE migration LIKE '%create_upstream_nodes_table%')
    BEGIN
        INSERT INTO dbo.migrations (migration, batch) VALUES ('2024_01_01_000001_create_upstream_nodes_table', @batch);
        PRINT 'Registered create_upstream_nodes_table in dbo.migrations';
    END

    IF NOT EXISTS (SELECT 1 FROM dbo.migrations WHERE migration LIKE '%create_ip_rules_table%')
    BEGIN
        INSERT INTO dbo.migrations (migration, batch) VALUES ('2024_01_01_000002_create_ip_rules_table', @batch);
        PRINT 'Registered create_ip_rules_table in dbo.migrations';
    END

    IF NOT EXISTS (SELECT 1 FROM dbo.migrations WHERE migration LIKE '%create_request_metrics_table%')
    BEGIN
        INSERT INTO dbo.migrations (migration, batch) VALUES ('2024_01_01_000003_create_request_metrics_table', @batch);
        PRINT 'Registered create_request_metrics_table in dbo.migrations';
    END
END
GO

PRINT '============================================================================';
PRINT 'ISG Load Balancer MSSQL Schema successfully deployed!';
PRINT '============================================================================';
GO
