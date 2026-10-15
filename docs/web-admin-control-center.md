# Visual Cluster Web Admin Control Center Guide

Operational guide for the modern, dark-mode single-page administrative control center built directly into the **ISG Load Balancer Gateway (`app03`)**.

---

## Access & URL

- **URL**: [http://localhost:8080/admin/cluster](http://localhost:8080/admin/cluster)
- **Framework**: Zero external dependencies. Pure vanilla HTML5, modern CSS3 glassmorphism, and asynchronous JavaScript.
- **Auto-Refresh**: Live telemetry polls automatically every 10 seconds.

---

## 1. Zero-Trust Security & Authentication

By default, visiting the dashboard is **completely locked**:

```
┌────────────────────────────────────────────────────────┐
│               🔒 Authentication Required               │
│                                                        │
│  Cluster administration and observability require a    │
│  valid administrative Bearer token. Enter your         │
│  LOAD_BALANCER_METRICS_TOKEN in the navbar and click    │
│  "Unlock".                                             │
└────────────────────────────────────────────────────────┘
```

### Unlocking the Dashboard:
1. Paste your `LOAD_BALANCER_METRICS_TOKEN` (configured in your `.env`) into the **Admin Token** input field in the top navigation bar.
2. Click **Unlock**.
3. If valid:
   - The status badge changes to `✓ Unlocked` (green).
   - Real-time KPI counters populate.
   - Upstream node cards render with interactive controls.
   - The token is safely preserved in browser `sessionStorage` for the duration of your session.
4. If invalid:
   - The dashboard displays a red **"⛔ Unauthorized Access (HTTP 401)"** rejection banner.

---

## 2. Key Dashboard Capabilities

### A. Live KPI Overview
- **Cluster Servers Online**: Displays active healthy nodes vs total registered nodes (e.g., `10 / 10`).
- **Total Cluster Requests**: Real-time processed request counter.
- **Average Latency**: Average proxy latency across active nodes (typically `0.4 ms`).
- **Zero-Trust Security**: Current signature algorithm (`HMAC-SHA256`) and key rotation state.

### B. Dynamic 1-Click Traffic Draining (Maintenance Mode)
Need to perform zero-downtime maintenance or deploy updates to a specific backend server?
1. Locate the backend node card (e.g., `backend_app05`).
2. Click **"Drain / Maintain"**.
3. The node immediately turns amber (`○ Draining`).
4. The gateway atomically flushes the node cache in Valkey/Redis. `app03` stops sending new incoming requests to this server instantly.
5. Once maintenance is complete, click **"Re-Activate Node"** to resume traffic distribution.

### C. Dynamic Node Provisioning (+ Add Upstream Node)
1. Click the blue **"+ Add Upstream Node"** button in the top navigation bar.
2. Fill in the modal fields:
   - **Node Identifier**: e.g., `backend_app11`.
   - **Upstream Target URL**: e.g., `http://10.0.0.21:8000`.
   - **Load Balancing Weight**: e.g., `1` (or higher for larger servers).
3. Click **Save Node**.
4. The node is validated against SSRF cloud metadata rules, inserted into the database, and added to the gateway's rotation within milliseconds.

### D. Permanent Node Decommissioning (Delete)
1. Click the red **"Delete"** button on any decommissioned server.
2. Confirm the prompt: *"Are you sure you want to remove upstream node 'app10' from the cluster?"*
3. The node is safely removed from the database and purged from the active node cache.
