<?php

namespace App\Http\Controllers;

use App\Services\GoogleMapsUrlResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class GoogleMapsKmlController extends Controller
{
    public function __invoke(Request $request): Response|JsonResponse
    {
        if ($request->filled('url')) {
            return $this->fromGoogleMapsUrl($request);
        }

        $data = $request->validate([
            'geometry' => ['required', 'array'],
            'geometry.type' => ['required', 'string', 'in:LineString,MultiLineString'],
            'geometry.coordinates' => ['required', 'array', 'min:2', 'max:10000'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $name = trim((string) ($data['name'] ?? 'traseu-google-maps'));
        $name = $name !== '' ? $name : 'traseu-google-maps';

        $safeFilename = preg_replace('/[^A-Za-z0-9ăâîșțĂÂÎȘȚ _-]+/u', '-', $name) ?: 'traseu-google-maps';
        $safeFilename = trim(preg_replace('/\s+/u', '-', $safeFilename), '-');
        $safeFilename = $safeFilename !== '' ? $safeFilename : 'traseu-google-maps';

        if (! class_exists(\DOMDocument::class)) {
            return response()->json([
                'message' => 'Extensia PHP DOM este necesară pentru generarea KML.',
            ], 500);
        }

        $coordinates = $data['geometry']['coordinates'];
        $lines = $data['geometry']['type'] === 'MultiLineString' ? $coordinates : [$coordinates];

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $dom->preserveWhiteSpace = false;

        $kml = $dom->createElementNS('http://www.opengis.net/kml/2.2', 'kml');
        $dom->appendChild($kml);
        $document = $dom->createElement('Document');
        $kml->appendChild($document);

        $nameNode = $dom->createElement('name');
        $nameNode->appendChild($dom->createTextNode($name));
        $document->appendChild($nameNode);

        $validPlacemarks = 0;

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $points = [];
            foreach ($line as $point) {
                if (! is_array($point) || count($point) < 2) {
                    continue;
                }

                $lon = filter_var($point[0], FILTER_VALIDATE_FLOAT);
                $lat = filter_var($point[1], FILTER_VALIDATE_FLOAT);
                $alt = isset($point[2]) ? filter_var($point[2], FILTER_VALIDATE_FLOAT) : 0.0;

                if ($lon === false || $lat === false || $alt === false) {
                    continue;
                }
                if ($lon < -180 || $lon > 180 || $lat < -90 || $lat > 90) {
                    continue;
                }

                $points[] = sprintf('%.8F,%.8F,%.3F', (float) $lon, (float) $lat, (float) $alt);
            }

            if (count($points) < 2) {
                continue;
            }

            $placemark = $dom->createElement('Placemark');
            $placemarkName = $dom->createElement('name');
            $placemarkName->appendChild($dom->createTextNode($name));
            $placemark->appendChild($placemarkName);

            $style = $dom->createElement('Style');
            $lineStyle = $dom->createElement('LineStyle');
            $color = $dom->createElement('color', 'ff0055ff');
            $width = $dom->createElement('width', '5');
            $lineStyle->appendChild($color);
            $lineStyle->appendChild($width);
            $style->appendChild($lineStyle);
            $placemark->appendChild($style);

            $lineString = $dom->createElement('LineString');
            $tessellate = $dom->createElement('tessellate', '1');
            $coordinateNode = $dom->createElement('coordinates');
            $coordinateNode->appendChild($dom->createTextNode(implode(' ', $points)));
            $lineString->appendChild($tessellate);
            $lineString->appendChild($coordinateNode);
            $placemark->appendChild($lineString);

            $document->appendChild($placemark);
            $validPlacemarks++;
        }

        if ($validPlacemarks === 0) {
            return response()->json([
                'message' => 'Geometria traseului nu conține cel puțin două puncte valide.',
            ], 422);
        }

        $kml = $dom->saveXML();
        $check = new \DOMDocument;
        $check->preserveWhiteSpace = false;
        $previous = libxml_use_internal_errors(true);
        $valid = $check->loadXML($kml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $valid) {
            return response()->json([
                'message' => 'Nu s-a putut genera un document KML XML valid.',
            ], 500);
        }

        return response($kml, 200, [
            'Content-Type' => 'application/vnd.google-earth.kml+xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $safeFilename . '.kml"',
            'Content-Length' => strlen($kml),
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    private function fromGoogleMapsUrl(Request $request): Response|JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'name' => ['nullable', 'string', 'max:255'],
            'travel_mode' => ['nullable', 'string', 'in:driving,bicycling,walking,hiking'],
        ]);

        try {
            $url = $this->resolveShortLink($data['url']);
            $parsed = $this->parseGoogleMapsUrl($url);
            $locations = array_values(array_filter([
                $parsed['origin'],
                ...$parsed['waypoints'],
                $parsed['destination'],
            ], static fn ($value) => $value !== null && $value !== ''));

            if (count($locations) < 2) {
                throw new \RuntimeException('Linkul Google Maps trebuie să conțină cel puțin origine și destinație.');
            }

            $coordinates = [];
            foreach ($locations as $location) {
                $coordinates[] = $this->geocode($location);
            }

            $routed = $this->route($coordinates, $data['travel_mode'] ?? 'driving');
            $name = trim((string) ($data['name'] ?? 'traseu-google-maps')) ?: 'traseu-google-maps';

            return $this->kmlResponse($routed['geometry'], $name);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => $e->getMessage() ?: 'Nu am putut converti linkul Google Maps în KML.',
            ], 422);
        }
    }

    private function resolveShortLink(string $url): string
    {
        return app(GoogleMapsUrlResolver::class)->resolve($url);
    }

    /** @return array<string, mixed> */
    private function parseGoogleMapsUrl(string $url): array
    {
        $parsed = parse_url($url);
        $host = strtolower(preg_replace('/^www\./', '', $parsed['host'] ?? ''));
        $path = $parsed['path'] ?? '';
        $query = [];
        parse_str($parsed['query'] ?? '', $query);

        $isGoogleMaps = in_array($host, ['google.com', 'maps.google.com'], true)
            || str_starts_with($host, 'maps.google.')
            || (str_starts_with($host, 'google.') && str_contains(strtolower($path), '/maps'));
        if (! $isGoogleMaps || ! str_contains(strtolower($path), '/maps')) {
            throw new \RuntimeException('URL-ul introdus nu este un link Google Maps valid.');
        }

        $origin = isset($query['origin']) ? $this->normalizeLocation($query['origin']) : null;
        $destination = isset($query['destination']) ? $this->normalizeLocation($query['destination']) : null;
        $waypoints = isset($query['waypoints'])
            ? array_values(array_filter(array_map(fn ($v) => $this->normalizeLocation($v), explode('|', (string) $query['waypoints']))))
            : [];

        // Google Maps share links often put the route data in the path as
        // /maps/dir/data=!... instead of exposing origin/destination query
        // parameters. Never send that opaque `data=...` value to Geoapify as
        // a place name; first try to recover the embedded coordinates.
        $marker = stripos($path, '/maps/dir/');
        if ((! $origin || ! $destination) && $marker !== false) {
            $routePath = substr($path, $marker + strlen('/maps/dir/'));

            /*
             * IMPORTANT:
             * Google puts the map viewport after the destination:
             *
             * /maps/dir/ORIGIN/DESTINATION/@45.2765,25.2776,102405m/data=...
             *
             * `@LAT,LON,...` is NOT a waypoint. It is only the map viewport.
             * The previous parser treated the last path segment as the
             * destination, which made @45.2765,25.2776 become the destination.
             */
            $parts = [];
            foreach (explode('/', $routePath) as $rawPart) {
                $part = rawurldecode(trim($rawPart));

                if ($part === '') {
                    continue;
                }

                // Google's opaque payload is not a location.
                if (str_starts_with(strtolower($part), 'data=')) {
                    continue;
                }

                // @lat,lon[,zoom...] is the map viewport, never a route point.
                if (preg_match('/^@-?\d+(?:\.\d+)?,-?\d+(?:\.\d+)?(?:,|$)/', $part)) {
                    continue;
                }

                $location = $this->normalizeLocation($part);
                if ($location !== '') {
                    $parts[] = $location;
                }
            }

            if (count($parts) >= 2) {
                $origin ??= $parts[0];
                $destination ??= $parts[array_key_last($parts)];

                if (count($parts) > 2) {
                    $waypoints = array_merge(
                        $waypoints,
                        array_slice($parts, 1, -1)
                    );
                }
            }
        }

        if ($origin && ($c = $this->coordinateFromText($origin))) {
            $origin = $c;
        }
        if ($destination && ($c = $this->coordinateFromText($destination))) {
            $destination = $c;
        }

        // Never allow a Google map viewport to become a waypoint.
        $waypoints = array_values(array_filter(
            $waypoints,
            static fn ($point) => ! preg_match(
                '/^@-?\d+(?:\.\d+)?,-?\d+(?:\.\d+)?(?:,|$)/',
                (string) $point
            )
        ));

        // Google route links can contain two different kinds of coordinates:
        //   1) route/place coordinates encoded as !1dLON!2dLAT or !3dLAT!4dLON
        //   2) the map viewport center encoded as @LAT,LON,...
        //
        // The viewport center is NOT a route waypoint. Never use the generic
        // @LAT,LON coordinate to replace an explicit origin/destination.
        //
        // For the supplied maps.app.goo.gl example, the path contains:
        //   /maps/dir/45.6540041,25.7531091/Târgoviște/...
        // and the `data` section contains the exact destination coordinate.
        // Keep the explicit origin and use the embedded route coordinate for
        // the textual destination.
        $embeddedRouteCoordinates = $this->extractRouteCoordinates($url);

        if ($destination && ! is_array($destination) && count($embeddedRouteCoordinates) >= 1) {
            $destination = $embeddedRouteCoordinates[array_key_last($embeddedRouteCoordinates)];
        }

        // If the URL doesn't expose explicit endpoints, fall back to route
        // coordinates embedded in Google's data payload. Do not use the map
        // viewport (@lat,lon) as a route point.
        if (! $origin || ! $destination) {
            if (count($embeddedRouteCoordinates) >= 2) {
                $origin ??= $embeddedRouteCoordinates[0];
                $destination ??= $embeddedRouteCoordinates[array_key_last($embeddedRouteCoordinates)];

                if (count($embeddedRouteCoordinates) > 2) {
                    $waypoints = array_merge(
                        $waypoints,
                        array_slice($embeddedRouteCoordinates, 1, -1)
                    );
                }
            } else {
                throw new \RuntimeException('Linkul Google Maps nu conține suficiente coordonate pentru a identifica originea și destinația. Încearcă să copiezi linkul complet al traseului din Google Maps.');
            }
        }

        // If the origin/destination were explicitly provided as coordinates,
        // keep them. This prevents the Google map viewport from accidentally
        // replacing a real endpoint.

        return compact('origin', 'destination', 'waypoints');
    }

    /** @return array{latitude: float, longitude: float} */
    private function geocode(mixed $location): array
    {
        if (is_array($location)) {
            return $location;
        }
        $text = $this->normalizeLocation($location);
        if ($coordinates = $this->coordinateFromText($text)) {
            return $coordinates;
        }

        $response = Http::timeout(15)->get('https://api.geoapify.com/v1/geocode/search', [
            'text' => $text,
            'limit' => 1,
            'format' => 'json',
            'lang' => 'ro',
            'apiKey' => config('services.geoapify.key'),
        ]);
        if (! $response->successful()) {
            throw new \RuntimeException('Geoapify nu a putut localiza „' . $text . '”.');
        }
        $result = $response->json('results.0');
        if (! is_array($result) || ! isset($result['lat'], $result['lon'])) {
            throw new \RuntimeException('Geoapify nu a găsit locația „' . $text . '”.');
        }

        return ['latitude' => (float) $result['lat'], 'longitude' => (float) $result['lon']];
    }

    /**
     * @param  list<array{latitude: float, longitude: float}>  $coordinates
     * @return array{geometry: array<string, mixed>}
     */
    private function route(array $coordinates, string $travelMode): array
    {
        $mode = match ($travelMode) {
            'bicycling' => 'bicycle',
            'walking', 'hiking' => 'walk',
            default => 'drive',
        };
        $waypoints = implode('|', array_map(fn ($p) => $p['latitude'] . ',' . $p['longitude'], $coordinates));
        $response = Http::timeout(30)->get('https://api.geoapify.com/v1/routing', [
            'waypoints' => $waypoints,
            'mode' => $mode,
            // For a Google Maps share link with only origin + destination,
            // prefer the shortest drivable route. Geoapify's "balanced"
            // profile can select a substantially longer alternative, which
            // is especially visible on routes such as Brașov -> Târgoviște.
            'type' => 'short',
            'intermediate_waypoint_mode' => count($coordinates) > 2 ? 'pass_through' : null,
            'traffic' => 'free_flow',
            'format' => 'geojson',
            'apiKey' => config('services.geoapify.key'),
        ]);
        if (! $response->successful()) {
            throw new \RuntimeException('Geoapify nu a putut calcula traseul.');
        }
        $feature = $response->json('features.0');
        $geometry = $feature['geometry'] ?? null;
        if (! is_array($geometry) || empty($geometry['coordinates'])) {
            throw new \RuntimeException('Geoapify nu a returnat o geometrie validă pentru traseu.');
        }

        // Never silently accept a route whose final geometry is far away from
        // the requested destination. This catches malformed Google Maps URL
        // parsing before a wrong KML is generated.
        $routeEnd = $this->lastGeometryPoint($geometry);
        $requestedEnd = $coordinates[array_key_last($coordinates)] ?? null;
        if ($routeEnd !== null && is_array($requestedEnd)) {
            $distance = $this->haversineKm(
                $routeEnd['latitude'],
                $routeEnd['longitude'],
                (float) $requestedEnd['latitude'],
                (float) $requestedEnd['longitude']
            );

            if ($distance > 5.0) {
                throw new \RuntimeException(
                    sprintf(
                        'Geoapify a returnat un traseu care se termină la %.1f km de destinația din linkul Google Maps. Linkul nu a putut fi interpretat în siguranță.',
                        $distance
                    )
                );
            }
        }

        return ['geometry' => $geometry];
    }

    /**
     * @param  array<string, mixed>  $geometry
     * @return array{latitude: float, longitude: float}|null
     */
    private function lastGeometryPoint(array $geometry): ?array
    {
        $coordinates = $geometry['coordinates'] ?? [];

        if (($geometry['type'] ?? null) === 'LineString') {
            $point = $coordinates[array_key_last($coordinates)] ?? null;

            return $this->geoPoint($point);
        }

        if (($geometry['type'] ?? null) === 'MultiLineString') {
            for ($i = count($coordinates) - 1; $i >= 0; $i--) {
                $line = $coordinates[$i] ?? [];
                if ($line === []) {
                    continue;
                }
                $point = $line[array_key_last($line)] ?? null;
                $geoPoint = $this->geoPoint($point);
                if ($geoPoint !== null) {
                    return $geoPoint;
                }
            }
        }

        return null;
    }

    /** @return array{latitude: float, longitude: float}|null */
    private function geoPoint(mixed $point): ?array
    {
        if (! is_array($point) || count($point) < 2 || ! is_numeric($point[0]) || ! is_numeric($point[1])) {
            return null;
        }

        return [
            'longitude' => (float) $point[0],
            'latitude' => (float) $point[1],
        ];
    }

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthKm = 6371.0088;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earthKm * 2 * asin(min(1, sqrt($a)));
    }

    /** @param array<string, mixed> $geometry */
    private function kmlResponse(array $geometry, string $name): Response
    {
        $safeFilename = preg_replace('/[^A-Za-z0-9ăâîșțĂÂÎȘȚ _-]+/u', '-', $name) ?: 'traseu-google-maps';
        $safeFilename = trim(preg_replace('/\s+/u', '-', $safeFilename), '-') ?: 'traseu-google-maps';
        $lines = ($geometry['type'] ?? 'LineString') === 'MultiLineString' ? ($geometry['coordinates'] ?? []) : [$geometry['coordinates'] ?? []];
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $kml = $xml->createElementNS('http://www.opengis.net/kml/2.2', 'kml');
        $xml->appendChild($kml);
        $document = $xml->createElement('Document');
        $kml->appendChild($document);
        $document->appendChild($xml->createElement('name'))->appendChild($xml->createTextNode($name));
        $valid = 0;
        foreach ($lines as $line) {
            $points = [];
            foreach ($line as $point) {
                if (! is_array($point) || count($point) < 2) {
                    continue;
                }
                $lon = filter_var($point[0], FILTER_VALIDATE_FLOAT);
                $lat = filter_var($point[1], FILTER_VALIDATE_FLOAT);
                if ($lon === false || $lat === false || $lon < -180 || $lon > 180 || $lat < -90 || $lat > 90) {
                    continue;
                }
                $alt = isset($point[2]) && is_numeric($point[2]) ? (float) $point[2] : 0.0;
                $points[] = sprintf('%.8F,%.8F,%.3F', $lon, $lat, $alt);
            }
            if (count($points) < 2) {
                continue;
            }
            $placemark = $xml->createElement('Placemark');
            $placemark->appendChild($xml->createElement('name'))->appendChild($xml->createTextNode($name));
            $lineString = $xml->createElement('LineString');
            $lineString->appendChild($xml->createElement('tessellate', '1'));
            $lineString->appendChild($xml->createElement('coordinates'))->appendChild($xml->createTextNode(implode(' ', $points)));
            $placemark->appendChild($lineString);
            $document->appendChild($placemark);
            $valid++;
        }
        if ($valid === 0) {
            throw new \RuntimeException('Traseul nu conține suficiente puncte pentru KML.');
        }
        $content = $xml->saveXML();

        return response($content, 200, [
            'Content-Type' => 'application/vnd.google-earth.kml+xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $safeFilename . '.kml"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    private function normalizeLocation(mixed $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace('+', ' ', urldecode((string) $value))));
    }

    /** @return array{latitude: float, longitude: float}|null */
    private function coordinateFromText(string $value): ?array
    {
        $value = preg_replace('/^[^@]*@(?=-?\d)/', '@', $value);
        if (! preg_match('/^@?\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/', $value, $m)) {
            return null;
        }
        $lat = (float) $m[1];
        $lon = (float) $m[2];

        return ($lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180)
            ? ['latitude' => $lat, 'longitude' => $lon] : null;
    }

    /**
     * Extract only coordinates that Google embeds as part of a place/route
     * payload. Unlike extractCoordinates(), this intentionally ignores
     *
     * @LAT,LON viewport coordinates.
     */
    /** @return list<array{latitude: float, longitude: float}> */
    private function extractRouteCoordinates(string $url): array
    {
        $result = [];

        $add = function ($lat, $lon) use (&$result): void {
            $lat = (float) $lat;
            $lon = (float) $lon;

            if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                return;
            }

            foreach ($result as $point) {
                if (
                    abs($point['latitude'] - $lat) < 1e-7
                    && abs($point['longitude'] - $lon) < 1e-7
                ) {
                    return;
                }
            }

            $result[] = [
                'latitude' => $lat,
                'longitude' => $lon,
            ];
        };

        // Most Google Maps share links use longitude/latitude pairs:
        // !1d25.4558274!2d44.9118218
        if (preg_match_all(
            '/!1d(-?\\d+(?:\\.\\d+)?)!2d(-?\\d+(?:\\.\\d+)?)/',
            $url,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $add($match[2], $match[1]);
            }
        }

        // Some variants use latitude/longitude explicitly:
        // !3d44.9118218!4d25.4558274
        if (preg_match_all(
            '/!3d(-?\\d+(?:\\.\\d+)?)!4d(-?\\d+(?:\\.\\d+)?)/',
            $url,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $add($match[1], $match[2]);
            }
        }

        return $result;
    }
}
