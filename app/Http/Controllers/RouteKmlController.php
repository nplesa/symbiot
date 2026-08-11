<?php

namespace App\Http\Controllers;

use App\Models\Route as PlannedRoute;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class RouteKmlController extends Controller
{
    public function export(PlannedRoute $route): Response
    {
        abort_unless((int) $route->user_id === (int) auth()->id(), 404);

        $coordinates = $this->flattenCoordinates($route->geometry);
        abort_if(count($coordinates) < 2, 422, 'Traseul nu conține o geometrie exportabilă.');

        if (! class_exists(\DOMDocument::class)) {
            abort(500, 'Extensia PHP DOM este necesară pentru exportul KML.');
        }

        $name = trim((string) ($route->name ?: 'Traseu fără nume'));
        $description = 'Export KML din Symbiot' . ($route->source_url ? ' — sursă: ' . $route->source_url : '');

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $dom->preserveWhiteSpace = false;

        $kml = $dom->createElementNS('http://www.opengis.net/kml/2.2', 'kml');
        $dom->appendChild($kml);

        $document = $dom->createElement('Document');
        $kml->appendChild($document);

        $this->appendTextElement($dom, $document, 'name', $name);
        $this->appendTextElement($dom, $document, 'description', $description);

        $style = $dom->createElement('Style');
        $style->setAttribute('id', 'route');
        $lineStyle = $dom->createElement('LineStyle');
        $this->appendTextElement($dom, $lineStyle, 'color', 'ff0000ff');
        $this->appendTextElement($dom, $lineStyle, 'width', '5');
        $style->appendChild($lineStyle);
        $document->appendChild($style);

        $placemark = $dom->createElement('Placemark');
        $this->appendTextElement($dom, $placemark, 'name', $name);
        $this->appendTextElement($dom, $placemark, 'styleUrl', '#route');

        $lineString = $dom->createElement('LineString');
        $this->appendTextElement($dom, $lineString, 'tessellate', '1');

        $coordinateNode = $dom->createElement('coordinates');
        $coordinateNode->appendChild($dom->createTextNode(
            implode(' ', array_map(
                fn (array $point): string => $this->formatCoordinate($point),
                $coordinates
            ))
        ));
        $lineString->appendChild($coordinateNode);
        $placemark->appendChild($lineString);
        $document->appendChild($placemark);

        $this->appendPointPlacemark($dom, $document, 'Start', $coordinates[0]);
        $this->appendPointPlacemark($dom, $document, 'Sfârșit', $coordinates[array_key_last($coordinates)]);

        $kmlContent = $dom->saveXML();

        $check = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $valid = $check->loadXML($kmlContent, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $valid) {
            abort(500, 'Nu s-a putut genera un document KML XML valid.');
        }

        $filename = Str::slug($name) . '-' . $route->id . '.kml';

        return response($kmlContent, 200, [
            'Content-Type' => 'application/vnd.google-earth.kml+xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => strlen($kmlContent),
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function appendTextElement(\DOMDocument $dom, \DOMNode $parent, string $name, string $value): void
    {
        $node = $dom->createElement($name);
        $node->appendChild($dom->createTextNode($value));
        $parent->appendChild($node);
    }

    private function appendPointPlacemark(\DOMDocument $dom, \DOMNode $document, string $name, array $point): void
    {
        $placemark = $dom->createElement('Placemark');
        $this->appendTextElement($dom, $placemark, 'name', $name);

        $pointNode = $dom->createElement('Point');
        $coordinates = $dom->createElement('coordinates');
        $coordinates->appendChild($dom->createTextNode($this->formatCoordinate($point)));
        $pointNode->appendChild($coordinates);
        $placemark->appendChild($pointNode);

        $document->appendChild($placemark);
    }

    private function formatCoordinate(array $point): string
    {
        return sprintf(
            '%.8F,%.8F,%.3F',
            (float) $point[0],
            (float) $point[1],
            isset($point[2]) ? (float) $point[2] : 0.0
        );
    }

    private function flattenCoordinates(?array $geometry): array
    {
        if (! $geometry) return [];

        if (($geometry['type'] ?? null) === 'Feature') {
            return $this->flattenCoordinates($geometry['geometry'] ?? null);
        }

        if (($geometry['type'] ?? null) === 'FeatureCollection') {
            $out = [];
            foreach (($geometry['features'] ?? []) as $feature) {
                $out = array_merge($out, $this->flattenCoordinates($feature));
            }
            return $out;
        }

        if (($geometry['type'] ?? null) === 'LineString') {
            return $this->cleanLine($geometry['coordinates'] ?? []);
        }

        if (($geometry['type'] ?? null) === 'MultiLineString') {
            $out = [];
            foreach (($geometry['coordinates'] ?? []) as $line) {
                $out = array_merge($out, $this->cleanLine($line));
            }
            return $out;
        }

        return [];
    }

    private function cleanLine(array $line): array
    {
        $out = [];

        foreach ($line as $point) {
            if (!is_array($point) || count($point) < 2 || !is_numeric($point[0]) || !is_numeric($point[1])) {
                continue;
            }

            $lon = (float) $point[0];
            $lat = (float) $point[1];

            if ($lon < -180 || $lon > 180 || $lat < -90 || $lat > 90) {
                continue;
            }

            $out[] = [
                $lon,
                $lat,
                isset($point[2]) && is_numeric($point[2]) ? (float) $point[2] : 0.0,
            ];
        }

        return $out;
    }
}
