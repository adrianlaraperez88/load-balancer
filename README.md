# Enterprise Load Balancer & Gateway (Laravel 12 / 13 & PHP 8.3)

A turnkey, high-resilience standalone Load Balancer and Reverse Proxy application built on **Laravel 12/13 and PHP 8.3**. Specifically architected for high-throughput enterprise deployments featuring **1 Front Gateway Server (`app03`) + $N$ Backend Processing Servers**, supporting both **Windows Server + IIS 10** and **Docker / Linux Containers** with zero-trust security and manual MSSQL database provisioning.

---

## Table of Contents

- [Key Highlights](#key-highlights)
- [Architecture: 1 Front Gateway (app03) + N Backends](#architecture-1-front-gateway-app03--n-backends)
- [Installation](#installation)
- [Deployment Option 1: Windows Server + IIS](#deployment-option-1-windows-server--iis)
  - [1. Web Server & FastCGI Configuration](#1-web-server--fastcgi-configuration)
  - [2. Passing Authorization Headers (Bearer Tokens)](#2-passing-authorization-headers-bearer-tokens)
  - [3. Windows NTFS & Path Traversal Protections](#3-windows-ntfs--path-traversal-protections)
  - [4. MSSQL Database Setup (No Migration Privileges)](#4-mssql-database-setup-no-migration-privileges)
- [Deployment Option 2: Docker & Linux Containers](#deployment-option-2-docker--linux-containers)
  - [1. Production Docker Architecture](#1-production-docker-architecture)
  - [2. Quick Start with Docker Compose (Production)](#2-quick-start-with-docker-compose-production)
  - [3. Local Multi-Node Test Cluster](#3-local-multi-node-test-cluster-docker-composetest-clusteryml)
  - [4. High-Concurrency Scale Benchmark (1 Gateway + 5 Backends)](#4-high-concurrency-scale-benchmark-1-gateway--5-backends)
  - [5. Speed & Latency Optimizations](#5-speed--latency-optimizations)
- [Authentication & Secure Communications (Sanctum)](#authentication--secure-communications-sanctum)
- [Interactive Swagger UI & OpenAPI Specification](#interactive-swagger-ui--openapi-specification)
- [Visual Cluster Web Admin Control Center](#visual-cluster-web-admin-control-center)
- [Ready-to-Run cURL Commands](#ready-to-run-curl-commands)
- [Security & Zero-Trust Hardening](#security--zero-trust-hardening)
  - [1. HMAC-SHA256 Request Signing & Nonce Deep-Dive](#1-hmac-sha256-request-signing--nonce-deep-dive)
  - [2. RFC-Compliant Header Sanitization & Multi-Cookie Preservation](#2-rfc-compliant-header-sanitization--multi-cookie-preservation)
  - [3. Sliding-Window Circuit Breaker & Fail-Open Protection](#3-sliding-window-circuit-breaker--fail-open-protection)
  - [4. Request Body Size Limits & Memory-Efficient Streaming](#4-request-body-size-limits--memory-efficient-streaming)
  - [5. Authenticated Observability & Metrics](#5-authenticated-observability--metrics)
- [Artisan CLI Operations](#artisan-cli-operations)
  - [Node Management (`loadbalancer:node`)](#node-management-loadbalancernode)
  - [Concurrent Health Checks (`loadbalancer:health-check`)](#concurrent-health-checks-loadbalancerhealth-check)
  - [Telemetry Retention (`loadbalancer:prune-metrics`)](#telemetry-retention-loadbalancerprune-metrics)
- [Balancing Strategies & Sticky Sessions](#balancing-strategies--sticky-sessions)
  - [Supported Strategies](#supported-strategies)
  - [Rendezvous (HRW) Consistent Hashing](#rendezvous-hrw-consistent-hashing)
  - [Power-of-Two-Choices (P2C) Adaptive Latency](#power-of-two-choices-p2c-adaptive-latency)
  - [Custom Strategy Implementation](#custom-strategy-implementation)
- [Configuration Reference (`.env`)](#configuration-reference-env)
- [Testing & Quality Assurance](#testing--quality-assurance)

---

## Key Highlights

- **Pure PHP Reverse Proxy**: Pipes payloads, HTTP methods, headers, and responses without requiring IIS ARR (Application Request Routing) or custom Nginx C modules.
- **Dual Deployment Ready**: Optimized for **Windows Server 2016/2019/2022 + IIS 10** as well as **Docker / Kubernetes / Linux**.
- **DBA-Friendly MSSQL Support**: Includes self-contained, idempotent T-SQL deployment and rollback scripts for environments where developers cannot run `php artisan migrate`.
- **Zero-Trust Security**: HMAC-SHA256 request signing ensures backends cannot be bypassed directly; Windows NTFS-specific traversal protections block alternate data streams and 8.3 short-name exploits.
- **Fail-Open Circuit Breakers**: Windowed error rates prevent transient blips from knocking out your cluster, while guaranteed minimums prevent thundering herd failure cascades.
- **Extreme Scale Analytics**: Asynchronous metric logging via `dispatchAfterResponse()` with probabilistic sampling handles sustained bursts of traffic.

---

## Architecture: 1 Front Gateway (app03) + N Backends

In this topology, public clients and browser single-page applications (SPAs) **only communicate with a single ingress server (`app03`)**. The $N$ backend processing nodes (`app01`, `app02`, ..., `appN`) reside in private subnets, execute compute workloads, and stream responses back through `app03` to the client.

```
                       ┌────────────────────────────────────────────────────────┐
                       │                   UI / Browser / Client                │
                       └───────────────────────────┬────────────────────────────┘
                                                   │ (All requests hit app03 only)
                                                   ▼
┌────────────────────────────────────────────────────────────────────────────────────────────────────────┐
│  FRONT GATEWAY SERVER (app03)                                                                         │
│                                                                                                        │
│  - Public Ingress (e.g., https://app03.yourdomain.com)                                                │
│  - Runs on Windows IIS 10 or Docker Nginx/PHP-FPM                                                     │
│  - Mode: Proxy (`LOAD_BALANCER_MODE=proxy`)                                                           │
│  - Dynamic Session / Token Forwarding (`Authorization: Bearer` passed intact)                          │
│  - HMAC Request Signing (`X-LoadBalancer-Signature`, `X-LoadBalancer-Timestamp`)                      │
│  - Dispatches via Strategy: Round Robin, Weighted, Adaptive Latency (P2C), or Rendezvous Sticky       │
└───────────────┬──────────────────────────┬──────────────────────────┬──────────────────────────────────┘
                │                          │                          │
        (Private VPC/LAN)          (Private VPC/LAN)          (Private VPC/LAN)
                │                          │                          │
                ▼                          ▼                          ▼
      ┌──────────────────┐       ┌──────────────────┐       ┌──────────────────┐
      │  Backend app01   │       │  Backend app02   │       │  Backend app0N   │
      │  (10.0.0.11:8000)│       │  (10.0.0.12:8000)│       │  (10.0.0.1N:8000)│
      │  IIS / Docker    │       │  IIS / Docker    │       │  IIS / Docker    │
      │  Compute/Process │       │  Compute/Process │       │  Compute/Process │
      └──────────────────┘       └──────────────────┘       └──────────────────┘
```

---

## Installation

Require the package via Composer:

```bash
composer require adrianlaraperez88/load-balancer
```

### Turnkey 1-Command Setup Wizard

Run the interactive installation command to set up configuration, HMAC security secrets, database migrations, initial nodes, and admin tokens in under 10 seconds:

```bash
php artisan loadbalancer:install
```

Or for automated headless CI/CD deployments:

```bash
php artisan loadbalancer:install --no-interaction --mode=proxy --strategy=round_robin
```

This single command automatically:
1. Publishes `config/load-balancer.php`.
2. Generates a cryptographically strong 64-character HMAC secret key and appends `LOAD_BALANCER_HMAC_SECRET` into `.env`.
3. Runs database migrations for `upstream_nodes`, `ip_rules`, and `request_metrics`.
4. Prompts to register your initial upstream backend nodes.
5. Issues a dedicated admin Sanctum token for immediate API & Web GUI administration.

### Gateway Reverse Proxy Route Macro

Register the reverse proxy route macro on your ingress gateway server (`routes/web.php` or `routes/api.php`):

```php
use Illuminate\Support\Facades\Route;

// Captures all incoming web/API paths and transparently proxies to backend nodes:
Route::loadBalancer('{any?}');
```

> **Note**: The `Route::loadBalancer()` macro automatically strips stateful session, cookie encryption, and CSRF middleware to avoid intercepting or corrupting backend authentication headers.

### Turnkey Backend Protection (1-Line Middleware)

On your backend processing servers (`app01`, `app02`, ..., `appN`), protect incoming routes so they **only accept requests originating from the gateway** using the pre-registered `'gateway.verify'` middleware alias:

```php
use Illuminate\Support\Facades\Route;

// Backend servers reject direct requests (401 Unauthorized) and only accept HMAC-signed traffic from app03:
Route::middleware('gateway.verify')->group(function () {
    Route::apiResource('/orders', OrderController::class);
    Route::get('/users/me', [UserController::class, 'me']);
});
```

---

## Deployment Option 1: Windows Server + IIS

This is the recommended configuration when your organization hosts on Windows Server (2016 / 2019 / 2022) with Internet Information Services (IIS 10) and SQL Server.

### 1. Web Server & FastCGI Configuration

A battle-tested IIS `web.config` is provided in [`config/iis/web.config`](file:///f:/ai-projects/load-balancer/config/iis/web.config). Copy it directly to the root of your Laravel `public/` directory:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
    <system.webServer>
        <defaultDocument>
            <files>
                <clear />
                <add value="index.php" />
            </files>
        </defaultDocument>
        <rewrite>
            <rules>
                <!-- Forward Authorization Header to PHP FastCGI -->
                <rule name="Pass Authorization Header" stopProcessing="false">
                    <match url=".*" />
                    <conditions>
                        <add input="{HTTP_AUTHORIZATION}" pattern="^Bearer (.+)$" />
                    </conditions>
                    <serverVariables>
                        <set name="HTTP_AUTHORIZATION" value="{C:0}" />
                    </serverVariables>
                </rule>
                <!-- Standard Laravel Front Controller Rewrite -->
                <rule name="Laravel Front Controller" stopProcessing="true">
                    <match url="^" ignoreCase="false" />
                    <conditions logicalGrouping="MatchAll">
                        <add input="{REQUEST_FILENAME}" matchType="IsDirectory" negate="true" />
                        <add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
                    </conditions>
                    <action type="Rewrite" url="index.php" />
                </rule>
            </rules>
        </rewrite>
        <!-- Security: Hide sensitive directories and files -->
        <security>
            <requestFiltering>
                <hiddenSegments>
                    <add segment=".env" />
                    <add segment="storage" />
                    <add segment="resources" />
                    <add segment="composer.json" />
                    <add segment="composer.lock" />
                </hiddenSegments>
            </requestFiltering>
        </security>
    </system.webServer>
</configuration>
```

### 2. Passing Authorization Headers (Bearer Tokens)

IIS FastCGI strips the `Authorization` header by default. The rewrite rule above captures it and sets the `HTTP_AUTHORIZATION` server variable.

If you encounter **HTTP Error 500.50 (URL Rewrite Module Error)**:
1. Open **IIS Manager**.
2. Select your site, double-click **URL Rewrite**.
3. In the right Actions pane, click **View Server Variables...**
4. Click **Add...** and type `HTTP_AUTHORIZATION`.

### 3. Windows NTFS & Path Traversal Protections

Windows IIS uses NTFS, which includes special namespace characteristics that malicious clients can attempt to exploit. The built-in proxy engine automatically rejects attacks before upstream dispatch:
- **Alternate Data Streams (`::$DATA`)**: Blocked (`HTTP 400 Bad Request`).
- **8.3 Short Names (`DIR~1`)**: Blocked to prevent bypassing hidden segment rules (`HTTP 400 Bad Request`).
- **Trailing Dots and Spaces (`path./`)**: Blocked to prevent NTFS path truncation bypasses (`HTTP 400 Bad Request`).
- **Path Traversal (`..`, `%2e%2e`, `\`)**: Sanitized and blocked (`HTTP 400 Bad Request`).

### 4. MSSQL Database Setup (No Migration Privileges)

When corporate database policies prevent developers from executing `php artisan migrate`, production-grade idempotent T-SQL scripts are available in [`database/mssql/`](file:///f:/ai-projects/load-balancer/database/mssql/):

| Script | Purpose | Execution |
| :--- | :--- | :--- |
| [`01_create_tables.sql`](file:///f:/ai-projects/load-balancer/database/mssql/01_create_tables.sql) | Creates `upstream_nodes`, `load_balancer_metrics`, `ip_rules` with primary keys, indexes, and constraints | Run by DBA via SSMS or `sqlcmd` |
| [`02_seed_initial_cluster.sql`](file:///f:/ai-projects/load-balancer/database/mssql/02_seed_initial_cluster.sql) | Idempotently seeds initial cluster (`app01`, `app02`) and default routing rules | Run after table creation |
| [`03_rollback_tables.sql`](file:///f:/ai-projects/load-balancer/database/mssql/03_rollback_tables.sql) | Safely drops all tables and constraints if rollback is required | Emergency teardown |
| [`04_create_sanctum_tables.sql`](file:///f:/ai-projects/load-balancer/database/mssql/04_create_sanctum_tables.sql) | Creates `users` and `personal_access_tokens` tables for Laravel Sanctum authentication | Run by DBA for API auth |

**Executing via SQLCMD**:
```cmd
sqlcmd -S tcp:db-server.internal,1433 -d ProductionDB -U sa -P "YourStrongPassword!" -i database/mssql/01_create_tables.sql
sqlcmd -S tcp:db-server.internal,1433 -d ProductionDB -U sa -P "YourStrongPassword!" -i database/mssql/02_seed_initial_cluster.sql
sqlcmd -S tcp:db-server.internal,1433 -d ProductionDB -U sa -P "YourStrongPassword!" -i database/mssql/04_create_sanctum_tables.sql
```

---

## Deployment Option 2: Docker & Linux Containers

For cloud deployments (AWS ECS, GCP Cloud Run, Azure Kubernetes Service, or Linux VMs), an enterprise container stack is provided.

### 1. Production Docker Architecture

- **`Dockerfile` ([`docker/Dockerfile`](file:///f:/ai-projects/load-balancer/docker/Dockerfile))**: Multi-stage build based on PHP 8.3 CLI/FPM with Microsoft ODBC 18 drivers for SQL Server, `pdo_sqlsrv`, `redis`, `opcache`, and Nginx.
- **`supervisord` ([`docker/supervisord.conf`](file:///f:/ai-projects/load-balancer/docker/supervisord.conf))**: Manages PHP-FPM, Nginx, and the background health check daemon concurrently inside the container.
- **`nginx.conf` ([`docker/nginx.conf`](file:///f:/ai-projects/load-balancer/docker/nginx.conf))**: Configured for high-throughput proxying with 16KB header buffers, 10MB client body limit, gzip compression, and security headers.

### 2. Quick Start with Docker Compose (Production)

A complete production stack with the load balancer gateway, Valkey in-memory cache, and backend connections is defined in [`docker-compose.prod.yml`](file:///f:/ai-projects/load-balancer/docker-compose.prod.yml):

```bash
# Build and launch the containerized load balancer stack:
docker compose -f docker-compose.prod.yml up -d --build

# View gateway logs:
docker compose -f docker-compose.prod.yml logs -f gateway
```

### 3. Local Multi-Node Test Cluster (`docker-compose.test-cluster.yml`)

We provide a complete simulated environment featuring **1 Front Gateway (`app03_gateway` on port 8080) + 3 Backend Processing Nodes (`backend_app01`, `backend_app02`, `backend_app04`) + Valkey Cache (`valkey/valkey:8-alpine`)**:

```bash
# Launch the 4-server cluster:
docker compose -f docker-compose.test-cluster.yml up -d --build

# Test reverse proxy round-robin through app03:
curl http://localhost:8080/
curl http://localhost:8080/

# Test data writing and reading through the proxy:
curl -X POST http://localhost:8080/api/items \
     -H "Content-Type: application/json" \
     -H "Authorization: Bearer test-user-token" \
     -d '{"message": "Testing DB write through app03"}'

# Read back stored data:
curl http://localhost:8080/api/items
```

### 4. High-Concurrency Scale Benchmark (1 Gateway + 10 Backends)

A multi-node scale testing environment is configured in [`docker-compose.scale-test.yml`](file:///f:/ai-projects/load-balancer/docker-compose.scale-test.yml) and automated via [`docker/run_scale_benchmark.py`](file:///f:/ai-projects/load-balancer/docker/run_scale_benchmark.py):

- **1 Gateway (`app03_gateway`)**: Public ingress on port `8080`, running PHP-FPM 8.3 (tuned to 64 dynamic worker pools) and Nginx.
- **10 Backend Processing Nodes (`backend_app01` to `backend_app10`)**: Running on private Docker subnet with ports isolated.
- **1 Valkey Cache (`cluster_valkey`)**: Running `valkey/valkey:8-alpine` for high-throughput node registry and atomic metrics caching.

#### How to Run the 10-Backend Benchmark & Graphic Generator:
```bash
# 1. Start the 1 Gateway + 10 Backend cluster
docker compose -f docker-compose.scale-test.yml up -d

# 2. Run the timeout validation suite and 10,000-request scale benchmark
python docker/run_scale_benchmark.py
```

#### Performance Graphic Dashboard:
The test outputs a high-resolution 4-panel visual graphic: [`docker/performance_report_10nodes.png`](file:///f:/ai-projects/load-balancer/docker/performance_report_10nodes.png)

#### Multi-Tier Benchmark Results (10,000 Requests):

| Concurrency Tier | Total Requests | Elapsed Time | Throughput (RPS) | Median Latency (p50) | 95th Percentile (p95) | Success Rate | Failed |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **20 Workers** | 500 req | 6.88 s | **72.7 RPS** | 259.9 ms | 335.8 ms | **100.0%** | 0 |
| **50 Workers** | 1,500 req | 16.74 s | **89.6 RPS** | 536.3 ms | 697.4 ms | **100.0%** | 0 |
| **100 Workers** | 3,000 req | 30.53 s | **98.3 RPS** | 1,013.1 ms | 1,101.5 ms | **100.0%** | 0 |
| **200 Workers** | 5,000 req | 51.85 s | **96.4 RPS** | 2,042.4 ms | 2,350.3 ms | **100.0%** | 0 |

**10,000 total requests processed across 10 backends with 0 dropped packets or errors (100.0% success rate).**

#### Traffic Distribution Uniformity Across all 10 Backend Nodes:

```text
  app01           :  480 req ( 9.6%) #########
  app02           :  509 req (10.2%) ##########
  app03_compute   :  461 req ( 9.2%) #########
  app04           :  487 req ( 9.7%) #########
  app05           :  603 req (12.1%) ############
  app06           :  522 req (10.4%) ##########
  app07           :  489 req ( 9.8%) #########
  app08           :  477 req ( 9.5%) #########
  app09           :  474 req ( 9.5%) #########
  app10           :  498 req (10.0%) ##########
```
*Round-robin distribution achieves an optimal ~10% balance per server across all 10 nodes under heavy concurrent load.*

#### Timeout Validation Results (1ms to 12s):

| Injected Delay | Elapsed Time | Returned Status | Expected | Match | Behavior |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **1 ms** | 0.41 s | `200 OK` | `200` | **PASS** | Instant response |
| **50 ms** | 0.30 s | `200 OK` | `200` | **PASS** | Instant response |
| **250 ms** | 0.46 s | `200 OK` | `200` | **PASS** | Normal response |
| **1 sec** | 1.23 s | `200 OK` | `200` | **PASS** | Normal response |
| **3 sec** | 3.25 s | `200 OK` | `200` | **PASS** | Normal response |
| **6 sec** | 6.25 s | `200 OK` | `200` | **PASS** | Normal response |
| **8 sec** | 8.27 s | `200 OK` | `200` | **PASS** | Within 10s gateway timeout |
| **12 sec** | 30.32 s | `502 / 504` | `[502, 504]` | **PASS** | **Timeout triggered! Gateway retried 3 healthy nodes, then safely aborted to protect client.** |

#### 100k Throughput & 10-Minute Projection:
- At **98.3 sustained RPS**, a single container gateway processes **58,980 requests in 10 minutes (600s)**.
- Reaching **100k requests in 10 minutes** requires 167 RPS. Deploying **2 Gateway containers** behind an ALB/DNS will comfortably deliver **~196 RPS (100k in 8.5 minutes)** across the 10 backends.

---

### 5. Speed & Latency Optimizations

1. **cURL Socket Keep-Alive Connection Reuse**:
   - `CURLOPT_TCP_KEEPALIVE => 1`, `CURLOPT_TCP_KEEPIDLE => 120`, `CURLOPT_TCP_KEEPINTVL => 60`.
   - Eliminates TCP three-way handshake (`SYN` / `SYN-ACK` / `ACK`) latency on every proxy forward.
2. **PHP-FPM Dynamic High-Concurrency Pool**:
   - Default PHP-FPM settings throttle at 5 workers (`pm.max_children = 5`), leading to queue starvation.
   - Tuned in Dockerfile & FPM configs:
     ```ini
     pm = dynamic
     pm.max_children = 64
     pm.start_servers = 16
     pm.min_spare_servers = 8
     pm.max_spare_servers = 32
     pm.max_requests = 2000
     ```
3. **Nginx FastCGI Buffer Tuning**:
   - `fastcgi_buffer_size 32k;` and `fastcgi_buffers 16 16k;` prevent disk-buffering bottlenecks during large response transfers.
   - `keepalive_requests 10000;` and `keepalive_timeout 65;` optimize persistent client connections.
4. **Sub-Millisecond Node Registry Caching**:
   - Upstream node list is cached in Redis / APCu for 60 seconds.
   - Atomic cache clearing occurs instantly on any REST or Artisan node modification (`NodeManagementService::clearNodeCache()`).
5. **Gateway Response Microcaching (`X-Cache: HIT / MISS`)**:
   - For read-heavy endpoints (`GET`, `HEAD`), the ingress gateway can microcache upstream responses in Redis or local memory for 1 to 60 seconds.
   - Sub-millisecond latency ($< 1\text{ ms}$) without hitting backend compute or network sockets.
   - Safe by default: Requests with `Authorization` headers, user session cookies, or mutating verbs (`POST`, `PUT`, `DELETE`, `PATCH`) automatically bypass the cache and pass through directly to backends.
   - Enabled via `.env`:
     ```env
     LOAD_BALANCER_CACHE_ENABLED=true
     LOAD_BALANCER_CACHE_TTL=5
     LOAD_BALANCER_CACHE_STORE=redis
     ```
   - Inspection: Responses return `X-Cache: HIT` or `X-Cache: MISS` headers.

---

## Authentication & Secure Communications (Sanctum)

To protect communications across **1 Front Gateway (`app03`) + $N$ Backend Processing Servers**, the application implements **Laravel Sanctum** token-based authentication paired with **HMAC-SHA256 request signing**:

1. **Client Authenticates**: Client sends credentials to `POST /api/auth/login` and receives a Sanctum `access_token` (`plainTextToken`).
2. **Stateless Gateway Ingress (`app03`)**: For subsequent API calls, the client sends `Authorization: Bearer <token>`. The `app03` gateway:
   - Forwards the `Authorization` header intact.
   - Signs the request with an HMAC-SHA256 hash (`X-LoadBalancer-Signature`) and timestamp (`X-LoadBalancer-Timestamp`).
3. **Backend Node Verification**:
   - [`VerifyLoadBalancerSignature`](file:///f:/ai-projects/load-balancer/src/Http/Middleware/VerifyLoadBalancerSignature.php) verifies the HMAC signature, rejecting any requests that bypass `app03`.
   - `auth:sanctum` validates the Bearer token against the central database (`personal_access_tokens` table in SQL Server / SQLite / MySQL).

### Authentication Endpoints

| Method | Endpoint | Protection | Description |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/auth/login` | Public | Authenticates credentials and returns a Sanctum `access_token` |
| `GET` | `/api/auth/me` | `auth:sanctum` | Returns the authenticated user's profile |
| `POST` | `/api/auth/workload` | `auth:sanctum` | Executes a secure business workload requiring authentication |
| `POST` | `/api/auth/logout` | `auth:sanctum` | Revokes the current `personal_access_token` |

#### Testing Sanctum Authentication:
```bash
# 1. Login to obtain a Bearer token
curl -X POST http://localhost:8080/api/auth/login \
     -H "Content-Type: application/json" \
     -d '{"email": "alice@enterprise.com", "password": "SecretPass123!"}'

# 2. Call protected workload endpoint via gateway
curl -X POST http://localhost:8080/api/auth/workload \
     -H "Content-Type: application/json" \
     -H "Authorization: Bearer <token_here>" \
     -d '{"task": "process_batch", "batch_id": 1001}'

# 3. Logout (revokes token in DB)
curl -X POST http://localhost:8080/api/auth/logout \
     -H "Authorization: Bearer <token_here>"
```

---

## Interactive Swagger UI & OpenAPI Specification

The gateway includes an interactive, browser-based **Swagger UI (OpenAPI 3.0.3)** document explorer for visualizing and testing all endpoints:

- **Swagger UI URL**: [http://localhost:8080/api/docs](http://localhost:8080/api/docs)
- **Raw OpenAPI JSON Spec**: [http://localhost:8080/api/docs/openapi.json](http://localhost:8080/api/docs/openapi.json)

```bash
# View Swagger UI from CLI
curl -i http://localhost:8080/api/docs

# Download the complete OpenAPI 3.0 specification
curl -s http://localhost:8080/api/docs/openapi.json | jq .
```

---

## Visual Cluster Web Admin Control Center

A modern, responsive, dark-mode single-page administrative dashboard is built directly into the gateway. It allows DevOps and engineering leads to monitor and control the cluster in real time without touching the command line or database:

- **Web Admin Dashboard URL**: [http://localhost:8080/admin/cluster](http://localhost:8080/admin/cluster)
- **Zero-Dependency Architecture**: Built using pure vanilla modern HTML5, CSS3 glassmorphism, and asynchronous vanilla JavaScript.

### Key Capabilities:
1. **Live Cluster KPI Metrics**: Live counters for Total Registered Nodes, Active Healthy Nodes, Drained / Maintenance Nodes, and Gateway Status.
2. **Instant Traffic Draining**: 1-click **Drain / Maintain** toggle removes a backend node from the proxy rotation instantly (atomic cache invalidation). Click again to return to **Active** status.
3. **Dynamic Node Provisioning**: Click **"+ Add Upstream Node"** to register a new backend container/server (`name`, `URL`, `weight`) with real-time modal validation.
4. **Permanent Node Deletion**: Remove decommissioned servers from the cluster database with confirmation safeguards.
5. **Zero-Trust Administrative Protection**: Locked down by default with an "Authentication Required" lock screen. Requires pasting your configured `LOAD_BALANCER_METRICS_TOKEN` (Bearer token) and clicking **Unlock** to view KPIs, list nodes, or execute administrative actions. Unauthorized requests are strictly rejected with HTTP 401. Active tokens are securely persisted in browser `sessionStorage` for convenience.

---

## Ready-to-Run cURL Commands

### 1. Health & Status Probe
```bash
curl -i http://localhost:8080/up
```

### 2. Laravel Sanctum Authentication Flow

```bash
# A. Authenticate and receive a Bearer token
curl -s -X POST http://localhost:8080/api/auth/login \
     -H "Content-Type: application/json" \
     -d '{"email": "alice@enterprise.com", "password": "SecretPass123!"}' | jq .

# Save token into environment variable:
TOKEN="<paste_access_token_here>"

# B. Call authenticated user profile
curl -s -X GET http://localhost:8080/api/auth/me \
     -H "Authorization: Bearer $TOKEN" | jq .

# C. Execute secure workload through the gateway
curl -s -X POST http://localhost:8080/api/auth/workload \
     -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"task": "process_batch", "batch_id": 1054}' | jq .

# D. Revoke token on logout
curl -s -X POST http://localhost:8080/api/auth/logout \
     -H "Authorization: Bearer $TOKEN" | jq .
```

### 3. Cluster Node Management API

```bash
# A. List all active upstream nodes in the cluster
curl -s -X GET http://localhost:8080/api/load-balancer/nodes \
     -H "X-Metrics-Secret: admin-cluster-secret-token" | jq .

# B. Register a new backend processing node
curl -s -X POST http://localhost:8080/api/load-balancer/nodes \
     -H "X-Metrics-Secret: admin-cluster-secret-token" \
     -H "Content-Type: application/json" \
     -d '{"name": "backend_app06", "url": "http://10.0.0.16:8000", "weight": 5, "is_active": true}' | jq .

# C. Inspect node details by ID
curl -s -X GET http://localhost:8080/api/load-balancer/nodes/1 \
     -H "X-Metrics-Secret: admin-cluster-secret-token" | jq .

# D. Toggle node maintenance mode (drain/reactivate traffic instantly)
curl -s -X PATCH http://localhost:8080/api/load-balancer/nodes/1/toggle \
     -H "X-Metrics-Secret: admin-cluster-secret-token" | jq .

# E. Remove node from the database
curl -s -X DELETE http://localhost:8080/api/load-balancer/nodes/1 \
     -H "X-Metrics-Secret: admin-cluster-secret-token" | jq .
```

### 4. IP Affinity & Firewall Rules API

```bash
# A. List all active IP rules
curl -s -X GET http://localhost:8080/api/load-balancer/ip-rules \
     -H "X-Metrics-Secret: admin-cluster-secret-token" | jq .

# B. Bind an IP to a designated target node (Sticky Routing)
curl -s -X POST http://localhost:8080/api/load-balancer/ip-rules \
     -H "X-Metrics-Secret: admin-cluster-secret-token" \
     -H "Content-Type: application/json" \
     -d '{"ip_address": "192.168.1.100", "action": "route", "target_node_id": 2}' | jq .

# C. Delete an IP rule
curl -s -X DELETE http://localhost:8080/api/load-balancer/ip-rules/1 \
     -H "X-Metrics-Secret: admin-cluster-secret-token" | jq .
```

### 5. Cluster Observability & Latency Metrics

```bash
# Retrieve real-time request counts, status codes, and latency percentiles
curl -s -X GET http://localhost:8080/api/load-balancer/metrics \
     -H "X-Metrics-Secret: admin-cluster-secret-token" | jq .
```

---

## Security & Zero-Trust Hardening

### 1. HMAC-SHA256 Request Signing & Nonce Deep-Dive

To ensure clients cannot bypass the `app03` gateway and send requests directly to backend nodes, all proxied requests are cryptographically signed with HMAC-SHA256.

```text
Payload Canonical String:
"{METHOD}|{PATH}|{TIMESTAMP}|{REQUEST_ID}"
```

- **`X-Request-Id` (Cryptographic Nonce & Tracing ID)**: A unique UUIDv4 generated for every request. Ensures two identical requests arriving in the same second never share the same hash, eliminating collision and rainbow table vulnerabilities.
- **`X-LB-Timestamp` (Replay Window)**: Unix timestamp validated against a 300-second expiration tolerance.
- **`X-LB-Signature`**: `hash_hmac('sha256', payload, secret)`.

#### Configuration:
In your `.env` (on both Gateway and Backends):
```env
LOAD_BALANCER_SIGNATURE_ENABLED=true
LOAD_BALANCER_SIGNATURE_SECRET="your-ultra-secure-shared-secret-key-64-bytes"
LOAD_BALANCER_SIGNATURE_TOLERANCE=300
```

#### Verification Middleware on Backend Nodes (`app01` to `appN`):
In `bootstrap/app.php`:
```php
use Isg\LoadBalancer\Http\Middleware\VerifyLoadBalancerSignature;

$middleware->api(append: [
    VerifyLoadBalancerSignature::class,
]);
```

Or simply use the route alias: `Route::middleware('gateway.verify')`.

#### Zero-Downtime HMAC Key Rotation

In enterprise environments, security policies require periodic secret rotation. To rotate keys without dropping inflight requests or requiring simultaneous downtime across all servers:

1. Configure `active_key_id`, key mappings, and `grace_secrets` in `config/load-balancer.php`:
   ```php
   'signature' => [
       'enabled' => env('LOAD_BALANCER_SIGNATURE_ENABLED', true),
       'active_key_id' => env('LOAD_BALANCER_ACTIVE_KEY_ID', 'v2'),
       'keys' => [
           'v1' => env('LOAD_BALANCER_KEY_V1'),
           'v2' => env('LOAD_BALANCER_KEY_V2'),
       ],
       'grace_secrets' => array_filter(explode(',', (string) env('LOAD_BALANCER_GRACE_SECRETS', ''))),
   ],
   ```
2. The gateway automatically tags requests with header `X-LB-Key-Id: v2`.
3. Backend servers verify against the active key ID (`v2`), falling back to any configured grace keys (`LOAD_BALANCER_GRACE_SECRETS`), providing seamless, zero-downtime rotation.

#### Hands-On cURL Demonstration:

##### A. Bypassing the Gateway (Rejected):
```bash
curl -i http://backend_app01:8000/api/workload
# Output: HTTP/1.1 401 Unauthorized
# {"error":"Unauthorized","message":"Missing required load balancer signature headers."}
```

##### B. Manually Calculating & Sending Valid Signature:
```bash
# In Bash:
SECRET="your-ultra-secure-shared-secret-key-64-bytes"
TIMESTAMP=$(date +%s)
REQ_ID="req-$(cat /proc/sys/kernel/random/uuid)"
PAYLOAD="GET|api/workload|${TIMESTAMP}|${REQ_ID}"
SIGNATURE=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')

# Send with signature:
curl -i http://backend_app01:8000/api/workload \
     -H "X-Request-Id: $REQ_ID" \
     -H "X-LB-Timestamp: $TIMESTAMP" \
     -H "X-LB-Signature: $SIGNATURE"
# Output: HTTP/1.1 200 OK
```

##### C. Path Tampering (Detected & Rejected):
```bash
# Attacker alters the path to /api/admin without knowing the secret
curl -i http://backend_app01:8000/api/admin \
     -H "X-Request-Id: $REQ_ID" \
     -H "X-LB-Timestamp: $TIMESTAMP" \
     -H "X-LB-Signature: $SIGNATURE"
# Output: HTTP/1.1 403 Forbidden
# {"error":"Forbidden","message":"Invalid load balancer signature."}
```

### 2. RFC-Compliant Header Sanitization & Multi-Cookie Preservation

The reverse proxy cleanses all headers before forwarding upstream and before responding to the client:
- **Stripped Hop-by-Hop Headers**: `Connection`, `Keep-Alive`, `Proxy-Authenticate`, `Proxy-Authorization`, `TE`, `Trailers`, `Transfer-Encoding`, `Upgrade`.
- **Client IP Forgery Prevention**: Strips client-supplied `X-Forwarded-Host`, `X-Forwarded-Proto`, and `X-Forwarded-For`, replacing them with verified client IP and gateway metadata.
- **Multiple `Set-Cookie` Support**: Preserves all separate `Set-Cookie` response headers returned by backend nodes (e.g., session cookies + remember-me cookies + tracking cookies) without overwriting or collapsing them.
- **Bearer Tokens**: The `Authorization` header is securely forwarded untouched.

### 3. Sliding-Window Circuit Breaker & Fail-Open Protection

Traditional circuit breakers that trip on a single error cause cascading outages. This package implements a sliding-window failure rate breaker:
- Tracks total requests and failures over a 60-second window.
- Trips to `OPEN` status only when the error rate exceeds the threshold (default: 50% failures across a minimum of 5 requests).
- **Fail-Open Protection**: If *all* backend nodes trip simultaneously, the breaker fails open rather than serving 500 errors to 100% of your users.
- **Half-Open Probing**: Periodically lets a canary probe through after the cool-off period (default: 30 seconds) to detect upstream recovery automatically.

### 4. Request Body Size Limits & Memory-Efficient Streaming

- **Configurable Body Limit**: Rejects oversized payloads immediately with `HTTP 413 Content Too Large` (default: 10MB via `LOAD_BALANCER_MAX_BODY_SIZE=10485760`).
- **Response Streaming**: Responses are streamed directly via `response()->stream()` using low memory buffers, preventing memory exhaustion on large downloads or long-polling responses.

### 5. Authenticated Observability & Metrics

The `/api/load-balancer/metrics` endpoint provides JSON telemetry (cluster status, total requests, average latency).

**By default, this endpoint returns `404 Not Found`** to prevent information disclosure. When enabled, it is protected by authentication and IP allowlisting:

```env
LOAD_BALANCER_METRICS_ENABLED=true
LOAD_BALANCER_METRICS_TOKEN="your-metrics-bearer-token"
LOAD_BALANCER_METRICS_IP_ALLOWLIST="10.0.0.5,192.168.1.50"
```

---

## Artisan CLI Operations

### Node Management (`loadbalancer:node`)

Manage cluster nodes dynamically without database access:

```bash
# Add a new backend node (validates URL scheme and syntax):
php artisan loadbalancer:node add --name=app01 --url=http://10.0.0.11:8000 --weight=1

# Add a primary node in an active-passive setup:
php artisan loadbalancer:node add --name=app02 --url=http://10.0.0.12:8000 --weight=2 --primary

# List all nodes with current health and weights:
php artisan loadbalancer:node list

# Toggle a node active/inactive for scheduled maintenance:
php artisan loadbalancer:node toggle 1

# Remove a node from the cluster:
php artisan loadbalancer:node remove 1
```

### Concurrent Health Checks (`loadbalancer:health-check`)

Executes non-blocking, parallel health checks across all cluster nodes using Guzzle HTTP pools:

```bash
# Run manual health check:
php artisan loadbalancer:health-check --timeout=5 --consecutive=3
```

Schedule it in your `routes/console.php` (Laravel 11/12) or `app/Console/Kernel.php` (Laravel 10):
```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('loadbalancer:health-check --timeout=3 --consecutive=2')->everyMinute();
```

### Telemetry Retention (`loadbalancer:prune-metrics`)

Prunes historical telemetry rows in safe, chunked transactions to avoid locking SQL Server or MySQL tables:

```bash
# Delete metric records older than 7 days in chunks of 1000:
php artisan loadbalancer:prune-metrics --days=7 --chunk=1000
```

Schedule nightly in your console routes:
```php
Schedule::command('loadbalancer:prune-metrics --days=14')->daily();
```

---

## Balancing Strategies & Sticky Sessions

### Supported Strategies

Set your strategy in `.env` via `LOAD_BALANCER_DEFAULT_STRATEGY`:

| Strategy Key | Description | Best For |
| :--- | :--- | :--- |
| `round_robin` | Even sequential distribution across all healthy nodes | Uniform backend servers |
| `weighted_random` | Distributes proportionally according to each node's `weight` column | Heterogeneous server capacities |
| `active_passive` | Routes 100% of traffic to `is_primary=true` nodes; fails over to secondaries | Disaster recovery setups |
| `adaptive_latency` | Power-of-Two-Choices (P2C) picking the lowest EMA latency node | Real-time dynamic workloads |
| `least_redirects` | Routes to the node with the lowest 24-hour total request tally | Redirect mode |
| `random` | Arbitrary random distribution | Development and testing |

### Rendezvous (HRW) Consistent Hashing

When `LOAD_BALANCER_STICKY_SESSIONS=true` is enabled, the proxy uses **Highest Random Weight (HRW) / Rendezvous Hashing** rather than simple modulo hashing.

- The client IP address is hashed against each active node's identifier (`md5("$ip-$nodeId")`).
- The node yielding the highest weight receives the request.
- **Advantage**: When a backend server is added or removed, only $1/N$ of keys are remapped, preserving cache warmth across the remaining $N-1$ servers.

### Power-of-Two-Choices (P2C) Adaptive Latency

The `adaptive_latency` strategy uses **Power-of-Two-Choices (P2C)**:
1. Samples two healthy nodes at random.
2. Compares their Exponential Moving Average (EMA) response latency.
3. Selects the node with the lower latency.

This prevents the classic "thundering herd" oscillation where all requests rush to a single momentarily fast server and overload it.

### Custom Strategy Implementation

Create a custom class implementing `LoadBalancingStrategy`:

```php
namespace App\LoadBalancing;

use Isg\LoadBalancer\Contracts\LoadBalancingStrategy;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Isg\LoadBalancer\Models\UpstreamNode;

class RegionalAffinityStrategy implements LoadBalancingStrategy
{
    public function selectNode(Collection $nodes, ?Request $request = null): ?UpstreamNode
    {
        $region = $request?->header('CF-IPCountry', 'US');
        return $nodes->firstWhere('metadata.region', $region) ?? $nodes->first();
    }
}
```

Register it in `config/load-balancer.php`:
```php
'strategies' => [
    // ...
    'regional' => \App\LoadBalancing\RegionalAffinityStrategy::class,
],
```

---

## Configuration Reference (`.env`)

| Variable | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `LOAD_BALANCER_MODE` | string | `proxy` | `proxy` (pipes payloads through gateway) or `redirect` (issues HTTP 302) |
| `LOAD_BALANCER_DEFAULT_STRATEGY` | string | `round_robin` | Default routing strategy key |
| `LOAD_BALANCER_TIMEOUT` | int | `30` | Upstream read/execution timeout in seconds |
| `LOAD_BALANCER_CONNECT_TIMEOUT` | int | `5` | Upstream socket connection timeout in seconds |
| `LOAD_BALANCER_TLS_VERIFY` | bool | `true` | Verify upstream SSL/TLS certificates (set `false` for self-signed LAN certs) |
| `LOAD_BALANCER_FORCE_HTTPS` | bool | `false` | Force `https://` schemes when querying upstreams in production |
| `LOAD_BALANCER_STICKY_SESSIONS` | bool | `false` | Enable Rendezvous consistent IP hashing |
| `LOAD_BALANCER_STREAM_RESPONSES` | bool | `true` | Stream backend responses with low memory footprint |
| `LOAD_BALANCER_MAX_BODY_SIZE` | int | `10485760` | Maximum request body size in bytes (10MB). Rejects with 413 if exceeded |
| `LOAD_BALANCER_LOGGING` | bool | `true` | Enable asynchronous metrics recording |
| `LOAD_BALANCER_SAMPLE_RATE` | float | `1.0` | Probability (0.01 - 1.0) to sample telemetry at high scale |
| `LOAD_BALANCER_CIRCUIT_BREAKER_ENABLED` | bool | `true` | Enable sliding-window circuit breaker |
| `LOAD_BALANCER_CIRCUIT_BREAKER_FAIL_RATE` | float | `0.5` | Failure rate threshold (50%) to trip breaker |
| `LOAD_BALANCER_CIRCUIT_BREAKER_MIN_REQ` | int | `5` | Minimum request volume before breaker can trip |
| `LOAD_BALANCER_CIRCUIT_BREAKER_DECAY` | int | `30` | Cool-off window in seconds before half-open canary probe |
| `LOAD_BALANCER_SIGN_REQUESTS` | bool | `false` | Enable HMAC-SHA256 request signing for zero-trust |
| `LOAD_BALANCER_HMAC_SECRET` | string | `null` | 64-byte shared secret key for HMAC signature generation |
| `LOAD_BALANCER_WAF_ENABLED` | bool | `true` | Enable internal IP rate limiter |
| `LOAD_BALANCER_WAF_LIMIT` | int | `500` | Max requests per minute per IP |
| `LOAD_BALANCER_CACHE_ENABLED` | bool | `false` | Enable sub-millisecond gateway response microcaching |
| `LOAD_BALANCER_CACHE_TTL` | int | `5` | Microcache duration in seconds (1 to 60s) |
| `LOAD_BALANCER_CACHE_STORE` | string | `null` | Cache repository store (`valkey`, `redis`, `database`, `file`) |
| `LOAD_BALANCER_ACTIVE_KEY_ID` | string | `null` | Active key identifier tag (e.g. `v2`) attached via `X-LB-Key-Id` |
| `LOAD_BALANCER_GRACE_SECRETS` | string | `null` | Comma-separated previous secrets accepted during zero-downtime key rotation |
| `LOAD_BALANCER_METRICS_ENABLED` | bool | `false` | Enable `/api/load-balancer/metrics` endpoint |
| `LOAD_BALANCER_METRICS_TOKEN` | string | `null` | Required Bearer token for accessing metrics |
| `LOAD_BALANCER_METRICS_IP_ALLOWLIST` | string | `null` | Comma-separated list of allowed CIDRs / IPs for metrics |
| `REDIS_HOST` | string | `127.0.0.1` | Valkey / Redis cluster service hostname |
| `REDIS_PORT` | int | `6379` | Valkey / Redis cluster service port |

---

## Testing & Quality Assurance

The package includes comprehensive unit and integration test coverage:

```bash
# Run PHPUnit test suite:
php vendor/bin/phpunit

# Or via Docker:
docker exec app03_gateway vendor/bin/phpunit
```

All 53 unit and integration tests (172 assertions, 100% green) validate:
- Zero-trust header sanitization, IP proxy spoofing prevention, and multi-cookie preservation.
- Laravel Sanctum authentication, Bearer token verification, and token revocation on logout.
- Gateway response microcaching (`X-Cache: HIT / MISS`) and authentication bypass.
- Zero-downtime HMAC key rotation (`X-LB-Key-Id`, active key, and grace-period secrets).
- Turnkey installation CLI wizard (`loadbalancer:install`) and `'gateway.verify'` middleware.
- REST API cluster and IP affinity rule database management with validation.
- Sliding-window circuit breaker tripping and fail-open resilience.
- Windows NTFS path traversal defenses (alternate streams, 8.3 short names, trailing dots).
- Rendezvous consistent hashing balance and minimal disruption.
- Concurrent health check pooling and metric pruning.
- Protected metrics authorization and HMAC signature verification.

---

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
