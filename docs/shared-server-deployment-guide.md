# Shared Server Deployment & Coexistence Guide

A complete operational guide for deploying the **ISG Load Balancer & Ingress Gateway (`app03`)** on a **Shared Windows Server (IIS 10) or Shared Linux Server** alongside existing enterprise applications, websites, and legacy systems **without port conflicts, route collisions, or neighbor interference**.

---

## Shared Server Architecture Overview

When deploying to a shared server hosting other internal applications (e.g., ERP, CRM, intranet sites, accounting portals):

```
┌────────────────────────────────────────────────────────────────────────┐
│             Shared Windows Server (IIS 10) / Shared Linux             │
│                                                                        │
│  [Port 80 / 443 Ingress]                                               │
│         ├── Host: crm.company.local     ──► [CRM App Pool]             │
│         ├── Host: erp.company.local     ──► [ERP App Pool]             │
│         └── Host: gateway.company.local ──► [Dedicated Load Balancer]  │
│                                                   │                    │
│                                                   ├── Dedicated AppPool│
│                                                   ├── Isolated .env    │
│                                                   ├── Scoped Valkey DB │
│                                                   └── Independent PHP  │
└───────────────────────────────────────────────────┼────────────────────┘
                                                    │
                                                    ▼
                                      ┌───────────────────────────┐
                                      │  Backend Processing Nodes │
                                      │  (app01, app02, ..., appN)│
                                      └───────────────────────────┘
```

---

## 1. IIS Coexistence Strategies (3 Options)

Choose the strategy that matches your network requirements:

### Option A: Dedicated Host Header Binding on Port 80/443 (Recommended)
This is the cleanest enterprise deployment. Multiple IIS sites share standard ports `80` and `443` using **IIS Host Name Bindings (SNI)**:

1. In **IIS Manager**, right-click **Sites** $\rightarrow$ **Add Website**.
2. **Site name**: `ISG_LoadBalancer`
3. **Physical path**: `C:\inetpub\wwwroot\load-balancer\public`
4. **Binding**:
   - Type: `http` (or `https`)
   - IP address: `All Unassigned`
   - Port: `80` (or `443`)
   - **Host name**: `gateway.company.local` (or `app03.company.local`)
5. Click **OK**.

> **Result**: IIS inspects the HTTP `Host` header. Requests to `crm.company.local` route to your CRM; only requests to `gateway.company.local` route to the Load Balancer. **Zero route or port collisions.**

---

### Option B: Dedicated Port Binding (e.g., Port `8080` / `8443`)
If your internal network does not use custom DNS host headers, bind the Load Balancer to a separate dedicated port:

1. In **IIS Manager**, create site with:
   - Port: `8080` (HTTP) or `8443` (HTTPS)
   - Host name: *(leave blank)*
2. In **Windows Defender Firewall**, allow incoming TCP traffic on port `8080`:
   ```cmd
   netsh advfirewall firewall add rule name="Allow IIS Port 8080" dir=in action=allow protocol=TCP localport=8080
   ```

---

### Option C: Sub-Path / Virtual Directory under an Existing IIS Site
If the gateway must live inside an existing IIS parent website (e.g., `https://main-site.com/gateway`):

1. In your existing IIS site, right-click $\rightarrow$ **Add Application...**:
   - **Alias**: `gateway`
   - **Physical path**: `C:\inetpub\wwwroot\load-balancer\public`
2. In `routes/web.php`, scope the `loadBalancer` route macro so it does not capture parent site routes:
   ```php
   use Illuminate\Support\Facades\Route;

   // Proxies only traffic directed to /gateway or /api:
   Route::loadBalancer('gateway/{any?}');
   ```

---

## 2. Dedicated IIS Application Pool (Process Isolation)

> [!CAUTION]
> Never run the Load Balancer under `DefaultAppPool` on a shared server! If another shared site crashes, leaks memory, or exhausts worker threads, it will take down the Load Balancer.

### Create an Isolated AppPool:
Open an Administrative Command Prompt:

```cmd
%windir%\system32\inetsrv\appcmd.exe add apppool /name:"LoadBalancerPool" /managedRuntimeVersion:""
%windir%\system32\inetsrv\appcmd.exe set site /site.name:"ISG_LoadBalancer" /[path='/'].applicationPool:"LoadBalancerPool"
```

### AppPool Performance & Reliability Settings:
```cmd
:: Enable dynamic process recycling on idle
%windir%\system32\inetsrv\appcmd.exe set config /section:applicationPools /[name='LoadBalancerPool'].recycling.periodicRestart.time:00:00:00

:: Set private memory limit (prevent memory leaks from affecting neighbor sites)
%windir%\system32\inetsrv\appcmd.exe set config /section:applicationPools /[name='LoadBalancerPool'].recycling.periodicRestart.privateMemory:524288

:: Set worker process pinging
%windir%\system32\inetsrv\appcmd.exe set config /section:applicationPools /[name='LoadBalancerPool'].processModel.pingingEnabled:true
```

