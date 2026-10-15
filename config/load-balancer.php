<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | Define which database connection should be used for storing upstream
    | nodes, IP rules, and request metrics.
    |
    */
    'database_connection' => env('LOAD_BALANCER_DB_CONNECTION', config('database.default', 'mysql')),

    /*
    |--------------------------------------------------------------------------
    | Sticky Sessions (IP Hash)
    |--------------------------------------------------------------------------
    |
    | If enabled, the load balancer will route sequential requests from the
    | same IP address to the same backend server by hashing the IP address
    | and modulo operation against the count of available nodes.
    |
    */
    'sticky_sessions' => env('LOAD_BALANCER_STICKY_SESSIONS', false),

    /*
    |--------------------------------------------------------------------------
    | Default Strategy
    |--------------------------------------------------------------------------
    |
    | Define the default load balancing strategy used when sticky sessions
    | aren't enabled and no explicit IP rule overrides apply.
    | Valid options: 'round_robin', 'random', 'weighted_random', 'active_passive'
    |
    */
    'default_strategy' => env('LOAD_BALANCER_DEFAULT_STRATEGY', 'round_robin'),

    /*
    |--------------------------------------------------------------------------
    | Package Mode (Proxy vs Redirect)
    |--------------------------------------------------------------------------
    |
    | If 'proxy', the application forwards the traffic internally.
    | If 'redirect', the application returns a 302 redirect directly to
    | the chosen upstream node's URL instead of proxying data.
    |
    */
    'mode' => env('LOAD_BALANCER_MODE', 'redirect'),

    /*
    |--------------------------------------------------------------------------
    | Upstream Timeouts (Seconds)
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('LOAD_BALANCER_TIMEOUT', 30),
    'connect_timeout' => (int) env('LOAD_BALANCER_CONNECT_TIMEOUT', 3),

    /*
    |--------------------------------------------------------------------------
    | Gateway Response Microcaching
    |--------------------------------------------------------------------------
    |
    | High-throughput microcaching for idempotent GET/HEAD requests.
    | Caches responses for short intervals (e.g. 2-5 seconds), delivering
    | sub-millisecond responses and 1,000+ RPS without querying backends.
    | Mutating requests and requests with Authorization headers bypass caching.
    |
    */
    'cache' => [
        'enabled' => (bool) env('LOAD_BALANCER_CACHE_ENABLED', false),
        'ttl_seconds' => (int) env('LOAD_BALANCER_CACHE_TTL', 5),
        'store' => env('LOAD_BALANCER_CACHE_STORE', null),
        'methods' => ['GET', 'HEAD'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Load Balancing Strategies
    |--------------------------------------------------------------------------
    |
    | This array maps the short string identifiers to their respective class
    | implementations. You can add your own custom strategies here as long as
    | they implement \Isg\LoadBalancer\Contracts\LoadBalancingStrategy.
    |
    */
    'strategies' => [
        'round_robin'     => \Isg\LoadBalancer\Strategies\RoundRobinStrategy::class,
        'random'          => \Isg\LoadBalancer\Strategies\RandomStrategy::class,
        'weighted_random' => \Isg\LoadBalancer\Strategies\WeightedRandomStrategy::class,
        'active_passive'  => \Isg\LoadBalancer\Strategies\ActivePassiveStrategy::class,
        'least_redirects' => \Isg\LoadBalancer\Strategies\LeastRedirectsStrategy::class,
        'adaptive_latency'=> \Isg\LoadBalancer\Strategies\AdaptiveLatencyStrategy::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance & Event Analytics
    |--------------------------------------------------------------------------
    |
    | Define logging parameters for extreme-scale enterprise architecture.
    | When processing millions of RPS, recording every individual request
    | via queue jobs creates IO starvation. Use 'sample_rate' to only
    | record statistical fractions of your traffic.
    | (1.0 = 100%, 0.1 = 10%, 0.01 = 1%)
    |
    */
    'logging' => [
        'enabled' => env('LOAD_BALANCER_LOGGING', true),
        'sample_rate' => env('LOAD_BALANCER_SAMPLE_RATE', 1.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Load Balancer WAF (Web Application Firewall)
    |--------------------------------------------------------------------------
    |
    | Define throttling rules preventing malicious traversals and DDOS scripts.
    |
    */
    'waf' => [
        'enabled' => env('LOAD_BALANCER_WAF_ENABLED', true),
        // If 'limiter' is set, it will automatically utilize Laravel's natively compiled RateLimiter::for('limit-name') logic
        'limiter' => env('LOAD_BALANCER_WAF_LIMITER', null), 
        'limit' => env('LOAD_BALANCER_WAF_LIMIT', 500),
        'window_minutes' => env('LOAD_BALANCER_WAF_WINDOW', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Upstream Request Timeout
    |--------------------------------------------------------------------------
    |
    | The duration in seconds that the proxy will wait for upstream backend
    | servers to complete processing before considering the request timed out.
    |
    */
    'timeout' => env('LOAD_BALANCER_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Enforce HTTPS for Upstream Nodes in Production
    |--------------------------------------------------------------------------
    |
    | In production, non-secure http:// upstream nodes are rejected by default
    | for SSRF safety. Set this to false if your front server (e.g. app03)
    | communicates with backend nodes over an internal, private VPC network.
    |
    */
    'enforce_https' => env('LOAD_BALANCER_FORCE_HTTPS', true),

    /*
    |--------------------------------------------------------------------------
    | Upstream Connect Timeout
    |--------------------------------------------------------------------------
    |
    | Seconds allowed to establish the TCP/TLS connection to a backend node.
    | Keep this short (1-3s) so a dead node fails fast and the request can be
    | safely re-routed, while 'timeout' above governs total processing time.
    |
    */
    'connect_timeout' => env('LOAD_BALANCER_CONNECT_TIMEOUT', 2),

    /*
    |--------------------------------------------------------------------------
    | Upstream TLS Verification
    |--------------------------------------------------------------------------
    |
    | true  = verify against the system / php.ini (curl.cainfo) CA bundle.
    | path  = absolute path to a CA bundle (e.g. your internal IIS CA:
    |         C:\certs\internal-ca.pem).
    | Never set this to false in production.
    |
    */
    'tls_verify' => env('LOAD_BALANCER_TLS_VERIFY', true),

    /*
    |--------------------------------------------------------------------------
    | Retry Policy
    |--------------------------------------------------------------------------
    |
    | 'max_attempts'        Total attempts across different nodes.
    | 'idempotent_methods'  Methods that may be retried after the request was
    |                       already sent (e.g. read timeout).
    | Non-idempotent methods (POST, PUT, PATCH, DELETE) are ONLY retried when
    | the connection could not be established, which guarantees the backend
    | never received them (prevents duplicate orders / payments / writes).
    |
    */
    'retry' => [
        'max_attempts' => env('LOAD_BALANCER_MAX_ATTEMPTS', 3),
        'idempotent_methods' => ['GET', 'HEAD', 'OPTIONS'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker
    |--------------------------------------------------------------------------
    |
    | A node is taken out of rotation only after 'failure_threshold' connection
    | failures inside 'window_seconds', and is retried after 'cooldown_seconds'.
    | Slow responses (read timeouts) never open the circuit. If every node is
    | open, the balancer fails open instead of returning 503 for everyone.
    |
    */
    'circuit_breaker' => [
        'failure_threshold' => env('LOAD_BALANCER_CB_THRESHOLD', 5),
        'window_seconds' => env('LOAD_BALANCER_CB_WINDOW', 30),
        'cooldown_seconds' => env('LOAD_BALANCER_CB_COOLDOWN', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Body Limit
    |--------------------------------------------------------------------------
    |
    | Maximum request body (bytes) app03 will buffer and forward. Requests
    | above this receive HTTP 413. Keep it aligned with IIS
    | <requestLimits maxAllowedContentLength> and php.ini post_max_size.
    |
    */
    'max_body_bytes' => env('LOAD_BALANCER_MAX_BODY_BYTES', 10 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Response Streaming
    |--------------------------------------------------------------------------
    |
    | When true, upstream responses are streamed in chunks instead of being
    | fully buffered in PHP memory (large downloads). On IIS, also set the
    | FastCGI handler responseBufferLimit="0" for true streaming.
    |
    */
    'stream_responses' => env('LOAD_BALANCER_STREAM_RESPONSES', false),

    /*
    |--------------------------------------------------------------------------
    | Blocked Paths
    |--------------------------------------------------------------------------
    |
    | Paths (Str::is patterns, case-insensitive, matched on the decoded path)
    | that app03 will never forward. Protects backend debug/admin tooling.
    |
    */
    'blocked_paths' => [
        '.env', '.env.*', '.git', '.git/*', '.svn/*',
        'web.config', 'composer.json', 'composer.lock', 'phpinfo.php',
        'vendor/*', 'storage/*',
        'horizon', 'horizon/*', 'telescope', 'telescope/*',
        '_ignition/*', '_debugbar/*', 'pulse', 'pulse/*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Header Sanitization
    |--------------------------------------------------------------------------
    |
    | 'strip_request'  Client-supplied headers removed before forwarding (they
    |                  could spoof identity/URLs on backends). Authoritative
    |                  X-Forwarded-* headers are re-added by the proxy.
    | 'strip_response' Backend headers removed before returning to the UI
    |                  (hides backend stack details, e.g. IIS / ASP.NET / PHP).
    | Hop-by-hop headers (RFC 9110) are always removed in both directions.
    |
    */
    'headers' => [
        'strip_request' => [
            'forwarded', 'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto',
            'x-forwarded-port', 'x-forwarded-prefix', 'x-forwarded-server',
            'x-real-ip', 'x-client-ip', 'true-client-ip', 'x-cluster-client-ip',
            'x-original-url', 'x-rewrite-url', 'x-original-host',
        ],
        'strip_response' => [
            'server', 'x-powered-by', 'x-aspnet-version', 'x-aspnetmvc-version',
            'x-sourcefiles', 'x-debug-token', 'x-debug-token-link', 'x-runtime',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics Endpoint
    |--------------------------------------------------------------------------
    |
    | GET /api/load-balancer/metrics exposes internal node URLs, so it is
    | DISABLED by default. When enabled, every configured check must pass:
    |   - token set       => "Authorization: Bearer <LOAD_BALANCER_METRICS_TOKEN>"
    |   - allowed_ips set => caller IP must be in the comma-separated list
    | With neither configured, all calls are denied.
    |
    */
    'metrics' => [
        'enabled' => env('LOAD_BALANCER_METRICS_ENABLED', false),
        'token' => env('LOAD_BALANCER_METRICS_TOKEN'),
        'allowed_ips' => array_filter(array_map('trim', explode(',', (string) env('LOAD_BALANCER_METRICS_IPS', '')))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Active Health Check Daemon Settings
    |--------------------------------------------------------------------------
    |
    | 'path'                 Path on backend nodes to ping (e.g. /up or /health)
    | 'timeout'              Per-node ping timeout in seconds
    | 'consecutive_failures' Number of consecutive failures before marking a node inactive
    |
    */
    'health_check' => [
        'path' => env('LOAD_BALANCER_HEALTH_PATH', '/'),
        'timeout' => env('LOAD_BALANCER_HEALTH_TIMEOUT', 3),
        'consecutive_failures' => env('LOAD_BALANCER_HEALTH_CONSECUTIVE_FAILURES', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backend Request Signature (HMAC)
    |--------------------------------------------------------------------------
    |
    | Signs requests forwarded by app03 using an HMAC-SHA256 signature so
    | backend servers can verify that requests originated from app03 and
    | reject direct access from unauthorized networks.
    |
    */
    'signature' => [
        'enabled' => env('LOAD_BALANCER_SIGNATURE_ENABLED', false),
        'strict' => env('LOAD_BALANCER_SIGNATURE_STRICT', true),
        'secret' => env('LOAD_BALANCER_SIGNATURE_SECRET', null),
        'header' => 'X-LB-Signature',
        'timestamp_header' => 'X-LB-Timestamp',
        'tolerance_seconds' => (int) env('LOAD_BALANCER_SIGNATURE_TOLERANCE', 300),
    ],
];
