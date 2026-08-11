<?php

namespace App\Services;

use RuntimeException;

class RouteImportService
{
    public function import(string $path, string $extension): array
    {
        $extension = strtolower($extension);

        return match ($extension) {
            'gpx' => $this->fromXmlFile($path, 'gpx'),
            'kml' => $this->fromXmlFile($path, 'kml'),
            'kmz' => $this->fromKmz($path),
            'geojson', 'json' => $this->fromGeoJson((string) file_get_contents($path)),
            'csv' => $this->fromCsv((string) file_get_contents($path)),
            default => throw new RuntimeException('Format de traseu nesuportat.'),
        };
    }

    private function fromXmlFile(string $path, string $format): array
    {
        $xml = @file_get_contents($path);
        if ($xml === false) {
            throw new RuntimeException('Fișierul nu poate fi citit.');
        }

        
        $xml = $this->normalizeXmlInput($xml);

        return $this->fromXml($xml, $format);
    }

    private function fromKmz(string $path): array
    {
        if (! class_exists('ZipArchive')) {
            throw new RuntimeException('Extensia PHP ZipArchive este necesară pentru KMZ.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Arhiva KMZ nu poate fi deschisă.');
        }

        $xml = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name) && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'kml') {
                $xml = $zip->getFromIndex($i);
                break;
            }
        }
        $zip->close();

        if (! is_string($xml) || $xml === '') {
            throw new RuntimeException('Arhiva KMZ nu conține un fișier KML.');
        }

        return $this->fromXml($xml, 'kml');
    }

    
    private function normalizeXmlInput(string $xml): string
    {
        
        $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml) ?? $xml;

        
        if (function_exists('mb_convert_encoding') && (str_starts_with($xml, "\xFF\xFE") || str_starts_with($xml, "\xFE\xFF"))) {
            $converted = @mb_convert_encoding($xml, 'UTF-8', 'UTF-16');
            if (is_string($converted) && $converted !== '') {
                $xml = $converted;
            }
        }

        
        if (function_exists('mb_convert_encoding') && (str_starts_with($xml, "\xFF\xFE\x00\x00") || str_starts_with($xml, "\x00\x00\xFE\xFF"))) {
            $converted = @mb_convert_encoding($xml, 'UTF-8', 'UTF-32');
            if (is_string($converted) && $converted !== '') {
                $xml = $converted;
            }
        }

        
        
        
        $xml = str_replace("\0", '', $xml);
        $xml = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F]/', '', $xml) ?? $xml;

        
        
        
        
        
        $xml = ltrim($xml, "\xEF\xBB\xBF\x20\x09\x0A\x0D");
        $xmlStart = stripos($xml, '<?xml');
        $kmlStart = stripos($xml, '<kml');
        $gpxStart = stripos($xml, '<gpx');
        $starts = array_values(array_filter([$xmlStart, $kmlStart, $gpxStart], static fn ($v) => $v !== false));
        if ($starts !== []) {
            $start = min($starts);
            if ($start > 0) {
                $xml = substr($xml, $start);
            }
        }

        
        $xml = str_replace("\xEF\xBB\xBF", '', $xml);

        return trim($xml);
    }

    private function fromXml(string $xml, string $format): array
    {
        if (! class_exists('DOMDocument')) {
            throw new RuntimeException('Extensia PHP DOM este necesară pentru GPX/KML/KMZ.');
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
                        $detail = $message . ($error->line ? ' (linia ' . $error->line . ', coloana ' . $error->column . ')' : '');
                        break;
                    }
                }
                throw new RuntimeException('Fișierul XML al traseului este invalid' . ($detail !== '' ? ': ' . $detail : '.'));
            }

            $points = [];
            $xpath = new \DOMXPath($dom);

            if ($format === 'gpx') {
                
                
                
                
                $trackNodes = $xpath->query('//*[local-name()="trkpt"]') ?: [];
                $routeNodes = $xpath->query('//*[local-name()="rtept"]') ?: [];
                $nodes = $trackNodes->length > 0 ? $trackNodes : $routeNodes;

                foreach ($nodes as $node) {
                    $lat = $node->attributes?->getNamedItem('lat')?->nodeValue;
                    $lon = $node->attributes?->getNamedItem('lon')?->nodeValue;
                    if ($this->validCoordinate($lat, $lon)) {
                        $points[] = $this->point((float) $lon, (float) $lat, $this->childFloat($node, 'ele'));
                    }
                }
            } else {
                foreach ($xpath->query('//*[local-name()="coordinates"]') ?: [] as $node) {
                    $text = trim((string) $node->textContent);
                    foreach (preg_split('/\s+/', $text) ?: [] as $tuple) {
                        $parts = array_map('trim', explode(',', $tuple));
                        if (count($parts) >= 2 && $this->validCoordinate($parts[1] ?? null, $parts[0] ?? null)) {
                            $points[] = $this->point((float) $parts[0], (float) $parts[1], isset($parts[2]) && is_numeric($parts[2]) ? (float) $parts[2] : null);
                        }
                    }
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $this->normalize($points, strtoupper($format));
    }

    private function fromGeoJson(string $json): array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw new RuntimeException('GeoJSON invalid.');
        }

        $points = [];
        $this->collectGeoJsonPoints($data, $points);

        return $this->normalize($points, 'GEOJSON');
    }

    private function collectGeoJsonPoints(array $value, array &$points): void
    {
        if (($value['type'] ?? null) === 'FeatureCollection') {
            foreach (($value['features'] ?? []) as $feature) {
                if (is_array($feature)) $this->collectGeoJsonPoints($feature, $points);
            }
            return;
        }

        if (($value['type'] ?? null) === 'Feature') {
            if (is_array($value['geometry'] ?? null)) $this->collectGeoJsonPoints($value['geometry'], $points);
            return;
        }

        $type = $value['type'] ?? null;
        $coordinates = $value['coordinates'] ?? null;
        if ($type === 'LineString' && is_array($coordinates)) {
            foreach ($coordinates as $point) {
                if (is_array($point) && count($point) >= 2 && is_numeric($point[0]) && is_numeric($point[1])) {
                    $points[] = $this->point((float) $point[0], (float) $point[1], isset($point[2]) && is_numeric($point[2]) ? (float) $point[2] : null);
                }
            }
        } elseif ($type === 'MultiLineString' && is_array($coordinates)) {
            foreach ($coordinates as $line) {
                if (is_array($line)) $this->collectGeoJsonPoints(['type' => 'LineString', 'coordinates' => $line], $points);
            }
        } elseif ($type === 'Point' && is_array($coordinates) && count($coordinates) >= 2) {
            $points[] = $this->point((float) $coordinates[0], (float) $coordinates[1], isset($coordinates[2]) && is_numeric($coordinates[2]) ? (float) $coordinates[2] : null);
        }
    }

    private function fromCsv(string $csv): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $headers = fgetcsv($stream);
        if (! is_array($headers)) throw new RuntimeException('CSV gol sau invalid.');
        $headers = array_map(fn ($v) => strtolower(trim((string) $v)), $headers);
        $latIndex = $this->firstIndex($headers, ['lat', 'latitude', 'y']);
        $lonIndex = $this->firstIndex($headers, ['lon', 'lng', 'longitude', 'x']);
        $eleIndex = $this->firstIndex($headers, ['ele', 'elevation', 'altitude', 'alt']);
        if ($latIndex === null || $lonIndex === null) throw new RuntimeException('CSV-ul trebuie să conțină coloane latitude/longitude sau lat/lon.');

        $points = [];
        while (($row = fgetcsv($stream)) !== false) {
            $lat = $row[$latIndex] ?? null;
            $lon = $row[$lonIndex] ?? null;
            if ($this->validCoordinate($lat, $lon)) {
                $ele = $eleIndex !== null && isset($row[$eleIndex]) && is_numeric($row[$eleIndex]) ? (float) $row[$eleIndex] : null;
                $points[] = $this->point((float) $lon, (float) $lat, $ele);
            }
        }
        fclose($stream);

        return $this->normalize($points, 'CSV');
    }

    private function normalize(array $points, string $format): array
    {
        if (count($points) < 2) {
            throw new RuntimeException('Traseul trebuie să conțină cel puțin două puncte GPS.');
        }

        $max = (int) config('tracking.planned_route_max_coordinates', 10000);
        if (count($points) > $max) {
            $originalLast = $points[array_key_last($points)];
            $step = (int) ceil(count($points) / $max);
            $points = array_values(array_filter($points, fn ($point, $i) => $i % $step === 0, ARRAY_FILTER_USE_BOTH));
            if ($points[array_key_last($points)] !== $originalLast) $points[] = $originalLast;
        }

        $distance = 0.0;
        $gain = 0.0;
        $loss = 0.0;
        $previous = null;
        foreach ($points as $point) {
            if ($previous) {
                $distance += $this->haversine($previous['latitude'], $previous['longitude'], $point['latitude'], $point['longitude']);
                if ($previous['elevation'] !== null && $point['elevation'] !== null) {
                    $delta = $point['elevation'] - $previous['elevation'];
                    if ($delta > 0) $gain += $delta;
                    if ($delta < 0) $loss += abs($delta);
                }
            }
            $previous = $point;
        }

        return [
            'format' => $format,
            'geometry' => ['type' => 'LineString', 'coordinates' => array_map(fn ($p) => array_values(array_filter([$p['longitude'], $p['latitude'], $p['elevation']], fn ($v) => $v !== null)), $points)],
            'points' => $points,
            'distance' => $distance,
            'duration' => 0,
            'elevation_gain' => $gain ?: null,
            'elevation_loss' => $loss ?: null,
        ];
    }

    private function point(float $longitude, float $latitude, ?float $elevation = null): array
    {
        return ['latitude' => $latitude, 'longitude' => $longitude, 'elevation' => $elevation];
    }

    private function validCoordinate($lat, $lon): bool
    {
        return is_numeric($lat) && is_numeric($lon) && (float) $lat >= -90 && (float) $lat <= 90 && (float) $lon >= -180 && (float) $lon <= 180;
    }

    private function childFloat(\DOMNode $node, string $name): ?float
    {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && strtolower($child->localName ?: $child->nodeName) === strtolower($name) && is_numeric(trim($child->textContent))) {
                return (float) trim($child->textContent);
            }
        }
        return null;
    }

    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earth = 6371008.8;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return 2 * $earth * asin(min(1, sqrt($a)));
    }

    private function firstIndex(array $headers, array $names): ?int
    {
        foreach ($names as $name) {
            $index = array_search($name, $headers, true);
            if ($index !== false) return $index;
        }
        return null;
    }
}
