# OWASP Top 10 & Reverse Proxy Security Architecture

An in-depth security whitepaper detailing the defensive controls implemented in the **ISG Load Balancer & Ingress Gateway** to safeguard enterprise environments against the **OWASP Top 10:2021** and known reverse proxy exploitation techniques.

---

## Security Control Matrix

| OWASP Vulnerability | Risk Mechanics | Defensive Implementation | File Reference |
| :--- | :--- | :--- | :--- |
| **A10: Server-Side Request Forgery (SSRF)** | Upstream nodes pointed to Cloud Instance Metadata Services (`169.254.169.254`, `metadata.google.internal`) to exfiltrate IAM tokens or credentials. | Dual-layer validation strictly blocks `169.254.0.0/16`, `fd00:ec2::254`, `instance-data`, and `metadata.google.internal` both on node registration and on proxy dispatch. | [`ProxyController.php`](file:///f:/ai-projects/load-balancer/src/Http/Controllers/ProxyController.php#L305)<br>[`NodeManagementAPIController.php`](file:///f:/ai-projects/load-balancer/src/Http/Controllers/NodeManagementAPIController.php#L188) |
| **A03: Injection (CRLF / Request Splitting)** | Carriage return (`\r`) or newline (`\n`) injected into client headers to split HTTP requests, smuggle payloads, or poison cache queues (CWE-113). | [`HeaderSanitizer.php`](file:///f:/ai-projects/load-balancer/src/Support/HeaderSanitizer.php) cleanses every forwarded header, stripping all `\r`, `\n`, and `\0` bytes before upstream transmission. | [`HeaderSanitizer.php`](file:///f:/ai-projects/load-balancer/src/Support/HeaderSanitizer.php#L70-L83) |
| **A02 & A07: Replay & Nonce Attacks** | Attacker intercepts an HMAC-signed request on the local wire and replays it within the 300-second timestamp window. | Atomic nonce cache verification using `Cache::add('lb_seen_nonce_' . sha1($requestId), true, $tolerance)`. Duplicate nonces are instantly blocked with `401 Unauthorized`. | [`VerifyLoadBalancerSignature.php`](file:///f:/ai-projects/load-balancer/src/Http/Middleware/VerifyLoadBalancerSignature.php#L87-L95) |
| **A07: Identification & Auth Failures** | Automated brute-force / credential-stuffing attacks on the authentication endpoint (`/api/auth/login`). | Native rate limiting applied using `throttle:10,1` (max 10 login attempts per minute per IP). | [`routes/api.php`](file:///f:/ai-projects/load-balancer/routes/api.php#L36-L39) |
| **A05: Security Misconfiguration** | MIME-type confusion attacks and clickjacking through nested frames. | Automatically attaches `X-Content-Type-Options: nosniff` and `X-Frame-Options: SAMEORIGIN` to all client responses. | [`ProxyController.php`](file:///f:/ai-projects/load-balancer/src/Http/Controllers/ProxyController.php#L241-L245) |
| **A01: Broken Access Control** | Unauthorized callers accessing administrative endpoints or node controls. | All `/api/load-balancer/*` routes guarded by [`ProtectMetricsEndpoint`](file:///f:/ai-projects/load-balancer/src/Http/Middleware/ProtectMetricsEndpoint.php) using constant-time `hash_equals()`. Web Admin GUI locked by default. | [`ProtectMetricsEndpoint.php`](file:///f:/ai-projects/load-balancer/src/Http/Middleware/ProtectMetricsEndpoint.php#L30-L36) |
| **A01: Windows NTFS Path Traversal** | Exploit Windows Alternate Data Streams (`::$DATA`), 8.3 short names (`~1`), trailing dots, or directory traversal (`..`). | Built-in `guardPath()` decodes and inspects paths, blocking colons, backslashes, null bytes, 8.3 short-name notation, and hidden administrative files (`.env`, `web.config`). | [`ProxyController.php`](file:///f:/ai-projects/load-balancer/src/Http/Controllers/ProxyController.php#L280-L295) |

---

## 1. Cloud Instance Metadata SSRF Shield (A10)

Cloud providers (AWS, Google Cloud, Microsoft Azure, Oracle Cloud) expose a Link-Local HTTP server at `http://169.254.169.254` (or `http://metadata.google.internal`) that returns instance IAM credentials, service account tokens, and SSH keys.

### Threat Vector:
An attacker might attempt to register `http://169.254.169.254/latest/meta-data/iam/security-credentials/` as an upstream node to siphon AWS secrets through the proxy.

### Defense:
1. **Registration-Time Block**: When calling `POST /api/load-balancer/nodes`, the target URL host is inspected. Link-local subnets (`169.254.0.0/16`, `fd00:ec2::254`) and metadata hostnames are rejected with HTTP 422:
   ```json
   {
     "message": "The url host cannot target cloud metadata services or link-local addresses."
   }
   ```
2. **Runtime Dispatch Block**: In [`ProxyController::handle()`](file:///f:/ai-projects/load-balancer/src/Http/Controllers/ProxyController.php), before any socket is opened, the resolved upstream URL host is validated. Any attempt to query metadata is blocked with `403 Forbidden`.

---

## 2. CRLF Injection & Request Splitting Defense (A03 / CWE-113)

When proxies forward client-supplied HTTP headers without sanitization, an attacker can embed Carriage Return (`\r` / `%0D`) and Line Feed (`\n` / `%0A`) characters into header values:

```http
GET /items HTTP/1.1
Host: gateway.internal
X-Client-Language: en\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\nGET /admin HTTP/1.1
```

This causes HTTP Request Splitting, leading to cache poisoning and unauthorized backend command execution.

### Defense:
[`HeaderSanitizer::forUpstream()`](file:///f:/ai-projects/load-balancer/src/Support/HeaderSanitizer.php) sanitizes all forwarded header values:
```php
foreach ($headers as $name => $values) {
    $cleaned = [];
    foreach ((array) $values as $val) {
        $cleaned[] = is_string($val) ? str_replace(["\r", "\n", "\0"], '', $val) : $val;
    }
    $sanitized[$name] = $cleaned;
}
```
All header splitting tokens are completely eliminated.

---

## 3. Cryptographic Anti-Replay Nonce Engine (A02 & A07)

HMAC-SHA256 request signatures include a Unix timestamp. However, without nonce tracking, an attacker who intercepts a signed request on the local network can replay the exact same request within the 300-second expiration window.

### Defense:
1. Every request generated by `app03` includes a cryptographically unique `X-Request-Id` UUIDv4.
2. The canonical payload string includes the nonce:
   ```text
   Payload = "{METHOD}|{PATH}|{TIMESTAMP}|{REQUEST_ID}"
   Signature = hash_hmac('sha256', Payload, Secret)
   ```
3. [`VerifyLoadBalancerSignature`](file:///f:/ai-projects/load-balancer/src/Http/Middleware/VerifyLoadBalancerSignature.php) validates the HMAC first, then executes an atomic cache check:
   ```php
   $nonceKey = 'lb_seen_nonce_' . sha1($requestId);
   if (!\Illuminate\Support\Facades\Cache::add($nonceKey, true, $tolerance)) {
       return response()->json([
           'error' => 'Unauthorized',
           'message' => 'Load balancer signature replay detected (nonce already processed).',
       ], 401);
   }
   ```
4. Because `Cache::add()` is an atomic `SETNX` in Valkey/Redis, a replayed request will fail and receive `401 Unauthorized`.

---

## 4. Windows NTFS Traversal & ADS Defenses (A01)

Windows NTFS filesystems feature Alternate Data Streams (ADS) and 8.3 short filename aliasing:

1. **`::$DATA` Alternate Streams**: Traversal exploits like `/index.php::$DATA` attempt to bypass script execution and download raw PHP source code.
2. **8.3 Short Names**: IIS aliases directory names (e.g., `web~1.con` for `web.config`).
3. **Trailing Dots/Spaces**: NTFS automatically trims trailing spaces and periods (e.g., `web.config.` resolves to `web.config`).

### Defense:
[`ProxyController::guardPath()`](file:///f:/ai-projects/load-balancer/src/Http/Controllers/ProxyController.php) normalizes and raw-urldecodes twice, strictly blocking:
```php
if (
    str_contains($decoded, "\0")
    || str_contains($decoded, '\\')
    || str_contains($decoded, ':')                  // Windows ADS (::$DATA)
    || preg_match('#(^|/)\.\.?(/|$)#', $decoded) === 1 // Traversal (..)
    || preg_match('#[\. ](/|$)#', $decoded) === 1     // Trailing dot / space
    || preg_match('#~[0-9]#', $decoded) === 1       // 8.3 short names (~1)
) {
    return $this->error(400, 'Bad Request', 'Malformed request path.');
}
```
Any malformed path receives `HTTP 400 Bad Request` before ever reaching backend applications.
