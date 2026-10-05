<?php

namespace App\Transit\Realtime;

use App\Transit\Models\TransitFeed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class RealtimePoller
{
    public function __construct(
        private readonly GtfsRealtimeDecoder $decoder,
        private readonly VehicleStore $store,
    ) {}

    /** @return Collection<int, TransitFeed> */
    public function activeFeeds()
    {
        return TransitFeed::query()
            ->where('active', true)
            ->whereNotNull('vehicle_positions_url')
            ->whereNotNull('static_hash')
            ->where('requested_at', '>=', now()->subMinutes((int) config('transit.realtime.active_minutes')))
            ->get();
    }

    /** Fetches and stores the positions; returns the number of vehicles kept, or null on failure. */
    public function poll(TransitFeed $feed): ?int
    {
        if ($this->store->isBackedOff($feed->id)) {
            return null;
        }

        try {
            $response = Http::withHeaders(['User-Agent' => 'Symbiot/1.0 GTFS-RT poller'])
                ->timeout(15)
                ->get($feed->vehicle_positions_url);
            $response->throw();

            $vehicles = $this->enrich($feed, $this->fresh($this->decoder->vehicles($response->body())));
            $this->store->replace($feed->id, $vehicles);

            return count($vehicles);
        } catch (Throwable $e) {
            $this->store->markFailed($feed->id);
            Log::warning("GTFS-RT poll failed for feed {$feed->id}: {$e->getMessage()}");

            return null;
        }
    }

    /** @param list<array<string, mixed>> $vehicles */
    private function fresh(array $vehicles): array
    {
        $oldest = time() - (int) config('transit.realtime.max_vehicle_age_seconds');

        return array_values(array_filter(
            $vehicles,
            fn (array $v): bool => $v['timestamp'] === null || $v['timestamp'] >= $oldest,
        ));
    }

    /** Adds route name, colour and mode so the map needs no extra lookups. */
    private function enrich(TransitFeed $feed, array $vehicles): array
    {
        $routes = DB::table('transit_routes')->where('feed_id', $feed->id)
            ->get(['route_id', 'short_name', 'long_name', 'route_type', 'color', 'text_color'])
            ->keyBy('route_id');

        $needsTrip = array_values(array_filter(array_map(
            fn (array $v): ?string => $v['route_id'] === null ? $v['trip_id'] : null,
            $vehicles,
        )));
        $tripRoutes = $needsTrip === [] ? collect() : DB::table('transit_trips')
            ->where('feed_id', $feed->id)->whereIn('trip_id', $needsTrip)->pluck('route_id', 'trip_id');

        return array_map(function (array $v) use ($feed, $routes, $tripRoutes): array {
            $routeId = $v['route_id'] ?? ($v['trip_id'] !== null ? $tripRoutes[$v['trip_id']] ?? null : null);
            $route = $routeId !== null ? $routes->get($routeId) : null;

            return $v + [
                'feed_id' => $feed->id,
                'route_name' => $route?->short_name ?: $route?->long_name ?: $routeId,
                'route_type' => $route?->route_type,
                'color' => $route?->color,
                'text_color' => $route?->text_color,
            ];
        }, array_map(fn (array $v): array => array_merge($v, ['route_id' => $v['route_id'] ?? null]), $vehicles));
    }
}
