<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class GoogleMapsController extends Controller
{
    public function resolve(Request $request): JsonResponse
    {
        $url = $request->query('url');
        if (! is_string($url) || $url === '' || strlen($url) > 2048) {
            return response()->json(['message' => 'Link Google Maps invalid.'], 422);
        }

        $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
        $host = preg_replace('/^www\./', '', $host);
        if (! in_array($host, ['maps.app.goo.gl', 'goo.gl'], true)) {
            return response()->json(['message' => 'Este permis doar un short-link Google Maps.'], 422);
        }

        $current = $url;
        try {
            for ($i = 0; $i < 6; $i++) {
                $response = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; Symbiot/1.0)',
                    'Accept' => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
                ])->timeout(15)->withOptions(['allow_redirects' => false])->get($current);

                if ($response->status() >= 300 && $response->status() < 400) {
                    $location = $response->header('Location');
                    if (! is_string($location) || $location === '') {
                        break;
                    }
                    $current = $this->absoluteUrl($current, $location);
                    continue;
                }

                if (! $response->successful()) {
                    return response()->json(['message' => 'Google Maps a returnat HTTP '.$response->status().'.'], 502);
                }

                
                $html = $response->body();
                foreach ([
                    '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/i',
                    '/<meta[^>]+property=["\']og:url["\'][^>]+content=["\']([^"\']+)["\']/i',
                ] as $pattern) {
                    if (preg_match($pattern, $html, $m)) {
                        $candidate = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
                        if (str_contains(strtolower($candidate), '/maps')) {
                            $current = $candidate;
                            break 2;
                        }
                    }
                }
                break;
            }
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Nu am putut rezolva linkul Google Maps.'], 502);
        }

        $finalHost = strtolower(parse_url($current, PHP_URL_HOST) ?: '');
        $finalHost = preg_replace('/^www\./', '', $finalHost);
        $finalPath = strtolower(parse_url($current, PHP_URL_PATH) ?: '');
        $isGoogleMaps = in_array($finalHost, ['google.com', 'maps.google.com'], true)
            || str_starts_with($finalHost, 'maps.google.')
            || (str_starts_with($finalHost, 'google.') && str_contains($finalPath, '/maps'));

        if (! $isGoogleMaps || ! str_contains($finalPath, '/maps')) {
            return response()->json(['message' => 'Linkul scurt nu a putut fi transformat într-un link Google Maps de traseu.'], 422);
        }

        return response()->json(['url' => $current]);
    }

    private function absoluteUrl(string $base, string $location): string
    {
        if (preg_match('/^https?:\/\//i', $location)) return $location;
        $parts = parse_url($base);
        $scheme = ($parts['scheme'] ?? 'https').'://';
        $host = $parts['host'] ?? 'maps.google.com';
        if (str_starts_with($location, '//')) return ($parts['scheme'] ?? 'https').':'.$location;
        if (str_starts_with($location, '/')) return $scheme.$host.$location;
        $path = $parts['path'] ?? '/';
        $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
        return $scheme.$host.($dir ? $dir.'/' : '/').$location;
    }
}
