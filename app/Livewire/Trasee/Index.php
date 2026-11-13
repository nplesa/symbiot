<?php

namespace App\Livewire\Trasee;

use App\Exceptions\UserFacingException;
use App\Models\Route as PlannedRoute;
use App\Services\RouteImportService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
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

        // Only the selected route needs its (possibly 10k-point) geometry, for the map preview.
        $selectedRoute = $this->selectedRouteId && $routes->contains('id', $this->selectedRouteId)
            ? PlannedRoute::query()->where('user_id', auth()->id())->find($this->selectedRouteId)
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

                // Only messages written for users are shown; anything else (e.g. SQL errors) stays in the log.
                $message = $e instanceof UserFacingException && $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Fișierul de traseu nu a putut fi importat.';
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
        // Everything the list shows, without geometry: it is re-rendered on every Livewire update.
        return PlannedRoute::query()
            ->select(['id', 'user_id', 'name', 'format', 'distance', 'duration', 'created_at'])
            ->where('user_id', auth()->id())
            ->withCount('points')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    private function ownedRoute(int $routeId): PlannedRoute
    {
        return PlannedRoute::query()
            ->where('user_id', auth()->id())
            ->findOrFail($routeId);
    }
}
