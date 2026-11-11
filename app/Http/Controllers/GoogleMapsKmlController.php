<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use App\Services\GoogleMapsUrlResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GoogleMapsKmlController extends Controller
{
    private const KML_NAMESPACE = 'http://www.opengis.net/kml/2.2';

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

        $lines = $data['geometry']['type'] === 'MultiLineString'
            ? $data['geometry']['coordinates']
            : [$data['geometry']['coordinates']];

        return $this->generateKml(
            $lines,
            trim((string) ($data['name'] ?? 'traseu-google-maps')) ?: 'traseu-google-maps'
        );
    }

    private function fromGoogleMapsUrl(Request $request): Response|JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'name' => ['nullable', 'string', 'max:255'],
            'travel_mode' => ['nullable', 'string', 'in:driving,bicycling,walking,hiking'],
        ]);

        try {
            $url = app(GoogleMapsUrlResolver::class)->resolve($data['url']);

            $parsed = $this->parseGoogleMapsUrl($url);

            $locations = array_values(array_filter([
                $parsed['origin'],
                ...$parsed['waypoints'],
                $parsed['destination'],
            ], static fn ($value) => $value !== null && $value !== ''));

            $coordinates = [];

            foreach ($locations as $location) {
                if (! is_array($location)) {
                    $location = $this->coordinateFromText((string) $location);
                }

                if ($location === null) {
                    throw new UserFacingException(
                        'Nu am putut identifica coordonatele tuturor punctelor din link. '
                        . 'Nu vom recalcula traseul și nu vom folosi geocodare externă.'
                    );
                }

                $coordinates[] = [
                    (float) $location['longitude'],
                    (float) $location['latitude'],
                    0,
                ];
            }

            if (count($coordinates) < 2) {
                throw new UserFacingException(
                    'Linkul Google Maps nu conține cel puțin două puncte geografice.'
                );
            }

            $name = trim((string) ($data['name'] ?? 'traseu-google-maps'))
                ?: 'traseu-google-maps';

            /*
             * IMPORTANT:
             * Nu calculăm un traseu rutier nou.
             * KML-ul este construit exclusiv din coordonatele disponibile
             * în URL-ul Google Maps.
             *
             * Dacă URL-ul conține doar plecarea și destinația,
             * rezultatul va fi o linie între aceste două puncte.
             */
            return $this->generateKml([$coordinates], $name);

        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => $e instanceof UserFacingException
                    ? $e->getMessage()
                    : 'Nu am putut converti linkul Google Maps în KML.',
            ], 422);
        }
    }

    /**
     * @return array{
     *     origin: array{latitude: float, longitude: float}|string|null,
     *     destination: array{latitude: float, longitude: float}|string|null,
     *     waypoints: array<int, mixed>
     * }
     */
    private function parseGoogleMapsUrl(string $url): array
    {
        $parsed = parse_url($url);

        $path = $parsed['path'] ?? '';

        if (! app(GoogleMapsUrlResolver::class)->isGoogleMapsUrl($url) || ! str_contains(strtolower($path), '/maps')) {
            throw new UserFacingException('URL-ul introdus nu este un link Google Maps valid.');
        }

        $query = [];
        parse_str($parsed['query'] ?? '', $query);

        $origin = isset($query['origin'])
            ? $this->normalizeLocation($query['origin'])
            : null;

        $destination = isset($query['destination'])
            ? $this->normalizeLocation($query['destination'])
            : null;

        $waypoints = isset($query['waypoints'])
            ? array_values(array_filter(array_map(
                fn ($value) => $this->normalizeLocation($value),
                explode('|', (string) $query['waypoints'])
            )))
            : [];

        $marker = stripos($path, '/maps/dir/');

        if ($marker !== false) {
            $routePath = substr($path, $marker + strlen('/maps/dir/'));
            $parts = [];

            foreach (explode('/', $routePath) as $rawPart) {
                $part = rawurldecode(trim($rawPart));

                if ($part === '' || str_starts_with(strtolower($part), 'data=')) {
                    continue;
                }

                if (preg_match('/^@-?\d+(?:\.\d+)?,-?\d+(?:\.\d+)?(?:,|$)/', $part)) {
                    continue;
                }

                $part = $this->normalizeLocation($part);

                if ($part !== '') {
                    $parts[] = $part;
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

        if ($origin !== null) {
            $origin = $this->coordinateFromText($origin) ?? $origin;
        }

        if ($destination !== null) {
            $destination = $this->coordinateFromText($destination) ?? $destination;
        }

        $embedded = $this->extractRouteCoordinates($url);

        /*
         * Google Maps poate include coordonatele locației în payload.
         * Le folosim doar pentru identificarea punctelor,
         * nu le considerăm geometrie rutieră completă.
         */
        if (is_string($destination) && $embedded !== []) {
            $destination = $embedded[array_key_last($embedded)];
        }

        if ($origin === null && count($embedded) >= 2) {
            $origin = $embedded[0];
        }

        if ($destination === null && count($embedded) >= 2) {
            $destination = $embedded[array_key_last($embedded)];
        }

        if ($origin === null || $destination === null) {
            throw new UserFacingException(
                'Nu am putut identifica originea și destinația din linkul Google Maps.'
            );
        }

        return compact('origin', 'destination', 'waypoints');
    }

    /**
     * @return list<array{latitude: float, longitude: float}>
     */
    private function extractRouteCoordinates(string $url): array
    {
        $result = [];

        $add = function (float $lat, float $lon) use (&$result): void {
            if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                return;
            }

            foreach ($result as $point) {
                if (
                    abs($point['latitude'] - $lat) < 0.0000001
                    && abs($point['longitude'] - $lon) < 0.0000001
                ) {
                    return;
                }
            }

            $result[] = [
                'latitude' => $lat,
                'longitude' => $lon,
            ];
        };

        if (preg_match_all(
            '/!1d(-?\d+(?:\.\d+)?)!2d(-?\d+(?:\.\d+)?)/',
            $url,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $add((float) $match[2], (float) $match[1]);
            }
        }

        if (preg_match_all(
            '/!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/',
            $url,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $add((float) $match[1], (float) $match[2]);
            }
        }

        return $result;
    }

    /**
     * @param array<int, array<int, mixed>> $lines
     */
    private function generateKml(array $lines, string $name): Response|JsonResponse
    {
        if (! class_exists(\DOMDocument::class)) {
            return response()->json([
                'message' => 'Extensia PHP DOM este necesară pentru generarea KML.',
            ], 500);
        }

        $safeFilename = preg_replace(
            '/[^A-Za-z0-9ăâîșțĂÂÎȘȚ _-]+/u',
            '-',
            $name
        ) ?: 'traseu-google-maps';

        $safeFilename = trim(
            preg_replace('/\s+/u', '-', $safeFilename),
            '-'
        ) ?: 'traseu-google-maps';

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $kml = $dom->createElementNS(self::KML_NAMESPACE, 'kml');
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

                if (! is_numeric($point[0]) || ! is_numeric($point[1])) {
                    continue;
                }

                $lon = (float) $point[0];
                $lat = (float) $point[1];
                $alt = isset($point[2]) && is_numeric($point[2])
                    ? (float) $point[2]
                    : 0.0;

                if ($lon < -180 || $lon > 180 || $lat < -90 || $lat > 90) {
                    continue;
                }

                $points[] = sprintf('%.8F,%.8F,%.3F', $lon, $lat, $alt);
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
            $lineStyle->appendChild($dom->createElement('color', 'ff0055ff'));
            $lineStyle->appendChild($dom->createElement('width', '5'));
            $style->appendChild($lineStyle);
            $placemark->appendChild($style);

            $lineString = $dom->createElement('LineString');
            $lineString->appendChild($dom->createElement('tessellate', '1'));

            $coordinateNode = $dom->createElement('coordinates');
            $coordinateNode->appendChild(
                $dom->createTextNode(implode(' ', $points))
            );

            $lineString->appendChild($coordinateNode);
            $placemark->appendChild($lineString);
            $document->appendChild($placemark);

            $validPlacemarks++;
        }

        if ($validPlacemarks === 0) {
            return response()->json([
                'message' => 'Geometria nu conține cel puțin două puncte valide.',
            ], 422);
        }

        $content = $dom->saveXML();

        return response($content, 200, [
            'Content-Type' => 'application/vnd.google-earth.kml+xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $safeFilename . '.kml"',
            'Content-Length' => strlen($content),
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    private function normalizeLocation(mixed $value): string
    {
        return trim(preg_replace(
            '/\s+/u',
            ' ',
            str_replace('+', ' ', urldecode((string) $value))
        ));
    }

    /**
     * @return array{latitude: float, longitude: float}|null
     */
    private function coordinateFromText(string $value): ?array
    {
        if (! preg_match(
            '/^\s*@?\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/',
            $value,
            $matches
        )) {
            return null;
        }

        $lat = (float) $matches[1];
        $lon = (float) $matches[2];

        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return null;
        }

        return [
            'latitude' => $lat,
            'longitude' => $lon,
        ];
    }
}
