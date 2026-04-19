# ISG Load Balancer

A highly resilient, database-driven standalone Load Balancer package for Laravel 10 and 11 featuring Proxy and Native Redirect architectural modes, advanced state cachers, Enterprise-level scaling analytics, and strict SSRF security frameworks.

## Installation

Require the package via composer:

```bash
composer require isg/load-balancer
```

Publish the package configuration files and database migrations:

```bash
php artisan vendor:publish --provider="Isg\LoadBalancer\LoadBalancerServiceProvider"
```

Run database migrations to generate up the internal rules & metric tables:

```bash
php artisan migrate
```

## Docker Simulated Staging

This repository mathematically proves scale constraints via isolated Docker sandboxes protecting your native OS paths! We include a custom `PHP 8.3 CLI` container seamlessly wrapping Valkey Cache engines and three backend `nginx` simulated websites.

The testing cluster is isolated cleanly inside `tests/sandbox`. To boot the load balancer's Linux sandbox:
```bash
cd tests/sandbox
docker compose up -d
```
To run the automated PHPUnit verification models validating your extensions seamlessly:
```bash
docker compose exec app php vendor/bin/phpunit
```

## Features & Configuration

### 1. Proxies vs. Redirects (Modes)

By default, the load balancer operates in **Redirect Mode**. The software instantly issues an `HTTP 302` response pointing the client exactly to the loaded server location ensuring zero payload bandwidth footprint on your proxy machine.

If you don't expose your backing APIs and need your balancer to pipe the payload invisibly through local networks, you can flip over to **Proxy Mode**:

```php
// config/load-balancer.php
'mode' => env('LOAD_BALANCER_MODE', 'proxy'),
```
Proxy mode safely handles all `X-Forwarded-*` headers, strips hop-by-hop blocks automatically, and intentionally turns off generic Guzzle decompressions to ensure `gzip` streams remain performant and don't break browsers!

### 2. High-Scale Analytics (Surviving Millions RPS)

Writing metric analytics to databases or queues per request is catastrophic at extreme scale (1,000,000 req/sec). 

To cleanly survive these waves, our asynchronous Metric Jobs trigger `dispatchAfterResponse()`, executing physically only after the user natively finishes unhooking their browser stream.

Additionally, you can activate Statistical Sampling. By tuning your `sample_rate`, the proxy utilizes lightweight mathematical probabilities (`mt_rand`) to log only specific fractions of your traffic. A value of `0.01` ensures exactly 1% of your traffic gets tracked, preventing queue IO bottlenecks whilst maintaining functionally perfect analytics.

```php
// config/load-balancer.php 
'logging' => [
    'enabled' => env('LOAD_BALANCER_LOGGING', true),
    'sample_rate' => env('LOAD_BALANCER_SAMPLE_RATE', 1.0), // Change to 0.01 for severe scales
],
```

### 3. IP Routing & Sticky Sessions

Route consistency is vital for maintaining cache warmth inside cluster applications. 
- **Sticky Sessions:** Setting `LOAD_BALANCER_STICKY_SESSIONS=true` internally switches the balancer to CRC32 map the incoming IP Address. 127.0.0.1 will constantly return the exact identical target upstream unless that upstream mathematically disables or dies!
- **`IpRule` Overrides:** Creating an `IpRule` array passing an IP logically binds that visitor to a specific `upstream_node_id`, bypassing standard strategies altogether for Admin or specialized Beta User handling!

### 4. Strict Security Limits & WAF

The `LoadBalancerManager` intercepts all Server-Side vulnerabilities implicitly:
1. Validates strict schema requirements, ensuring `file:///etc/passwd` injection rows are thrown cleanly with HTTP 500 status traps natively.
2. In Development or Local testing (`app()->environment('local')`), routing seamlessly translates local `http://` bindings.
3. Once the environment reads `production`, ANY target mappings lacking explicit TLS `https://` blocks hit our production safety trap.

**Native WAF Rate Limiting:**
Built directly onto the proxy, a throttle automatically maps incoming connections. By default, it limits malicious scripts to `500` requests per minute per IP. 

```php
// config/load-balancer.php
'waf' => [
    'enabled' => env('LOAD_BALANCER_WAF_ENABLED', true),
    'limit' => env('LOAD_BALANCER_WAF_LIMIT', 500),
],
```

**Custom WAF Injection:**
If your Host Laravel Application is already compiled with complex rate-limit arrays (e.g. infinite hits for admins overriding general traffic) inside your RouteServiceProvider `RateLimiter::for('api', ...)`:

Simply align the package perfectly without modifying routes or touching code by matching the array name:
`LOAD_BALANCER_WAF_LIMITER=api`

The Load Balancer organically hooks into your native Laravel definitions dropping the internal WAF rules automatically!

### 5. Active Health Daemons & Observability

We provide mechanical command daemons replacing passive checks with autonomous background logic:
- Schedule `php artisan loadbalancer:health-check` in your Laravel `Kernel` to execute `->everyMinute()`. It pings all upstreams dynamically, auto-switching database `is_active` boundaries before your users ever hit timeouts.
- Need a Dashboard? Access `http://your-app.com/api/load-balancer/metrics` organically to retrieve structured JSON containing active clusters, aggregated request sums, and live latencies!

---

## Included Strategies

You completely dictate routing logic mathematically via `LOAD_BALANCER_DEFAULT_STRATEGY`:

* `round_robin` - Distribute predictably over all interchangeably weighed active models universally.
* `random` - Distributes completely arbitrarily.
* `weighted_random` - Favors upstream nodes mathematically matching larger `weight` parameters inside your Database strings.
* `active_passive` - Prioritizes `is_primary=true` nodes exclusively, relying heavily on backups solely if master caches collapse.
* `least_redirects` - Used exclusively alongside Redirect Mode—routes traffic seamlessly to whichever server owns the smallest dynamically tallying 24-hr usage metric to constantly normalize traffic spread organically. 
* `adaptive_latency` - Realtime Smart Routing! Uses background statistics automatically sorting metrics to target the node possessing the absolute fastest Exponential Moving Average millisecond response!

## Creating Custom Strategies 

This component cleanly adheres to SOLID logic—you do not ever need to edit core package classes to invent new mapping targets! Simply add them to the array.

### 1. Build your Strategy

```php
namespace App\LoadBalancing;

use Isg\LoadBalancer\Contracts\LoadBalancingStrategy;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Isg\LoadBalancer\Models\UpstreamNode;

class GeographicalStrategy implements LoadBalancingStrategy
{
    public function selectNode(Collection $nodes, ?Request $request = null): ?UpstreamNode
    {
        // Custom logic to parse origins natively via geoips and pick models here
        return $nodes->first();
    }
}
```

### 2. Append the Registry Map

Register your logic inside the exported configurations associative list (`config/load-balancer.php`). Laravel's Service Container processes standard `make()` operations perfectly ensuring maximum compatibility!

```php
'strategies' => [
    'round_robin'    => \Isg\LoadBalancer\Strategies\RoundRobinStrategy::class,
    'random'         => \Isg\LoadBalancer\Strategies\RandomStrategy::class,
    
    // Add your own strategies perfectly natively here!
    'geo_routing'    => \App\LoadBalancing\GeographicalStrategy::class,
],
```

Set `LOAD_BALANCER_DEFAULT_STRATEGY="geo_routing"` internally and watch the system fly beautifully!
