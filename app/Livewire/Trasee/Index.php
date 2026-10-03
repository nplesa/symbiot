<?php

namespace App\Livewire\Trasee;

use App\Models\Route as PlannedRoute;
use App\Services\RouteImportService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

class Index extends Component
{
    use WithFileUploads;

    public mixed $file = null;

    public ?string $name = null;

    public string $googleMapsUrl = '';

    public ?int $selectedRouteId = null;

    public function mount(): void
    {
        $this->selectedRouteId = null;
    }

    public function render(): View
    {
        $routes = $this->ownedRoutes();

        $selectedRoute = $this->selectedRouteId
            ? $routes->firstWhere('id', $this->selectedRouteId)
            : null;

        if ($this->selectedRouteId !== null && $selectedRoute === null) {
            $this->selectedRouteId = null;
        }

        return view('livewire.trasee.index', compact('routes', 'selectedRoute'));
    }

    public function selectRoute(int $routeId): void
    {
        $route = $this->ownedRoute($routeId);

        $this->selectedRouteId = $route->id;

        $this->dispatch('route-selected', route: $route->toArray());
    }

    public function deleteRoute(int $routeId): void
    {
        $route = $this->ownedRoute($routeId);

        DB::transaction(function () use ($route): void {

            if (Schema::hasTable('route_points')) {
                DB::table('route_points')->where('route_id', $route->id)->delete();
            }

            $route->delete();
        });

        if ($this->selectedRouteId === $route->id) {
            $this->selectedRouteId = null;
        }

    }

    /** @param array<string, mixed> $payload */
    public function importGoogleMapsRouteFromClient(array $payload): int
    {
        $data = validator($payload, [
            'geometry' => ['required', 'array'],
            'geometry.type' => ['required', 'string', 'in:LineString,MultiLineString'],
            'geometry.coordinates' => ['required', 'array', 'min:2', 'max:10000'],
            'name' => ['nullable', 'string', 'max:255'],
            'source_url' => ['nullable', 'string', 'max:2048'],
            'travel_mode' => ['nullable', 'string', 'max:30'],
            'duration' => ['nullable', 'integer', 'min:0'],
            'distance' => ['nullable', 'numeric', 'min:0'],
        ])->validate();

        $geometry = $data['geometry'];
        $coordinates = $geometry['coordinates'];
        $pointCount = $this->validateClientGeometry($geometry);
        $distance = $this->calculateGeometryDistanceKm($geometry);
        $duration = (int) ($data['duration'] ?? 0);

        $route = DB::transaction(function () use ($data, $geometry, $distance, $duration): PlannedRoute {
            $route = new PlannedRoute;
            $route->user_id = auth()->id();
            $route->name = $data['name'] ?? null;
            $route->format = 'geojson';
            $route->geometry = $geometry;
            $route->distance = $distance;
            $route->duration = $duration;
            $route->source = 'google_maps_geoapify';
            $route->source_url = $data['source_url'] ?? null;
            $route->save();

            $this->insertRoutePointsFromGeometry($route, $geometry);

            return $route;
        });

        $route = $route->fresh();
        $this->selectedRouteId = $route->id;
        $this->googleMapsUrl = '';
        $this->dispatch('route-imported', route: $route->toArray(), source: 'google');

        return $route->id;
    }

    public function startRoute(): void
    {
        if (! $this->selectedRouteId) {
            $this->addError('selectedRouteId', 'Selectează mai întâi un traseu.');

            return;
        }

        $route = $this->ownedRoute($this->selectedRouteId);
        $this->redirectRoute('app.navigation.show', ['route' => $route->id]);
    }

