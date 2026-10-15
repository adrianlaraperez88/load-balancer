# Backend Zero-Downtime Migration & Integration Runbook

A battle-tested operational guide for updating existing backend processing services (`app01`, `app02`, ..., `appN`) to accept traffic through the **`app03` Ingress Gateway** and enforce zero-trust HMAC signature verification **without breaking current mobile apps, SPAs, or third-party webhooks**.

---

## The Migration Challenge

In existing architectures, clients and microservices often connect directly to backend servers:

```
[Current State]
Client / Mobile App / Webhook ──(Direct Connection)──► Backend Nodes (app01, app02)
```

If we immediately enforce strict HMAC signing on backends, any direct connection that has not yet switched to the `app03` gateway will fail with `401 Unauthorized`, causing an outage.

To eliminate risk, we implement a **4-Phase Phased Rollout**:

```
[Phase 1] ──► Install Middleware in Permissive Mode (strict: false)
              (Both signed and unsigned requests accepted - Zero Impact)
                 │
[Phase 2] ──► Connect Gateway (app03) & Canary Verify
              (Route test traffic through gateway, verify 200 OK)
                 │
[Phase 3] ──► Switch Ingress DNS / Client URLs to app03
              (Live traffic now flows through app03, automatically signed)
                 │
[Phase 4] ──► Toggle Backend Lockdown (strict: true)
              (Perimeter sealed: direct bypasses blocked with 401)
```

---

## Phase 1: Deploy Backend Middleware in Permissive Mode

On each backend service (`app01`, `app02`, ..., `appN`):

### 1. Require Package & Publish Config
```bash
composer require adrianlaraperez88/load-balancer
```

### 2. Configure Permissive Mode in `.env`
Set `LOAD_BALANCER_SIGNATURE_STRICT=false`. This enables **Permissive Transition Mode**:

```env
# Zero-Trust HMAC Request Verification
LOAD_BALANCER_SIGNATURE_ENABLED=true
LOAD_BALANCER_SIGNATURE_STRICT=false    # <--- Permissive Transition Mode!
LOAD_BALANCER_SIGNATURE_SECRET="your-64-character-shared-secret-from-app03"
LOAD_BALANCER_SIGNATURE_TOLERANCE=300
```

### 3. Attach the `'gateway.verify'` Middleware
In your backend application routes (`routes/api.php` or `routes/web.php`):

```php
use Illuminate\Support\Facades\Route;

// Backend routes protected by gateway verification:
Route::middleware('gateway.verify')->group(function () {
    Route::apiResource('/orders', OrderController::class);
    Route::get('/users/me', [UserController::class, 'me']);
    Route::post('/webhooks/payment', [PaymentWebhookController::class, 'handle']);
});
```

### How Permissive Mode Works Under the Hood:
```
Incoming Request
      │
      ├── Does request contain X-LB-Signature?
      │      ├── YES ──► Verify HMAC-SHA256 signature against secret.
      │      │            ├── If valid ──► Allow request (200 OK).
      │      │            └── If invalid ──► Reject request (403 Forbidden).
      │      │
      │      └── NO  ──► Check strict setting:
      │                   ├── strict == false ──► Allow request! Log transition notice.
      │                   └── strict == true  ──► Reject request (401 Unauthorized).
```

**Result**: Existing direct consumers continue working with zero disruption.

---

## Phase 2: Register Backends on Gateway & Canary Test

On the `app03` Ingress Gateway:

### 1. Register the Backends
Using the Web Admin Control Center at `http://app03-ip:8080/admin/cluster` or via Artisan CLI:

```bash
php artisan loadbalancer:node add app01 http://10.0.0.11:8000 --weight=1
php artisan loadbalancer:node add app02 http://10.0.0.12:8000 --weight=1
php artisan loadbalancer:node add app03_compute http://10.0.0.13:8000 --weight=1
```

### 2. Run Canary Verification
Send a request through `app03`:

```bash
curl -i http://app03-ip:8080/api/users/me \
     -H "Authorization: Bearer <user-jwt-token>"
```

Verify that:
1. `app03` captures the request, injects `X-LB-Signature`, `X-LB-Timestamp`, and `X-Request-Id`.
2. The backend validates the signature and returns `200 OK`.
3. Check backend log (`storage/logs/laravel.log`) to verify the signature passed cleanly.

---

## Phase 3: Switch Ingress DNS & API Client URLs

Redirect external traffic to the `app03` gateway:

1. **DNS / VIP Cutover**:
   - Update your external DNS `A`/`CNAME` record or F5/Cloudflare routing rule for `api.yourdomain.com` to point to `app03`'s public IP.
2. **Mobile App / Web SPA Config**:
   - Ensure the API base URL is set to `https://app03.yourdomain.com`.
3. **Monitor Ingress Traffic**:
   - Inspect the Web Admin Control Center at `http://app03-ip:8080/admin/cluster`.
   - Observe request counts increasing across all healthy backend nodes.

---

## Phase 4: Enforce Strict Perimeter Lockdown

Once telemetry verifies that 100% of production traffic arrives through `app03`:

### 1. Update Backend `.env`
On all backend servers, flip `LOAD_BALANCER_SIGNATURE_STRICT` to `true`:

```env
LOAD_BALANCER_SIGNATURE_STRICT=true    # <--- Strict Enforcement Enabled!
```

### 2. Clear Config Cache
```bash
php artisan config:cache
```

### 3. Verify Direct Bypass Block
Attempt to hit the backend IP directly from an internal machine:

```bash
curl -i http://10.0.0.11:8000/api/users/me
```

*Expected Response:*
```json
HTTP/1.1 401 Unauthorized
Content-Type: application/json

{
  "error": "Unauthorized",
  "message": "Missing required load balancer signature headers."
}
```

**Perimeter Sealed**: Your backend application nodes now reject any traffic that did not originate from the authenticated `app03` gateway.

---

## Bonus: Zero-Downtime HMAC Key Rotation

When enterprise compliance policies require rotating the shared secret key:

1. On backends, add the new secret (`v2`) to `keys` and preserve the old secret (`v1`) in `grace_secrets`:
   ```php
   'signature' => [
       'enabled' => true,
       'active_key_id' => 'v2',
       'keys' => [
           'v1' => 'old-secret-key-32-chars',
           'v2' => 'new-secret-key-64-chars',
       ],
       'grace_secrets' => ['old-secret-key-32-chars'],
   ],
   ```
2. Update the Gateway `app03` to use the new key:
   ```env
   LOAD_BALANCER_SIGNATURE_SECRET="new-secret-key-64-chars"
   LOAD_BALANCER_ACTIVE_KEY_ID="v2"
   ```
3. In-flight requests signed with either `v1` or `v2` will succeed with **zero dropped requests**.
4. Once rotation is complete, remove `old-secret-key-32-chars` from `grace_secrets`.
