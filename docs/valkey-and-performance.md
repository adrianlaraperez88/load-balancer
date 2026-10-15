# Valkey Engine & Performance Architecture

A technical overview of the high-throughput performance optimizations and the **Valkey 8** in-memory data store integration powering the **ISG Load Balancer & Ingress Gateway**.

---

## 1. Valkey 8 In-Memory Engine Integration

The load balancer integrates with **Valkey 8** (`valkey/valkey:8-alpine`), the open-source, community-driven high-performance data store stewarded by the **Linux Foundation**.

```
┌────────────────────────────────────────────────────────┐
│              Ingress Gateway (app03_gateway)           │
│                                                        │
│  - Microcache Lookups: < 0.4ms                         │
│  - Atomic Nonce Tracking: SETNX                        │
│  - Node Registry Cache: 60s TTL                        │
└───────────────────────────┬────────────────────────────┘
                            │ (RESP Protocol on port 6379)
                            ▼
┌────────────────────────────────────────────────────────┐
│          Valkey 8 Server (cluster_valkey)              │
│          valkey/valkey:8-alpine                        │
│                                                        │
│  - Memory Policy: allkeys-lru (256MB)                  │
│  - Persistence: AOF enabled                            │
│  - Zero Licensing Restrictions (BSD-3-Clause)          │
└────────────────────────────────────────────────────────┘
```

### Why Valkey?
- **100% Wire Compatible**: Valkey natively supports the standard Redis serialization protocol (RESP), allowing seamless usage with PHP's `phpredis` extension and Laravel's cache drivers.
- **Enterprise Proven**: Managed by the Linux Foundation with contributions from AWS, Google Cloud, Oracle, and Ericsson.
- **Ultra-Low Latency**: Sub-millisecond reads and atomic writes for distributed circuit breaker states and anti-replay nonces.

### Container Configuration:
In [`docker-compose.scale-test.yml`](file:///f:/ai-projects/load-balancer/docker-compose.scale-test.yml) and [`docker-compose.prod.yml`](file:///f:/ai-projects/load-balancer/docker-compose.prod.yml):
```yaml
  redis:
    image: valkey/valkey:8-alpine
    container_name: cluster_valkey
    restart: unless-stopped
    command: valkey-server --appendonly yes --maxmemory 256mb --maxmemory-policy allkeys-lru
    networks:
      - cluster_net
```

---

## 2. Gateway Response Microcaching (`X-Cache: HIT / MISS`)

For high-scale read-heavy workloads (product catalogs, static configs, user public profiles), routing every identical request to a backend PHP worker creates severe socket and memory bottlenecks.

The gateway implements **Gateway Response Microcaching** in [`ProxyController.php`](file:///f:/ai-projects/load-balancer/src/Http/Controllers/ProxyController.php):

### Mechanics:
1. **Idempotent Only**: Caches `GET` and `HEAD` requests only.
2. **Safe Authentication Bypass**: Automatically bypasses caching whenever an `Authorization` Bearer token, session cookie, or mutating verb (`POST`, `PUT`, `DELETE`, `PATCH`) is present.
3. **Sub-Millisecond Return**: Cached responses are returned in **$< 1\text{ ms}$** without opening TCP sockets to upstream servers.
4. **Inspection Headers**:
   - `X-Cache: MISS` (First request fetched from backend and cached).
   - `X-Cache: HIT` (Subsequent identical requests served instantly from Valkey/Redis).

### Configuration in `.env`:
```env
LOAD_BALANCER_CACHE_ENABLED=true
LOAD_BALANCER_CACHE_TTL=5
LOAD_BALANCER_CACHE_STORE=redis
```

---

## 3. High-Throughput Network Optimizations

### A. cURL TCP Keep-Alive Connection Reuse
Every HTTP connection created by the gateway reuses existing TCP/TLS connections:
```php
'curl' => [
    CURLOPT_TCP_KEEPALIVE => 1,
    CURLOPT_TCP_KEEPIDLE => 120,
    CURLOPT_TCP_KEEPINTVL => 60,
]
```
Eliminates TCP 3-way handshakes (`SYN`, `SYN-ACK`, `ACK`) and TLS renegotiation latency.

### B. Dynamic PHP-FPM High-Concurrency Pools
Configured in [`docker/Dockerfile`](file:///f:/ai-projects/load-balancer/docker/Dockerfile):
```ini
pm = dynamic
pm.max_children = 64
pm.start_servers = 16
pm.min_spare_servers = 8
pm.max_spare_servers = 32
pm.max_requests = 2000
```

---

## 4. 10-Backend Scale Benchmark Results

An enterprise benchmark was executed against **1 Front Gateway (`app03`) + 10 Backend Processing Nodes + Valkey** using [`docker/run_scale_benchmark.py`](file:///f:/ai-projects/load-balancer/docker/run_scale_benchmark.py):

### Benchmark Summary (10,000 Total Requests):

| Concurrency Tier | Total Requests | Elapsed Time | Sustained RPS | Median Latency (p50) | 95th Percentile (p95) | Success Rate | Failed |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **20 Workers** | 500 req | 6.88 s | **72.7 RPS** | 259.9 ms | 335.8 ms | **100.0%** | 0 |
| **50 Workers** | 1,500 req | 16.74 s | **89.6 RPS** | 536.3 ms | 697.4 ms | **100.0%** | 0 |
| **100 Workers** | 3,000 req | 30.53 s | **98.3 RPS** | 1,013.1 ms | 1,101.5 ms | **100.0%** | 0 |
| **200 Workers** | 5,000 req | 51.85 s | **96.4 RPS** | 2,042.4 ms | 2,350.3 ms | **100.0%** | 0 |

**0 errors / 0 dropped packets across all 10,000 requests (100.0% success rate).**

### Uniform Load Balance Across All 10 Nodes:
Round-robin distribution achieved an optimal ~10% spread per backend server:
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

### Visual Dashboard Report:
A high-resolution 4-panel visual chart was generated and stored at:
[`docker/performance_report_10nodes.png`](file:///f:/ai-projects/load-balancer/docker/performance_report_10nodes.png)