    public function importRoute(?string $importToken = null): void
    {
        // Un singur request poate procesa un token de import. Protejează baza de date
        // de submit-uri/upload-uri duplicate (inclusiv request-uri concurente).
        $importToken = $importToken ?: (string) Str::uuid();
        $lockKey = 'trasee:file-import:lock:' . auth()->id() . ':' . $importToken;
        $doneKey = 'trasee:file-import:done:' . auth()->id() . ':' . $importToken;

        if (Cache::has($doneKey)) {
            return;
        }

        $lock = Cache::lock($lockKey, 60);
        if (! $lock->get()) {
            return;
        }

        try {
            $this->validate([
                'file' => ['required', 'file', 'max:10240'],
                'name' => ['required', 'string', 'max:255'],
            ]);

            $extension = strtolower($this->file->getClientOriginalExtension());
            if (! in_array($extension, ['gpx', 'kml', 'kmz', 'geojson', 'json', 'csv'], true)) {
                $this->addError('file', 'Format nesuportat. Folosește GPX, KML, KMZ, GeoJSON sau CSV.');

                return;
            }

            try {
                $parsed = app(RouteImportService::class)->import($this->file->getRealPath(), $extension);

                $route = DB::transaction(function () use ($parsed): PlannedRoute {
                    $route = new PlannedRoute;
                    $route->user_id = auth()->id();
                    $route->name = $this->name ?: pathinfo($this->file->getClientOriginalName(), PATHINFO_FILENAME);
                    $route->format = strtolower($parsed['format']);
                    $route->geometry = $parsed['geometry'];
                    $route->distance = $parsed['distance'];
                    $route->duration = $parsed['duration'];
                    $route->elevation_gain = $parsed['elevation_gain'];
                    $route->elevation_loss = $parsed['elevation_loss'];
                    $route->source = 'file_import';
                    $route->source_url = null;
                    $route->save();

                    if (Schema::hasTable('route_points')) {
                        $rows = [];
                        foreach ($parsed['points'] as $sequence => $point) {
                            $rows[] = [
                                'route_id' => $route->id,
                                'sequence' => $sequence,
                                'longitude' => (float) $point['longitude'],
                                'latitude' => (float) $point['latitude'],
                                'elevation' => isset($point['elevation']) ? (float) $point['elevation'] : null,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        }

                        foreach (array_chunk($rows, 1000) as $chunk) {
                            if ($chunk !== []) {
                                DB::table('route_points')->insert($chunk);
                            }
                        }
                    }

                    return $route;
                });

                $route = $route->fresh();
                // Importul prin formular adaugă traseul în listă, dar NU îl selectează.
                // Preview-ul trebuie afișat doar după ce utilizatorul apasă pe traseul din listă.
                $this->selectedRouteId = null;
                $this->file = null;
                $this->name = null;
                Cache::put($doneKey, $route->id, now()->addMinutes(10));
                $this->dispatch('route-imported', route: $route->toArray(), source: 'file');
            } catch (\Throwable $e) {
                Log::warning('Route file import failed.', [
                    'user_id' => auth()->id(),
                    'extension' => $extension,
                    'error' => $e->getMessage(),
                ]);

                $message = $e->getMessage() ?: 'Fișierul de traseu nu a putut fi importat.';
                $this->addError('file', $message);
                $this->dispatch('route-import-failed', message: $message);
            }
        } finally {
            $lock->release();
        }
    }

    /** @return Collection<int, PlannedRoute> */
    private function ownedRoutes(): Collection
    {
        return PlannedRoute::query()
            ->where('user_id', auth()->id())
            ->withCount('points')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /** @param array<string, mixed> $payload */
    public function downloadGoogleMapsKml(array $payload): Response
    {
        $data = validator($payload, [
            'geometry' => ['required', 'array'],
            'geometry.type' => ['required', 'string', 'in:LineString,MultiLineString'],
            'geometry.coordinates' => ['required', 'array', 'min:2', 'max:10000'],
            'name' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $name = trim((string) ($data['name'] ?? 'traseu-google-maps'));
        $name = $name !== '' ? $name : 'traseu-google-maps';
        $safeFilename = preg_replace('/[^A-Za-z0-9ăâîșțĂÂÎȘȚ _-]+/u', '-', $name);
        $safeFilename = trim(preg_replace('/\s+/u', '-', $safeFilename), '-');
        $safeFilename = $safeFilename !== '' ? $safeFilename : 'traseu-google-maps';

        $geometry = $data['geometry'];
        $coordinates = $geometry['coordinates'];

        $lines = $geometry['type'] === 'MultiLineString'
            ? $coordinates
            : [$coordinates];

        $xmlEscape = static fn (string $value): string => htmlspecialchars(
            $value,
            ENT_XML1 | ENT_QUOTES,
            'UTF-8'
        );

        $kmlCoordinates = [];
        foreach ($lines as $line) {
            $points = [];
            foreach ($line as $point) {
                if (! is_array($point) || count($point) < 2) {
                    continue;
                }

                $lon = (float) $point[0];
                $lat = (float) $point[1];
                $alt = isset($point[2]) ? (float) $point[2] : null;

                if ($alt !== null) {
                    $points[] = sprintf('%.8F,%.8F,%.3F', $lon, $lat, $alt);
                } else {
                    $points[] = sprintf('%.8F,%.8F,0', $lon, $lat);
                }
            }

            if ($points !== []) {
                $kmlCoordinates[] = implode(' ', $points);
            }
        }

        if ($kmlCoordinates === []) {
            abort(422, 'Geometria traseului nu conține puncte valide.');
        }

        $nameXml = $xmlEscape($name);
        $placemarks = [];

        foreach ($kmlCoordinates as $index => $line) {
            $placemarks[] = <<<KML
        <Placemark>
            <name>{$nameXml}</name>
            <Style>
                <LineStyle>
                    <color>ff0055ff</color>
                    <width>5</width>
                </LineStyle>
            </Style>
            <LineString>
                <tessellate>1</tessellate>
                <coordinates>{$line}</coordinates>
            </LineString>
        </Placemark>
KML;
        }

        $kml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<kml xmlns="http://www.opengis.net/kml/2.2">' . "\n"
            . "  <Document>\n"
            . "    <name>{$nameXml}</name>\n"
            . implode("\n", $placemarks)
            . "\n  </Document>\n"
            . "</kml>\n";

        return response($kml, 200, [
            'Content-Type' => 'application/vnd.google-earth.kml+xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $safeFilename . '.kml"',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /** @param array<string, mixed> $geometry */
    private function validateClientGeometry(array $geometry): int
    {
        $coordinates = $geometry['coordinates'] ?? null;
        if (! is_array($coordinates)) {
            throw new \InvalidArgumentException('Geometria traseului este invalidă.');
        }

        $lines = $geometry['type'] === 'MultiLineString' ? $coordinates : [$coordinates];
        $total = 0;
        foreach ($lines as $line) {
            if (! is_array($line) || count($line) < 2) {
                throw new \InvalidArgumentException('Fiecare segment al traseului trebuie să conțină cel puțin două puncte.');
            }
            foreach ($line as $point) {
                if (! is_array($point) || count($point) < 2 || ! is_numeric($point[0]) || ! is_numeric($point[1])) {
                    throw new \InvalidArgumentException('Geometria conține un punct GPS invalid.');
                }
                $lon = (float) $point[0];
                $lat = (float) $point[1];
                if (! is_finite($lon) || ! is_finite($lat) || $lon < -180 || $lon > 180 || $lat < -90 || $lat > 90) {
                    throw new \InvalidArgumentException('Geometria conține coordonate GPS în afara limitelor.');
                }
                if (isset($point[2]) && (! is_numeric($point[2]) || ! is_finite((float) $point[2]))) {
                    throw new \InvalidArgumentException('Altitudinea GPS este invalidă.');
                }
                $total++;
                if ($total > (int) config('tracking.planned_route_max_coordinates', 10000)) {
                    throw new \InvalidArgumentException('Traseul depășește numărul maxim de puncte.');
                }
            }
        }

        return $total;
    }

    /** @param array<string, mixed> $geometry */
    private function calculateGeometryDistanceKm(array $geometry): float
    {
        $lines = $geometry['type'] === 'MultiLineString' ? $geometry['coordinates'] : [$geometry['coordinates']];
        $distance = 0.0;
        foreach ($lines as $line) {
            $previous = null;
            foreach ($line as $point) {
                $current = [(float) $point[1], (float) $point[0]];
                if ($previous !== null) {
                    $distance += $this->haversineKm($previous[0], $previous[1], $current[0], $current[1]);
                }
                $previous = $current;
            }
        }

        return round($distance, 3);
    }

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earth = 6371.0088;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earth * 2 * asin(min(1.0, sqrt($a)));
    }

    private function ownedRoute(int $routeId): PlannedRoute
    {
        return PlannedRoute::query()
            ->where('user_id', auth()->id())
            ->findOrFail($routeId);
    }

    /** @param array<string, mixed> $geometry */
    private function insertRoutePointsFromGeometry(PlannedRoute $route, array $geometry): void
    {
        if (! Schema::hasTable('route_points')) {
            return;
        }

        $columns = Schema::getColumnListing('route_points');
        $coordinates = $geometry['coordinates'] ?? [];
        $flat = $geometry['type'] === 'MultiLineString'
            ? array_merge(...$coordinates)
            : $coordinates;

        $rows = [];
        $sequence = 0;
        $now = now();

        foreach ($flat as $point) {
            if (! is_array($point) || count($point) < 2) {
                continue;
            }

            $row = [];
            if (in_array('route_id', $columns, true)) {
                $row['route_id'] = $route->id;
            }
            if (in_array('sequence', $columns, true)) {
                $row['sequence'] = $sequence++;
            }
            if (in_array('longitude', $columns, true)) {
                $row['longitude'] = (float) $point[0];
            }
            if (in_array('latitude', $columns, true)) {
                $row['latitude'] = (float) $point[1];
            }
            if (isset($point[2]) && in_array('elevation', $columns, true)) {
                $row['elevation'] = (float) $point[2];
            }
            if (in_array('created_at', $columns, true)) {
                $row['created_at'] = $now;
            }
            if (in_array('updated_at', $columns, true)) {
                $row['updated_at'] = $now;
            }

            $rows[] = $row;
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            if ($chunk !== []) {
                DB::table('route_points')->insert($chunk);
            }
        }
    }
}
