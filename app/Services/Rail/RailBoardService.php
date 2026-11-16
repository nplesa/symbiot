<?php

namespace App\Services\Rail;

use App\Services\RailTimetableProvider;
use Illuminate\Support\Facades\Log;

/**
 * Departures/arrivals board of a railway station: live worldwide data from Transitous,
 * with the published Romanian annual timetable (data.gov.ro) as the fallback when
 * Transitous is unavailable or has no trains for the station.
 *
 * @phpstan-import-type RailStop from TransitousClient
 * @phpstan-import-type BoardEntry from TransitousClient
 */
class RailBoardService
{
    public const ATTRIBUTION_URL = 'https://transitous.org/sources/';

    public function __construct(
        private readonly TransitousClient $transitous,
        private readonly RailTimetableProvider $annualTimetable,
    ) {}

    /**
     * @param  'departures'|'arrivals'  $type
     * @return array{
     *     type: string,
     *     live: array{source: string, station: array{name: string, timezone: ?string}, items: list<BoardEntry>, attribution_url: string}|null,
     *     live_failed: bool,
     *     timetable: array{source: string, services: list<array<string, mixed>>, valid_until: ?string, available: bool}|null
     * }
     */
    public function board(float $lat, float $lon, ?string $stationName, string $type): array
    {
        $live = null;
        $liveFailed = false;

        if ($this->transitous->enabled()) {
            try {
                $station = $this->transitous->nearestStation($lat, $lon);
                if ($station !== null) {
                    $live = [
                        'source' => 'Transitous',
                        'station' => ['name' => $station['name'], 'timezone' => $station['timezone']],
                        'items' => $this->transitous->board($station['id'], $type),
                        'attribution_url' => self::ATTRIBUTION_URL,
                    ];
                }
            } catch (\Throwable $e) {
                $liveFailed = true;
                Log::warning('Transitous rail board lookup failed', ['message' => $e->getMessage()]);
            }
        }

        // The annual Romanian timetable only lists departures; it is the fallback when there is no live board.
        $timetable = null;
        if (($live === null || $live['items'] === []) && $stationName !== null && trim($stationName) !== '') {
            $timetable = $this->annualTimetable($stationName);
        }

        return [
            'type' => $type,
            'live' => $live,
            'live_failed' => $liveFailed,
            'timetable' => $timetable,
        ];
    }

    /** @return array{source: string, services: list<array<string, mixed>>, valid_until: ?string, available: bool} */
    private function annualTimetable(string $stationName): array
    {
        $station = $this->annualTimetable->addScheduledServices([['type' => 'train', 'name' => $stationName]])[0];

        return [
            'source' => (string) ($station['train_timetable_source'] ?? 'data.gov.ro'),
            'services' => array_values((array) ($station['train_services'] ?? [])),
            'valid_until' => isset($station['train_timetable_valid_until']) ? (string) $station['train_timetable_valid_until'] : null,
            'available' => (bool) ($station['train_services_available'] ?? false),
        ];
    }
}
