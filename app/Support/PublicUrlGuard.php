<?php

namespace App\Support;

use Closure;
use RuntimeException;

/**
 * Rejects URLs that point at the server itself or its private network (SSRF),
 * for URLs that come from third-party data such as the GTFS feed catalog.
 */
class PublicUrlGuard
{
    /** @var Closure(string): list<string> */
    private readonly Closure $resolver;

    /** @param (Closure(string): list<string>)|null $resolver host => IP addresses; injectable for tests */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? $this->resolveWithDns(...);
    }

    public function assertPublic(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException('Only public http(s) URLs are allowed.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver)($host);
        if ($addresses === []) {
            throw new RuntimeException("The host {$host} could not be resolved.");
        }

        foreach ($addresses as $address) {
            $isPublic = filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;

            if (! $isPublic) {
                throw new RuntimeException("The host {$host} points to a non-public address.");
            }
        }
    }

    /** Guzzle `allow_redirects` options that re-check every redirect target. */
    public function redirectOptions(int $max = 5): array
    {
        return [
            'max' => $max,
            'protocols' => ['http', 'https'],
            'on_redirect' => function ($request, $response, $uri): void {
                $this->assertPublic((string) $uri);
            },
        ];
    }

    /** @return list<string> */
    private function resolveWithDns(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
