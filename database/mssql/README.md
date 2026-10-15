# Microsoft SQL Server (MSSQL) Deployment Scripts

This directory contains standalone, production-ready T-SQL scripts for deploying the ISG Load Balancer database schema directly to **Microsoft SQL Server** (2016, 2017, 2019, 2022, or Azure SQL Database) without running Laravel's `php artisan migrate`.

---

## Files Included

| Script | Purpose |
| :--- | :--- |
| `01_create_tables.sql` | Creates `upstream_nodes`, `ip_rules`, and `request_metrics` tables with indexes, constraints, and defaults. Idempotent (`IF NOT EXISTS`). |
| `02_seed_initial_cluster.sql` | Seed script to register backend nodes (`app01`, `app02`, ..., `app0N`) using T-SQL `MERGE`. |
| `03_rollback_tables.sql` | Drops all three tables in correct reverse foreign-key order. |
| `04_create_sanctum_tables.sql` | Creates `users` and `personal_access_tokens` tables for Laravel Sanctum authentication. Idempotent. |

---

## Execution Guide

### Option A: SQL Server Management Studio (SSMS) or Azure Data Studio
1. Connect to your SQL Server instance.
2. Select your target application database (e.g., `USE [YourDatabaseName];`).
3. Open and execute `01_create_tables.sql`.
4. (Optional) Open `02_seed_initial_cluster.sql`, update the URLs/weights for your nodes, and execute.

### Option B: Command Line via `sqlcmd`
```bash
# 1. Run schema creation
sqlcmd -S <server_ip_or_name> -d <database_name> -U <username> -P <password> -i 01_create_tables.sql

# 2. (Optional) Seed initial cluster nodes
sqlcmd -S <server_ip_or_name> -d <database_name> -U <username> -P <password> -i 02_seed_initial_cluster.sql
```

---

## Laravel `.env` Configuration for MSSQL

Make sure `app03` connects to your SQL Server instance:

```env
# Database connection driver
DB_CONNECTION=sqlsrv
DB_HOST=10.0.0.50          # MSSQL Server IP or Hostname
DB_PORT=1433
DB_DATABASE=your_db_name
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password

# Or configure a dedicated connection in config/load-balancer.php:
# LOAD_BALANCER_DB_CONNECTION=sqlsrv
```

---

## Schema Reference

### 1. `upstream_nodes`
Stores the active cluster nodes that `app03` dispatches requests to:
- `id` (BIGINT, IDENTITY, PK)
- `name` (NVARCHAR(255)) - e.g., `'app01'`
- `url` (NVARCHAR(2048)) - e.g., `'http://10.0.0.11:8000'`
- `weight` (INT, DEFAULT 1) - Traffic weighting factor
- `is_active` (BIT, DEFAULT 1) - Active state (health check toggles this)
- `is_primary` (BIT, DEFAULT 0) - Primary flag for active-passive strategy
- `created_at`, `updated_at` (DATETIME2)

### 2. `ip_rules`
Binds specific client IPs directly to designated upstream nodes:
- `id` (BIGINT, IDENTITY, PK)
- `ip_address` (NVARCHAR(45), UNIQUE) - Client IPv4 or IPv6
- `upstream_node_id` (BIGINT, FK -> `upstream_nodes.id` ON DELETE CASCADE)
- `created_at`, `updated_at` (DATETIME2)

### 3. `request_metrics`
Logs request latencies and response statuses:
- `id` (BIGINT, IDENTITY, PK)
- `incoming_ip` (NVARCHAR(45))
- `upstream_node_id` (BIGINT, NULLABLE, FK -> `upstream_nodes.id` ON DELETE SET NULL)
- `method` (NVARCHAR(10)) - e.g., `'GET'`, `'POST'`
- `uri` (NVARCHAR(2048))
- `status_code` (INT)
- `response_time_ms` (INT)
- `created_at` (DATETIME2, DEFAULT `SYSUTCDATETIME()`)
