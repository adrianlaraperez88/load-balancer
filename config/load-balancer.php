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
];
