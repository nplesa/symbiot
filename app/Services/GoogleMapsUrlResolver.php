<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleMapsUrlResolver
{
    private const SHORT_HOSTS = ['maps.app.goo.gl', 'goo.gl'];

    public function resolve(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) {
            throw new RuntimeException('Link Google Maps invalid.');
        }

        $host = $this->normalizeHost(parse_url($url, PHP_URL_HOST));
        if (! in_array($host, self::SHORT_HOSTS, true)) {
            $this->assertGoogleMapsUrl($url);

            return $url;
        }

        $current = $url;
        for ($i = 0; $i < 6; $i++) {
            $this->assertAllowedHost($current);

            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; Symbiot/1.0)',
                'Accept' => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
            ])->timeout(15)->withOptions(['allow_redirects' => false])->get($current);

            if ($response->status() >= 300 && $response->status() < 400) {
                $location = $response->header('Location');
                if (! is_string($location) || $location === '') {
                    break;
                }

                $next = $this->absoluteUrl($current, $location);
                if ($this->isGoogleMapsUrl($next)) {
                    $current = $next;
                    break;
                }

                $this->assertAllowedHost($next);
                $current = $next;

                continue;
            }

            if (! $response->successful()) {
                throw new RuntimeException('Google Maps a returnat HTTP ' . $response->status() . '.');
            }

            $html = $response->body();
            foreach ([
                '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/i',
                '/<meta[^>]+property=["\']og:url["\'][^>]+content=["\']([^"\']+)["\']/i',
            ] as $pattern) {
                if (preg_match($pattern, $html, $m)) {
                    $candidate = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
                    if ($this->isGoogleMapsUrl($candidate)) {
                        $current = $candidate;
                        break 2;
                    }
                }
            }
            break;
        }

        $this->assertGoogleMapsUrl($current);

        return $current;
    }

    public function isGoogleMapsUrl(string $url): bool
    {
        $host = $this->normalizeHost(parse_url($url, PHP_URL_HOST));
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));

        return in_array($host, ['google.com', 'maps.google.com'], true)
            || str_starts_with($host, 'maps.google.')
            || (str_starts_with($host, 'google.') && str_contains($path, '/maps'));
    }

    private function assertGoogleMapsUrl(string $url): void
    {
        if (! $this->isGoogleMapsUrl($url) || ! str_contains(strtolower((string) parse_url($url, PHP_URL_PATH)), '/maps')) {
            throw new RuntimeException('Linkul nu este un link Google Maps valid.');
        }
    }

    private function assertAllowedHost(string $url): void
    {
        $host = $this->normalizeHost(parse_url($url, PHP_URL_HOST));
        if (! in_array($host, self::SHORT_HOSTS, true)) {
            throw new RuntimeException('Redirect extern blocat în timpul rezolvării linkului Google Maps.');
        }
    }

    private function normalizeHost(?string $host): string
    {
        return strtolower(preg_replace('/^www\./', '', (string) $host));
    }

    private function absoluteUrl(string $base, string $location): string
    {
        if (preg_match('/^https?:\/\//i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        $scheme = ($parts['scheme'] ?? 'https') . '://';
        $host = $parts['host'] ?? '';
        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $scheme . $host . $location;
        }
        $path = $parts['path'] ?? '/';
        $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');

        return $scheme . $host . ($dir ? $dir . '/' : '/') . $location;
    }
}
