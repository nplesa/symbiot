<?php

namespace App\Services;

use RuntimeException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RouteImportService
{
    /** @return array<string, mixed> */
    public function import(string $path, string $extension): array
    {
        $extension = strtolower($extension);

        $parsed = match ($extension) {
            'gpx' => $this->fromXmlFile($path, 'gpx'),
            'kml' => $this->fromXmlFile($path, 'kml'),
            'kmz' => $this->fromKmz($path),
            'geojson', 'json' => $this->fromGeoJson(
                (string) file_get_contents($path)
            ),
            'csv' => $this->fromCsv((string) file_get_contents($path)),
            default => throw new RuntimeException('Format de traseu nesuportat.'),
        };

        return $this->addMissingElevations($parsed);
    }

    /** @return array<string, mixed> */
    private function fromXmlFile(string $path, string $format): array
    {
        $xml = @file_get_contents($path);

        if ($xml === false) {
            throw new RuntimeException('Fișierul nu poate fi citit.');
        }

        return $this->fromXml(
            $this->normalizeXmlInput($xml),
            $format
        );
    }

    /** @return array<string, mixed> */
    private function fromKmz(string $path): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new RuntimeException(
                'Extensia PHP ZipArchive este necesară pentru KMZ.'
            );
        }

        $zip = new \ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Arhiva KMZ nu poate fi deschisă.');
        }

        $maxFiles = 100;
        $maxUncompressed = 50 * 1024 * 1024;

        if ($zip->numFiles > $maxFiles) {
            $zip->close();

            throw new RuntimeException(
                'Arhiva KMZ conține prea multe fișiere.'
            );
        }

        $totalUncompressed = 0;
        $xml = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $size = (int) ($stat['size'] ?? 0);

            if (
                $size < 0
                || $size > $maxUncompressed
                || $totalUncompressed + $size > $maxUncompressed
            ) {
                $zip->close();

                throw new RuntimeException(
                    'Arhiva KMZ este prea mare după decomprimare.'
                );
            }

            $totalUncompressed += $size;

            $fileName = $zip->getNameIndex($i);

            if (
                is_string($fileName)
                && strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'kml'
            ) {
                $xml = $zip->getFromIndex($i);

                if (is_string($xml) && $xml !== '') {
                    break;
                }
            }
        }

        $zip->close();

        if (! is_string($xml) || $xml === '') {
            throw new RuntimeException(
                'Arhiva KMZ nu conține un fișier KML.'
            );
        }

        return $this->fromXml(
            $this->normalizeXmlInput($xml),
            'kml'
        );
    }

    private function normalizeXmlInput(string $xml): string
    {
        // UTF-32 trebuie verificat înainte de UTF-16.
        if (
            function_exists('mb_convert_encoding')
            && (
                str_starts_with($xml, "\xFF\xFE\x00\x00")
                || str_starts_with($xml, "\x00\x00\xFE\xFF")
            )
        ) {
            $converted = @mb_convert_encoding($xml, 'UTF-8', 'UTF-32');

            if (is_string($converted) && $converted !== '') {
                $xml = $converted;
            }
        } elseif (
            function_exists('mb_convert_encoding')
            && (
                str_starts_with($xml, "\xFF\xFE")
                || str_starts_with($xml, "\xFE\xFF")
            )
        ) {
            $converted = @mb_convert_encoding($xml, 'UTF-8', 'UTF-16');

            if (is_string($converted) && $converted !== '') {
                $xml = $converted;
            }
        }

        $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml) ?? $xml;
        $xml = str_replace("\0", '', $xml);
        $xml = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F]/', '', $xml) ?? $xml;

        $xml = ltrim($xml, "\xEF\xBB\xBF\x20\x09\x0A\x0D");

        $xmlStart = stripos($xml, '<?xml');
        $kmlStart = stripos($xml, '<kml');
        $gpxStart = stripos($xml, '<gpx');

        $starts = array_values(array_filter(
            [$xmlStart, $kmlStart, $gpxStart],
            static fn ($value) => $value !== false
        ));

        if ($starts !== []) {
            $start = min($starts);

            if ($start > 0) {
                $xml = substr($xml, $start);
            }
        }

        return trim(str_replace("\xEF\xBB\xBF", '', $xml));
    }

    /** @return array<string, mixed> */
    private function fromXml(string $xml, string $format): array
    {
        if (! class_exists(\DOMDocument::class)) {
            throw new RuntimeException(
                'Extensia PHP DOM este necesară pentru GPX/KML/KMZ.'
            );
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $dom = new \DOMDocument();
            $dom->preserveWhiteSpace = false;

            if (! @$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
                $errors = libxml_get_errors();
                $detail = '';

                foreach ($errors as $error) {
                    $message = trim($error->message ?? '');

                    if ($message !== '') {
                        $detail = $message;

                        if ($error->line) {
                            $detail .= ' (linia ' . $error->line
                                . ', coloana ' . $error->column . ')';
                        }

                        break;
                    }
                }

                throw new RuntimeException(
                    'Fișierul XML al traseului este invalid'
                    . ($detail !== '' ? ': ' . $detail : '.')
                );
            }

            $xpath = new \DOMXPath($dom);

            $segments = $format === 'gpx'
                ? $this->extractGpxSegments($xpath)
                : $this->extractKmlSegments($xpath);

        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $this->normalizeSegments(
            $segments,
            strtoupper($format)
        );
    }

    /**
     * Extrage separat fiecare segment GPX.
     *
     * @return list<list<array{latitude: float, longitude: float, elevation: float|null}>>
     */
    private function extractGpxSegments(\DOMXPath $xpath): array
    {
        $segments = [];

        // Preferăm segmentele track-urilor.
        $trackSegments = $xpath->query('//*[local-name()="trkseg"]');

        if ($trackSegments && $trackSegments->length > 0) {
            foreach ($trackSegments as $segmentNode) {
                $points = [];

                foreach (
                    $xpath->query('./*[local-name()="trkpt"]', $segmentNode) ?: []
                    as $node
                ) {
                    $lat = $node->attributes?->getNamedItem('lat')?->nodeValue;
                    $lon = $node->attributes?->getNamedItem('lon')?->nodeValue;

                    if ($this->validCoordinate($lat, $lon)) {
                        $points[] = $this->point(
                            (float) $lon,
                            (float) $lat,
                            $this->childFloat($node, 'ele')
                        );
                    }
                }

                if (count($points) >= 2) {
                    $segments[] = $points;
                }
            }

            return $segments;
        }

        // Dacă nu există track-uri, folosim punctele route.
        $routeNodes = $xpath->query('//*[local-name()="rtept"]');

        if ($routeNodes && $routeNodes->length > 0) {
            $points = [];

            foreach ($routeNodes as $node) {
                $lat = $node->attributes?->getNamedItem('lat')?->nodeValue;
                $lon = $node->attributes?->getNamedItem('lon')?->nodeValue;

                if ($this->validCoordinate($lat, $lon)) {
                    $points[] = $this->point(
                        (float) $lon,
                        (float) $lat,
                        $this->childFloat($node, 'ele')
                    );
                }
            }

            if (count($points) >= 2) {
                $segments[] = $points;
            }

            return $segments;
        }

        // Compatibilitate cu GPX-uri care nu folosesc trkseg.
        $points = [];

        foreach ($xpath->query('//*[local-name()="trkpt"]') ?: [] as $node) {
            $lat = $node->attributes?->getNamedItem('lat')?->nodeValue;
            $lon = $node->attributes?->getNamedItem('lon')?->nodeValue;

            if ($this->validCoordinate($lat, $lon)) {
                $points[] = $this->point(
                    (float) $lon,
                    (float) $lat,
                    $this->childFloat($node, 'ele')
                );
            }
        }

        if (count($points) >= 2) {
            $segments[] = $points;
        }

        return $segments;
    }

    /**
     * Fiecare LineString KML devine un segment separat.
     *
     * @return list<list<array{latitude: float, longitude: float, elevation: float|null}>>
     */
    private function extractKmlSegments(\DOMXPath $xpath): array
    {
        $segments = [];

        $nodes = $xpath->query(
            '//*[local-name()="LineString"]/*[local-name()="coordinates"]'
        );

        foreach ($nodes ?: [] as $node) {
            $text = trim((string) $node->textContent);
            $points = [];

            foreach (preg_split('/\s+/', $text) ?: [] as $tuple) {
                if ($tuple === '') {
                    continue;
                }

                $parts = array_map('trim', explode(',', $tuple));

                if (
                    count($parts) < 2
                    || ! $this->validCoordinate($parts[1], $parts[0])
                ) {
                    continue;
                }

                // Google Maps KML uses a third coordinate of 0 as a placeholder,
                // not as measured elevation.
                $elevation = isset($parts[2]) && is_numeric($parts[2])
                    && (float) $parts[2] !== 0.0
                    ? (float) $parts[2]
                    : null;

                $points[] = $this->point(
                    (float) $parts[0],
                    (float) $parts[1],
                    $elevation
                );
            }

            if (count($points) >= 2) {
                $segments[] = $points;
            }
        }

        return $segments;
    }

    /** @return array<string, mixed> */
    private function fromGeoJson(string $json): array
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw new RuntimeException('GeoJSON invalid.');
        }

        $segments = [];
        $this->collectGeoJsonSegments($data, $segments);

        return $this->normalizeSegments($segments, 'GEOJSON');
    }

    /**
     * @param array<string, mixed> $value
     * @param list<list<array{latitude: float, longitude: float, elevation: float|null}>> $segments
     */
    private function collectGeoJsonSegments(
        array $value,
        array &$segments
    ): void {
        $type = $value['type'] ?? null;

        if ($type === 'FeatureCollection') {
            foreach (($value['features'] ?? []) as $feature) {
                if (is_array($feature)) {
                    $this->collectGeoJsonSegments($feature, $segments);
                }
            }

            return;
        }

        if ($type === 'Feature') {
            if (is_array($value['geometry'] ?? null)) {
                $this->collectGeoJsonSegments($value['geometry'], $segments);
            }

            return;
        }

        $coordinates = $value['coordinates'] ?? null;

        if ($type === 'LineString' && is_array($coordinates)) {
            $segment = $this->geoJsonLine($coordinates);

            if (count($segment) >= 2) {
                $segments[] = $segment;
            }

            return;
        }

        if ($type === 'MultiLineString' && is_array($coordinates)) {
            foreach ($coordinates as $line) {
                if (! is_array($line)) {
                    continue;
                }

                $segment = $this->geoJsonLine($line);

                if (count($segment) >= 2) {
                    $segments[] = $segment;
                }
            }

            return;
        }

        if ($type === 'GeometryCollection') {
            foreach (($value['geometries'] ?? []) as $geometry) {
                if (is_array($geometry)) {
                    $this->collectGeoJsonSegments($geometry, $segments);
                }
            }
        }
    }

    /**
     * @param list<array<mixed>> $coordinates
     * @return list<array{latitude: float, longitude: float, elevation: float|null}>
     */
    private function geoJsonLine(array $coordinates): array
    {
        $points = [];

        foreach ($coordinates as $coordinate) {
            if (
                ! is_array($coordinate)
                || count($coordinate) < 2
                || ! is_numeric($coordinate[0])
                || ! is_numeric($coordinate[1])
                || ! $this->validCoordinate($coordinate[1], $coordinate[0])
            ) {
                continue;
            }

            $elevation = isset($coordinate[2]) && is_numeric($coordinate[2])
                ? (float) $coordinate[2]
                : null;

            $points[] = $this->point(
                (float) $coordinate[0],
                (float) $coordinate[1],
                $elevation
            );
        }

        return $points;
    }

    /** @return array<string, mixed> */
    private function fromCsv(string $csv): array
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new RuntimeException('Nu s-a putut procesa CSV-ul.');
        }

        fwrite($stream, $csv);
        rewind($stream);

        $headers = fgetcsv($stream);

        if (! is_array($headers)) {
            fclose($stream);

            throw new RuntimeException('CSV gol sau invalid.');
        }

        $headers = array_map(
            fn ($value) => strtolower(trim((string) $value)),
            $headers
        );

        $latIndex = $this->firstIndex($headers, ['lat', 'latitude', 'y']);
        $lonIndex = $this->firstIndex($headers, ['lon', 'lng', 'longitude', 'x']);
        $eleIndex = $this->firstIndex($headers, ['ele', 'elevation', 'altitude', 'alt']);

        if ($latIndex === null || $lonIndex === null) {
            fclose($stream);

            throw new RuntimeException(
                'CSV-ul trebuie să conțină coloane latitude/longitude sau lat/lon.'
            );
        }

        $points = [];

        while (($row = fgetcsv($stream)) !== false) {
            $lat = $row[$latIndex] ?? null;
            $lon = $row[$lonIndex] ?? null;

            if (!$this->validCoordinate($lat, $lon)) {
                continue;
            }

            $elevation = $eleIndex !== null
                && isset($row[$eleIndex])
                && is_numeric($row[$eleIndex])
                    ? (float) $row[$eleIndex]
                    : null;

            $points[] = $this->point(
                (float) $lon,
                (float) $lat,
                $elevation
            );
        }

        fclose($stream);

        return $this->normalizeSegments([$points], 'CSV');
    }

    /**
     * Normalizează segmentele fără să le unească și fără să elimine puncte.
     *
     * @param list<list<array{latitude: float, longitude: float, elevation: float|null}>> $segments
     * @return array<string, mixed>
     */
    private function normalizeSegments(array $segments, string $format): array
    {
        $max = (int) config('tracking.planned_route_max_coordinates', 10000);
        $max = max(2, $max);

        $validSegments = [];
        $totalPoints = 0;

        foreach ($segments as $segment) {
            if (! is_array($segment) || count($segment) < 2) {
                continue;
            }

            $validSegments[] = array_values($segment);
            $totalPoints += count($segment);
        }

        if ($totalPoints < 2) {
            throw new RuntimeException(
                'Traseul trebuie să conțină cel puțin două puncte GPS valide.'
            );
        }

        if ($totalPoints > $max) {
            throw new RuntimeException(
                sprintf(
                    'Traseul conține %d puncte, iar limita configurată este %d. '
                    . 'Nu am eliminat puncte. Mărește tracking.planned_route_max_coordinates pentru a importa geometria completă.',
                    $totalPoints,
                    $max
                )
            );
        }

        $geometryCoordinates = [];
        $flatPoints = [];
        $distance = 0.0;
        $gain = 0.0;
        $loss = 0.0;
        $hasElevation = false;

        foreach ($validSegments as $segment) {
            $lineCoordinates = [];
            $previous = null;

            foreach ($segment as $point) {
                $coordinate = [
                    $point['longitude'],
                    $point['latitude'],
                ];

                if ($point['elevation'] !== null) {
                    $coordinate[] = $point['elevation'];
                    $hasElevation = true;
                }

                $lineCoordinates[] = $coordinate;
                $flatPoints[] = $point;

                if ($previous !== null) {
                    $distance += $this->haversine(
                        $previous['latitude'],
                        $previous['longitude'],
                        $point['latitude'],
                        $point['longitude']
                    );

                    if (
                        $previous['elevation'] !== null
                        && $point['elevation'] !== null
                    ) {
                        $delta = $point['elevation'] - $previous['elevation'];

                        if ($delta > 0) {
                            $gain += $delta;
                        } elseif ($delta < 0) {
                            $loss += abs($delta);
                        }
                    }
                }

                $previous = $point;
            }

            $geometryCoordinates[] = $lineCoordinates;
        }

        $geometry = count($geometryCoordinates) === 1
            ? [
                'type' => 'LineString',
                'coordinates' => $geometryCoordinates[0],
            ]
            : [
                'type' => 'MultiLineString',
                'coordinates' => $geometryCoordinates,
            ];

        return [
            'format' => $format,
            'geometry' => $geometry,
            'points' => $flatPoints,
            'distance' => $distance,
            'duration' => 0,
            'elevation_gain' => $hasElevation ? $gain : null,
            'elevation_loss' => $hasElevation ? $loss : null,
        ];
    }

    /** @param array<string, mixed> $parsed @return array<string, mixed> */
    private function addMissingElevations(array $parsed): array
    {
        if (! config('services.geoapify.key') || empty($parsed['points'])) {
            return $parsed;
        }

        $points = $parsed['points'];
        $missingIndexes = [];
        foreach ($points as $index => $point) {
            if ($point['elevation'] === null) {
                $missingIndexes[] = $index;
            }
        }

        if ($missingIndexes === []) {
            return $parsed;
        }

        try {
            foreach (array_chunk($missingIndexes, 1000) as $indexes) {
                $locations = array_map(
                    fn (int $index) => [$points[$index]['longitude'], $points[$index]['latitude']],
                    $indexes
                );
                $response = Http::acceptJson()
                    ->asJson()
                    ->timeout(20)
                    ->post('https://api.geoapify.com/v1/geodata/elevation?apiKey=' . urlencode(config('services.geoapify.key')), [
                        'format' => 'json',
                        'units' => 'metric',
                        'locations' => $locations,
                    ]);

                if (! $response->successful()) {
                    throw new RuntimeException('Geoapify Elevation API returned HTTP ' . $response->status());
                }

                $results = $response->json('results');
                if (! is_array($results) || count($results) !== count($indexes)) {
                    throw new RuntimeException('Geoapify Elevation API returned an invalid result list.');
                }

                foreach ($indexes as $offset => $index) {
                    $elevation = $results[$offset]['elevation'] ?? null;
                    if (is_numeric($elevation)) {
                        $points[$index]['elevation'] = (float) $elevation;
                    }
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('Geoapify elevation lookup failed for imported route.', [
                'message' => $exception->getMessage(),
            ]);

            return $parsed;
        }

        $index = 0;
        $gain = 0.0;
        $loss = 0.0;
        $coordinates = $parsed['geometry']['type'] === 'LineString'
            ? [$parsed['geometry']['coordinates']]
            : $parsed['geometry']['coordinates'];

        foreach ($coordinates as &$segmentCoordinates) {
            $previousElevation = null;
            foreach ($segmentCoordinates as &$coordinate) {
                $elevation = $points[$index]['elevation'] ?? null;
                $coordinate = [$coordinate[0], $coordinate[1]];
                if ($elevation !== null) {
                    $coordinate[] = $elevation;
                }
                if ($previousElevation !== null && $elevation !== null) {
                    $delta = $elevation - $previousElevation;
                    // Ignore tiny DEM fluctuations that are below terrain-data noise.
                    if ($delta > 2) {
                        $gain += $delta;
                    } elseif ($delta < -2) {
                        $loss += abs($delta);
                    }
                }
                $previousElevation = $elevation;
                $index++;
            }
            unset($coordinate);
        }
        unset($segmentCoordinates);

        $parsed['points'] = $points;
        $parsed['geometry']['coordinates'] = $parsed['geometry']['type'] === 'LineString'
            ? $coordinates[0]
            : $coordinates;
        $parsed['elevation_gain'] = $gain;
        $parsed['elevation_loss'] = $loss;

        return $parsed;
    }

    /**
     * @return array{latitude: float, longitude: float, elevation: float|null}
     */
    private function point(
        float $longitude,
        float $latitude,
        ?float $elevation = null
    ): array {
        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'elevation' => $elevation,
        ];
    }

    private function validCoordinate(mixed $lat, mixed $lon): bool
    {
        return is_numeric($lat)
            && is_numeric($lon)
            && (float) $lat >= -90
            && (float) $lat <= 90
            && (float) $lon >= -180
            && (float) $lon <= 180;
    }

    private function childFloat(\DOMNode $node, string $name): ?float
    {
        foreach ($node->childNodes as $child) {
            if (
                $child->nodeType === XML_ELEMENT_NODE
                && strtolower($child->localName ?: $child->nodeName) === strtolower($name)
                && is_numeric(trim($child->textContent))
            ) {
                return (float) trim($child->textContent);
            }
        }

        return null;
    }

    private function haversine(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2
    ): float {
        $earth = 6371008.8;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1))
            * cos(deg2rad($lat2))
            * sin($dLon / 2) ** 2;

        return 2 * $earth * asin(min(1, sqrt($a)));
    }

    /**
     * @param list<string> $headers
     * @param list<string> $names
     */
    private function firstIndex(array $headers, array $names): ?int
    {
        foreach ($names as $name) {
            $index = array_search($name, $headers, true);

            if ($index !== false) {
                return $index;
            }
        }

        return null;
    }
}
