<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Exceptions\UserFacingException;

class GoogleMapsUrlResolver
{
    private const SHORT_HOSTS = ['maps.app.goo.gl', 'goo.gl'];

    /** google.com, google.ro, google.co.uk, google.com.br, optionally prefixed with "maps.". */
    private const GOOGLE_HOST_PATTERN = '/^(maps\.)?google\.(com|[a-z]{2}|co\.[a-z]{2}|com\.[a-z]{2})$/';

    private const HOP_CONNECT_TIMEOUT_SECONDS = 5;

    private const HOP_TIMEOUT_SECONDS = 8;

    /** Upper bound for the whole redirect chain, so one request cannot hold a PHP worker for long. */
    private const TOTAL_TIMEOUT_SECONDS = 20;

    private const MAX_CONTENT_LENGTH_BYTES = 2 * 1024 * 1024;

    /** canonical / og:url live in <head>, so only the start of the page is searched. */
    private const MAX_HTML_SCAN_BYTES = 512 * 1024;

    public function resolve(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) {
            throw new UserFacingException('Link Google Maps invalid.');
        }

        $host = $this->normalizeHost(parse_url($url, PHP_URL_HOST));
        if (! in_array($host, self::SHORT_HOSTS, true)) {
            $this->assertGoogleMapsUrl($url);

            return $url;
        }

        $deadline = now()->addSeconds(self::TOTAL_TIMEOUT_SECONDS);
        $current = $url;
        for ($i = 0; $i < 6; $i++) {
            $this->assertAllowedHost($current);

            $remaining = (int) floor(now()->diffInSeconds($deadline, false));
            if ($remaining < 1) {
                throw new UserFacingException('Rezolvarea linkului Google Maps a durat prea mult. Încearcă din nou.');
            }

            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; Symbiot/1.0)',
                'Accept' => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
            ])->connectTimeout(min(self::HOP_CONNECT_TIMEOUT_SECONDS, $remaining))
                ->timeout(min(self::HOP_TIMEOUT_SECONDS, $remaining))
                ->withOptions(['allow_redirects' => false])
                ->get($current);

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
                throw new UserFacingException('Google Maps a returnat HTTP ' . $response->status() . '.');
            }

            if ((int) $response->header('Content-Length') > self::MAX_CONTENT_LENGTH_BYTES) {
                throw new UserFacingException('Răspunsul Google Maps este prea mare.');
            }

            $html = substr($response->body(), 0, self::MAX_HTML_SCAN_BYTES);
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
        if (! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return false;
        }

        $host = $this->normalizeHost(parse_url($url, PHP_URL_HOST));
        if (preg_match(self::GOOGLE_HOST_PATTERN, $host) !== 1) {
            return false;
        }

        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));

        return $host === 'google.com'
            || str_starts_with($host, 'maps.')
            || str_contains($path, '/maps');
    }

    private function assertGoogleMapsUrl(string $url): void
    {
        if (! $this->isGoogleMapsUrl($url) || ! str_contains(strtolower((string) parse_url($url, PHP_URL_PATH)), '/maps')) {
            throw new UserFacingException('Linkul nu este un link Google Maps valid.');
        }
    }

    private function assertAllowedHost(string $url): void
    {
        $host = $this->normalizeHost(parse_url($url, PHP_URL_HOST));
        if (! in_array($host, self::SHORT_HOSTS, true)) {
            throw new UserFacingException('Redirect extern blocat în timpul rezolvării linkului Google Maps.');
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