---

## 3. Dedicated NTFS Security Isolation

On a shared server, prevent other web applications or users from reading your `.env` secrets, HMAC keys, or database credentials:

```cmd
:: 1. Disable inheritance on the load-balancer folder
icacls "C:\inetpub\wwwroot\load-balancer" /inheritance:d

:: 2. Grant Administrators full control
icacls "C:\inetpub\wwwroot\load-balancer" /grant "Administrators":(OI)(CI)F

:: 3. Grant ONLY this specific AppPool read access to the project
icacls "C:\inetpub\wwwroot\load-balancer" /grant "IIS AppPool\LoadBalancerPool":(OI)(CI)R

:: 4. Grant ONLY this specific AppPool write access to storage and cache
icacls "C:\inetpub\wwwroot\load-balancer\storage" /grant "IIS AppPool\LoadBalancerPool":(OI)(CI)M /T
icacls "C:\inetpub\wwwroot\load-balancer\bootstrap\cache" /grant "IIS AppPool\LoadBalancerPool":(OI)(CI)M /T
```

*Now, even if a neighbor application on the shared server is compromised via file disclosure, it cannot read `load-balancer\.env`.*

---

## 4. Valkey / Redis Namespacing on a Shared Server

If the shared server runs a single Valkey/Redis instance shared across multiple projects:

Configure unique cache and connection prefixes in `load-balancer/.env`:

```env
# Shared Valkey / Redis Configuration
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PREFIX="isg_lb_gateway_"
CACHE_PREFIX="isg_lb_cache_"

# Dedicated Valkey DB Index (0-15)
REDIS_DB=3
CACHE_DB=3
```

> **Why this matters**:
> - Replay nonces (`lb_seen_nonce_*`), microcaches (`lb_proxy_cache_*`), and circuit breaker states are stored with the `isg_lb_` prefix or in database index `3`.
> - Other applications running on the shared server (e.g. CRM on DB `0`) will never experience key collisions or flushed cache conflicts.

---

## 5. Database Isolation on a Shared SQL Server

If you share a central Microsoft SQL Server instance with other company databases:

1. **Dedicated Database**:
   Create a small dedicated database (e.g., `ISG_LoadBalancerDB`) rather than sharing tables in your core business database:
   ```sql
   CREATE DATABASE ISG_LoadBalancerDB;
   ```
2. **Dedicated Connection in `.env`**:
   ```env
   DB_CONNECTION=sqlsrv
   DB_HOST=127.0.0.1
   DB_PORT=1433
   DB_DATABASE=ISG_LoadBalancerDB
   DB_USERNAME=lb_gateway_user
   DB_PASSWORD=YourStrongPasswordHere!
   ```
3. **Shared Database Table Prefix (Alternative)**:
   If your DBA only permits one shared database, set the prefix in `config/database.php`:
   ```php
   'sqlsrv' => [
       'prefix' => 'isg_lb_',
   ],
   ```
   Tables will be created as `isg_lb_upstream_nodes`, `isg_lb_ip_rules`, `isg_lb_request_metrics`, cleanly separating them from existing tables.

---

## 6. PHP Multi-Version Coexistence (PHP 7.x alongside PHP 8.3)

Many shared Windows servers run older applications on PHP 7.2 / 7.4. You can run PHP 8.3 specifically for the Load Balancer without upgrading neighbor sites:

1. Install PHP 8.3 in `C:\PHP83`. Keep legacy PHP in `C:\PHP74`.
2. In IIS, do **NOT** register PHP 8.3 globally in the root server handlers.
3. Instead, assign PHP 8.3 **only at the `ISG_LoadBalancer` site level**:
   ```cmd
   %windir%\system32\inetsrv\appcmd.exe set config "ISG_LoadBalancer" -section:system.webServer/handlers /+"[name='PHP83_FastCGI',path='*.php',verb='*',modules='FastCgiModule',scriptProcessor='C:\PHP83\php-cgi.exe',resourceType='Either']"
   ```
4. Neighbor legacy sites will continue using PHP 7.4 completely undisturbed.

---

## Summary Checklist for Shared Server Deployment

- [ ] **Site Isolation**: Configured dedicated IIS site with Host Name Binding (`gateway.company.local`) or Port `8080`.
- [ ] **AppPool Isolation**: Created dedicated `LoadBalancerPool` with private memory limits.
- [ ] **File Permissions**: Locked down permissions strictly to `IIS AppPool\LoadBalancerPool`.
- [ ] **Valkey / Redis Isolation**: Set `REDIS_PREFIX="isg_lb_"` and `REDIS_DB=3`.
- [ ] **Database Isolation**: Used dedicated database `ISG_LoadBalancerDB` or `isg_lb_` table prefix.
- [ ] **PHP Version Isolation**: Bound `C:\PHP83\php-cgi.exe` exclusively to the Load Balancer site.
