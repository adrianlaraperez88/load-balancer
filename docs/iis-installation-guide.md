# Windows Server & IIS 10 Production Installation Guide

Complete operational runbook for deploying the **ISG Load Balancer & Ingress Gateway (`app03`)** on **Windows Server 2016, 2019, and 2022** with **Internet Information Services (IIS 10)**, PHP 8.3 FastCGI, and Microsoft SQL Server.

---

## Architecture Overview

```
┌────────────────────────────────────────────────────────────────────────┐
│               Windows Server 2016 / 2019 / 2022 + IIS 10               │
│                                                                        │
│  [IIS 10 Web Server] ───► [FastCGI php-cgi.exe] ───► [Laravel Gateway] │
│          │                                                             │
│          ├── URL Rewrite 2.1 (HTTP_AUTHORIZATION Pass-Through)         │
│          ├── Request Filtering (Hidden Segments & 10MB Limit)          │
│          └── NTFS Path Traversal Shields (::$DATA, 8.3 Names)          │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ Proxies via Private VPC / LAN
                                    ▼
                      ┌───────────────────────────┐
                      │  Backend Processing Nodes │
                      │  (app01, app02, ..., appN)│
                      └───────────────────────────┘
```

---

## 1. Server Prerequisites

### A. Windows Features & Roles
Open **Server Manager** $\rightarrow$ **Manage** $\rightarrow$ **Add Roles and Features**:
- Role: **Web Server (IIS)**
- Under **Web Server** $\rightarrow$ **Application Development**, verify the following are installed:
  - [x] **CGI** (includes the FastCGI module)
  - [x] **ISAPI Extensions**
  - [x] **ISAPI Filters**

