# Documentation Index & Architecture Sitemap

Welcome to the **ISG Load Balancer & Ingress Gateway** technical documentation library. Below is the master index of all architectural specifications, operational runbooks, security whitepapers, and guides.

---

## Technical Guides & Runbooks

| Document | Purpose | Target Audience |
| :--- | :--- | :--- |
| [**Windows Server & IIS 10 Installation Guide**](file:///f:/ai-projects/load-balancer/docs/iis-installation-guide.md) | Complete step-by-step setup for Windows Server 2016/2019/2022 + IIS 10, FastCGI tuning, URL Rewrite 2.1, `HTTP_AUTHORIZATION` whitelisting, and NTFS permissions. | Windows SysAdmins & DevOps |
| [**Shared Server Deployment & Coexistence Guide**](file:///f:/ai-projects/load-balancer/docs/shared-server-deployment-guide.md) | How to safely install on a shared Windows/Linux server alongside existing CRM, ERP, and legacy sites using AppPool isolation, Host Name Bindings, and Valkey/DB namespacing. | SysAdmins & Enterprise Architects |
| [**Backend Zero-Downtime Migration Runbook**](file:///f:/ai-projects/load-balancer/docs/backend-zero-downtime-migration.md) | 4-phase zero-downtime migration strategy: Permissive mode (`strict: false`), canary verification, DNS cutover, and strict perimeter lockdown without breaking current integrations. | Backend Engineers & Tech Leads |
| [**OWASP Top 10 Security Architecture**](file:///f:/ai-projects/load-balancer/docs/owasp-security-architecture.md) | In-depth security analysis and defense matrix covering SSRF metadata shields, CRLF header injection stripping, atomic anti-replay nonces, and brute-force throttling. | Security Engineers & SecOps |
| [**Valkey Engine & Performance Architecture**](file:///f:/ai-projects/load-balancer/docs/valkey-and-performance.md) | Linux Foundation Valkey 8 integration, sub-millisecond response microcaching (`X-Cache: HIT/MISS`), keep-alive socket reuse, and 10-node scale benchmark results. | Performance Engineers & SREs |
| [**Visual Cluster Web Admin Control Center**](file:///f:/ai-projects/load-balancer/docs/web-admin-control-center.md) | Operational walkthrough for the dark-mode GUI at `/admin/cluster`, authentication lock screen, 1-click dynamic node draining, and cluster provisioning. | Operations & Developers |

---

## Quick Navigation Links

- **Main Project Readme**: [`README.md`](file:///f:/ai-projects/load-balancer/README.md)
- **Interactive Swagger Documentation**: `http://localhost:8080/api/docs`
- **Visual Web Admin GUI**: `http://localhost:8080/admin/cluster`
- **10-Backend Benchmark Graphic Report**: [`docker/performance_report_10nodes.png`](file:///f:/ai-projects/load-balancer/docker/performance_report_10nodes.png)
- **MSSQL Idempotent DBA Migration Scripts**: [`database/mssql/`](file:///f:/ai-projects/load-balancer/database/mssql/)
- **Production IIS Configuration**: [`config/iis/web.config`](file:///f:/ai-projects/load-balancer/config/iis/web.config)
