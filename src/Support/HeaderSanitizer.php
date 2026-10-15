<?php

namespace Isg\LoadBalancer\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Builds the header sets that cross the proxy boundary in both directions.
 *
 * Request side: drops hop-by-hop headers and any client-supplied identity /
 * URL headers that backends might trust, then adds authoritative
 * X-Forwarded-* values. Authorization (Bearer tokens) and Cookie headers are
 * forwarded untouched.
 *
 * Response side: drops hop-by-hop headers and backend fingerprinting headers
 * while preserving every value of multi-value headers such as Set-Cookie.
 */
class HeaderSanitizer
{
    /** RFC 9110 §7.6.1 hop-by-hop headers plus common non-standard ones. */
    public const HOP_BY_HOP = [
        'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization',
        'proxy-connection', 'te', 'trailer', 'transfer-encoding', 'upgrade',
    ];

    /**
     * @return array<string, array<int, string>>
     */
    public function forUpstream(Request $request, string $requestId): array
    {
        $headers = $request->headers->all();

        $drop = array_merge(
            self::HOP_BY_HOP,
            $this->connectionListed($headers['connection'] ?? []),
            array_map('strtolower', (array) config('load-balancer.headers.strip_request', [])),
            // Guzzle recomputes these from the forwarded body / target URL.
            ['host', 'content-length', 'expect', 'x-request-id'],
        );

        foreach ($drop as $name) {
            unset($headers[$name]);
        }

        $headers['x-forwarded-for'] = [$request->ip()];
        $headers['x-forwarded-host'] = [$request->getHost()];
        $headers['x-forwarded-proto'] = [$request->getScheme()];
        $headers['x-forwarded-port'] = [(string) $request->getPort()];
        $headers['x-request-id'] = [$requestId];

        if (config('load-balancer.signature.enabled', false) && ($secret = config('load-balancer.signature.secret'))) {
            $timestamp = (string) time();
            $method = strtoupper($request->getMethod());
            $path = $request->path();
            $payload = "{$method}|{$path}|{$timestamp}|{$requestId}";
            $signature = hash_hmac('sha256', $payload, $secret);

            $sigHeader = strtolower((string) config('load-balancer.signature.header', 'X-LB-Signature'));
            $timeHeader = strtolower((string) config('load-balancer.signature.timestamp_header', 'X-LB-Timestamp'));

            $headers[$sigHeader] = [$signature];
            $headers[$timeHeader] = [$timestamp];

            if ($activeKeyId = config('load-balancer.signature.active_key_id')) {
                $headers['x-lb-key-id'] = [(string) $activeKeyId];
            }
        }

        // OWASP A03 / CWE-113: Strip CR, LF, and null bytes to prevent HTTP Request Smuggling & Header Splitting
        $sanitized = [];
        foreach ($headers as $name => $values) {
            $cleaned = [];
            foreach ((array) $values as $val) {
                if (is_string($val)) {
                    $val = str_replace(["\r", "\n", "\0"], '', $val);
                }
                $cleaned[] = $val;
            }
            $sanitized[$name] = $cleaned;
        }

        return $sanitized;
    }

    /**
     * @param  array<string, array<int, string>>  $upstreamHeaders
     * @return array<string, array<int, string>>
     */
    public function forClient(array $upstreamHeaders): array
    {
        $normalized = [];
        foreach ($upstreamHeaders as $name => $values) {
            $normalized[strtolower($name)] = array_values((array) $values);
        }

        $drop = array_merge(
            self::HOP_BY_HOP,
            $this->connectionListed($normalized['connection'] ?? []),
            array_map('strtolower', (array) config('load-balancer.headers.strip_response', [])),
        );

        foreach ($drop as $name) {
            unset($normalized[$name]);
        }

        return $normalized;
    }

    /**
     * Reuse a well-formed inbound X-Request-Id for end-to-end tracing,
     * otherwise mint a new one (prevents log injection via the header).
     */
    public function requestId(Request $request): string
    {
        $incoming = (string) $request->headers->get('x-request-id', '');

        return preg_match('/^[A-Za-z0-9._-]{8,128}$/', $incoming) === 1
            ? $incoming
            : (string) Str::uuid();
    }

    /**
     * Headers named inside a Connection header are hop-by-hop too.
     *
     * @param  array<int, string>  $connectionValues
     * @return array<int, string>
     */
    protected function connectionListed(array $connectionValues): array
    {
        $names = [];
        foreach ($connectionValues as $value) {
            foreach (explode(',', (string) $value) as $token) {
                $token = strtolower(trim($token));
                if ($token !== '') {
                    $names[] = $token;
                }
            }
        }

        return $names;
    }
}