### B. Microsoft URL Rewrite Module 2.1
Download and install the official Microsoft x64 installer:
- **Download Link**: [Microsoft URL Rewrite Module 2.1 (x64)](https://www.iis.net/downloads/microsoft/url-rewrite)

### C. Visual C++ Redistributable
Install the latest **Visual C++ 2015–2022 Redistributable (x64)** from Microsoft:
- [Visual C++ Redistributable Download](https://aka.ms/vs/17/release/vc_redist.x64.exe)

### D. PHP 8.3 Non-Thread Safe (NTS) x64
1. Download **PHP 8.3 Non-Thread Safe (NTS) x64** from [windows.php.net](https://windows.php.net/download/).
2. Extract the archive to `C:\PHP83`.
3. Copy `php.ini-production` to `C:\PHP83\php.ini`.
4. Configure required extensions and performance parameters in `C:\PHP83\php.ini`:

```ini
; Extension directory
extension_dir = "C:\PHP83\ext"

; Required extensions
extension=curl
extension=mbstring
extension=openssl
extension=fileinfo
extension=pdo_sqlsrv
extension=sqlsrv
extension=opcache

; FastCGI configuration
fastcgi.impersonate = 1
fastcgi.logging = 0
cgi.fix_pathinfo = 0
cgi.force_redirect = 0

; Execution limits
max_execution_time = 300
max_input_time = 60
memory_limit = 512M
post_max_size = 10M
upload_max_filesize = 10M

; Opcache for Windows IIS
opcache.enable = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 10000
opcache.revalidate_freq = 0
opcache.validate_timestamps = 0
```

---

## 2. FastCGI Handler & Concurrency Tuning

By default, IIS FastCGI restricts concurrency, causing worker starvation and dropped connections under high throughput.

Open an **Administrative Command Prompt** and execute:

```cmd
%windir%\system32\inetsrv\appcmd.exe set config -section:system.webServer/fastCgi /+"[fullPath='C:\PHP83\php-cgi.exe',arguments='',activityTimeout='300',requestTimeout='300',instanceMaxRequests='10000']" /commit:apphost
```

Add the PHP FastCGI Handler Mapping for IIS:

```cmd
%windir%\system32\inetsrv\appcmd.exe set config -section:system.webServer/handlers /+"[name='PHP_via_FastCGI',path='*.php',verb='*',modules='FastCgiModule',scriptProcessor='C:\PHP83\php-cgi.exe',resourceType='Either']" /commit:apphost
```

---

## 3. Whitelist `HTTP_AUTHORIZATION` in IIS

> [!CAUTION]
> IIS strips the `Authorization` header by default. Our `web.config` rewrite rule captures it and populates `HTTP_AUTHORIZATION`.
> If `HTTP_AUTHORIZATION` is NOT declared in IIS Allowed Server Variables, IIS will immediately crash with **HTTP 500.50 (URL Rewrite Module Error)**.

Execute this command to register `HTTP_AUTHORIZATION` in the allowed server variables:

```cmd
%windir%\system32\inetsrv\appcmd.exe set config "Default Web Site" -section:system.webServer/rewrite/allowedServerVariables /+"[name='HTTP_AUTHORIZATION']" /commit:apphost
```
*(Replace `"Default Web Site"` with your specific site name if different).*

---

## 4. Deploying Application Code & `web.config`

1. Clone or extract the application to your IIS root:
   ```cmd
   git clone <repo-url> C:\inetpub\wwwroot\load-balancer
   cd C:\inetpub\wwwroot\load-balancer
   composer install --no-dev --optimize-autoloader
   ```

2. Point the IIS Site **Physical Path** to the `public/` directory:
   - Physical Path: `C:\inetpub\wwwroot\load-balancer\public`

3. Verify that [`config/iis/web.config`](file:///f:/ai-projects/load-balancer/config/iis/web.config) is present in `public\web.config`:
   ```cmd
   copy C:\inetpub\wwwroot\load-balancer\config\iis\web.config C:\inetpub\wwwroot\load-balancer\public\web.config
   ```

### Production `web.config` Reference:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
    <system.webServer>
        <rewrite>
            <rules>
                <!-- Forward Authorization Header to PHP FastCGI -->
                <rule name="Import Laravel Authorization Header" stopProcessing="false">
                    <match url=".*" />
                    <conditions logicalGrouping="MatchAll">
                        <add input="{HTTP_AUTHORIZATION}" pattern="(.+)" />
                    </conditions>
                    <serverVariables>
                        <set name="HTTP_AUTHORIZATION" value="{C:1}" />
                    </serverVariables>
                </rule>
                
                <!-- Standard Laravel Rewrite -->
                <rule name="Laravel Standard Rewrite" stopProcessing="true">
                    <match url="^" ignoreCase="false" />
                    <conditions logicalGrouping="MatchAll">
                        <add input="{REQUEST_FILENAME}" matchType="IsDirectory" negate="true" />
                        <add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
                    </conditions>
                    <action type="Rewrite" url="index.php" />
                </rule>
            </rules>
        </rewrite>

        <!-- Request Filtering: Hide sensitive assets and enforce 10MB body limit -->
        <security>
            <requestFiltering>
                <requestLimits maxAllowedContentLength="10485760" />
                <hiddenSegments>
                    <add segment=".env" />
                    <add segment="web.config" />
                    <add segment="composer.json" />
                    <add segment="composer.lock" />
                    <add segment="storage" />
                    <add segment="resources" />
                </hiddenSegments>
            </requestFiltering>
        </security>

        <!-- Baseline Security Headers -->
        <httpProtocol>
            <customHeaders>
                <remove name="X-Powered-By" />
                <add name="X-Content-Type-Options" value="nosniff" />
                <add name="X-XSS-Protection" value="1; mode=block" />
                <add name="X-Frame-Options" value="SAMEORIGIN" />
            </customHeaders>
        </httpProtocol>

        <defaultDocument>
            <files>
                <clear />
                <add value="index.php" />
            </files>
        </defaultDocument>
    </system.webServer>
</configuration>
```

---

## 5. Configure Windows NTFS Permissions

The IIS worker processes (`IIS_IUSRS` and `IUSR`) must have write permissions on `storage` and `bootstrap/cache`:

```cmd
icacls "C:\inetpub\wwwroot\load-balancer\storage" /grant "IIS_IUSRS":(OI)(CI)M /T
icacls "C:\inetpub\wwwroot\load-balancer\storage" /grant "IUSR":(OI)(CI)M /T
icacls "C:\inetpub\wwwroot\load-balancer\bootstrap\cache" /grant "IIS_IUSRS":(OI)(CI)M /T
icacls "C:\inetpub\wwwroot\load-balancer\bootstrap\cache" /grant "IUSR":(OI)(CI)M /T
```

---

## 6. Execute Turnkey Installer

Run the interactive setup command:

```cmd
cd C:\inetpub\wwwroot\load-balancer
php artisan loadbalancer:install --mode=proxy --strategy=round_robin
```

This command automatically:
1. Publishes configuration to `config/load-balancer.php`.
2. Generates a cryptographically strong 64-character HMAC secret key and appends it to `.env`.
3. Runs database migrations (or use DBA T-SQL scripts in `database/mssql/` if restricted).
4. Seeds initial upstream backend nodes.
5. Issues an administrative Sanctum Bearer token for accessing metrics and the Web Admin GUI.

---

## 7. Verify the Deployment

1. **Verify Health Endpoint**:
   ```cmd
   curl -i http://localhost/up
   ```
   *Expected: `HTTP/1.1 200 OK`*

2. **Verify Swagger UI**:
   Open browser at: `http://localhost/api/docs`

3. **Verify Web Admin Control Center**:
   Open browser at: `http://localhost/admin/cluster`
