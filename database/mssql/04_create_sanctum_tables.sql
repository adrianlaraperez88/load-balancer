-- ==============================================================================
-- ISG Load Balancer: MSSQL Sanctum Authentication Tables Deployment Script
-- Script: 04_create_sanctum_tables.sql
-- Purpose: Creates personal_access_tokens and users tables for Sanctum tokens
-- Compatibility: Microsoft SQL Server 2016, 2019, 2022, Azure SQL
-- Idempotency: Fully idempotent (safe to run repeatedly)
-- ==============================================================================

SET NOCOUNT ON;

-- 1. Users Table
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'users' AND schema_id = SCHEMA_ID('dbo'))
BEGIN
    PRINT 'Creating table: users...';
    CREATE TABLE dbo.users (
        id BIGINT IDENTITY(1,1) NOT NULL,
        name NVARCHAR(255) NOT NULL,
        email NVARCHAR(255) NOT NULL,
        password NVARCHAR(255) NOT NULL,
        remember_token NVARCHAR(100) NULL,
        created_at DATETIME2(0) NULL DEFAULT SYSUTCDATETIME(),
        updated_at DATETIME2(0) NULL DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_users PRIMARY KEY CLUSTERED (id),
        CONSTRAINT UQ_users_email UNIQUE NONCLUSTERED (email)
    );
    PRINT 'Table users created successfully.';
END
ELSE
BEGIN
    PRINT 'Table users already exists. Skipping creation.';
END;
GO

-- 2. Personal Access Tokens Table (Sanctum)
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'personal_access_tokens' AND schema_id = SCHEMA_ID('dbo'))
BEGIN
    PRINT 'Creating table: personal_access_tokens...';
    CREATE TABLE dbo.personal_access_tokens (
        id BIGINT IDENTITY(1,1) NOT NULL,
        tokenable_type NVARCHAR(255) NOT NULL,
        tokenable_id BIGINT NOT NULL,
        name NVARCHAR(255) NOT NULL,
        token VARCHAR(64) NOT NULL,
        abilities NVARCHAR(MAX) NULL,
        last_used_at DATETIME2(0) NULL,
        expires_at DATETIME2(0) NULL,
        created_at DATETIME2(0) NULL DEFAULT SYSUTCDATETIME(),
        updated_at DATETIME2(0) NULL DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_personal_access_tokens PRIMARY KEY CLUSTERED (id),
        CONSTRAINT UQ_personal_access_tokens_token UNIQUE NONCLUSTERED (token)
    );
    
    CREATE NONCLUSTERED INDEX IX_pat_tokenable 
        ON dbo.personal_access_tokens (tokenable_type, tokenable_id);

    PRINT 'Table personal_access_tokens created successfully.';
END
ELSE
BEGIN
    PRINT 'Table personal_access_tokens already exists. Skipping creation.';
END;
GO
