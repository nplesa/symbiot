<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FlightAwareProvider
{
    /**
     * Returnează sosirile și plecările programate
     * pentru următoarele 24 de ore.
     *
     * @return array<string, mixed>
     */
    public function airportBoard(string $airport): array
    {
        $airport = strtoupper(trim($airport));

        if (! preg_match('/^[A-Z0-9]{3,4}$/', $airport)) {
            throw new RuntimeException(
                'Codul aeroportului trebuie să fie IATA sau ICAO.'
            );
        }

        $apiKey = config('services.flightaware.key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            return $this->unavailable(
                'not_configured',
                'FlightAware nu este configurat. Verifică FLIGHTAWARE_API_KEY în .env.',
                $airport
            );
        }

        $baseUrl = rtrim(
            (string) config('services.flightaware.base_url'),
            '/'
        );

        if ($baseUrl === '') {
            return $this->unavailable(
                'invalid_configuration',
                'Adresa API FlightAware nu este configurată.',
                $airport
            );
        }

        // Versiune nouă pentru invalidarea cache-ului anterior.
        $cacheKey = 'flightaware:airport-board:v3:'
            . $airport . ':'
            . now()->utc()->format('YmdH');

        try {
            return Cache::remember(
                $cacheKey,
                now()->addMinutes(2),
                function () use ($airport, $apiKey, $baseUrl): array {
                    $startAt = now()->utc();
                    $endAt = $startAt->copy()->addHours(24);

                    $start = $startAt->toIso8601String();
                    $end = $endAt->toIso8601String();

                    $fetch = function (string $direction) use (
                        $airport,
                        $apiKey,
                        $baseUrl,
                        $start,
                        $end
                    ): array {
                        $flightKey = "scheduled_{$direction}";

                        $url = "{$baseUrl}/airports/{$airport}/flights/{$flightKey}";

                        $response = Http::withHeaders([
                            'x-apikey' => trim($apiKey),
                        ])
                            ->acceptJson()
                            ->connectTimeout(5)
                            ->timeout(20)
                            ->get($url, [
                                'start' => $start,
                                'end' => $end,
                                'max_pages' => 3,
                            ]);

                        if (! $response->successful()) {
                            Log::warning(
                                'FlightAware request failed.',
                                [
                                    'airport' => $airport,
                                    'direction' => $direction,
                                    'http_status' => $response->status(),
                                ]
                            );

                            throw new RuntimeException(
                                "FlightAware {$direction} request returned HTTP {$response->status()}."
                            );
                        }

                        $payload = $response->json();

                        if (
                            ! is_array($payload)
                            || ! array_key_exists($flightKey, $payload)
                            || ! is_array($payload[$flightKey])
                        ) {
                            Log::warning(
                                'FlightAware returned an unexpected response structure.',
                                [
                                    'airport' => $airport,
                                    'direction' => $direction,
                                    'http_status' => $response->status(),
                                    'expected_key' => $flightKey,
                                    'response_keys' => is_array($payload)
                                        ? array_keys($payload)
                                        : [],
                                ]
                            );

                            throw new RuntimeException(
                                "FlightAware {$direction} response has an unexpected JSON structure."
                            );
                        }

                        $flights = array_values(
                            array_filter(
                                $payload[$flightKey],
                                'is_array'
                            )
                        );

                        Log::info('FlightAware airport response', [
                            'airport' => $airport,
                            'direction' => $direction,
                            'http_status' => $response->status(),
                            'response_keys' => array_keys($payload),
                            'flights_count' => count($flights),
                            'num_pages' => $payload['num_pages'] ?? null,
                        ]);

                        return $flights;
                    };

                    $arrivals = $fetch('arrivals');
                    $departures = $fetch('departures');

                    $normalizedArrivals = array_map(
                        fn (array $flight): array =>
                            $this->normalizeFlight($flight, 'arrival'),
                        $arrivals
                    );

                    $normalizedDepartures = array_map(
                        fn (array $flight): array =>
                            $this->normalizeFlight($flight, 'departure'),
                        $departures
                    );

                    return [
                        'available' => true,
                        'reason' => null,
                        'message' => null,

                        'has_flights' => count($arrivals) + count($departures) > 0,

                        'arrivals' => $normalizedArrivals,
                        'departures' => $normalizedDepartures,

                        'counts' => [
                            'arrivals' => count($normalizedArrivals),
                            'departures' => count($normalizedDepartures),
                            'total' => count($normalizedArrivals)
                                + count($normalizedDepartures),
                        ],

                        'source' => 'FlightAware AeroAPI',
                        'updated_at' => now()->toIso8601String(),

                        'window' => [
                            'start' => $start,
                            'end' => $end,
                        ],
                    ];
                }
            );
        } catch (Throwable $exception) {
            Log::warning('FlightAware airport board request failed', [
                'airport' => $airport,
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
            ]);

            return $this->unavailable(
                'provider_error',
                'Nu am putut prelua datele de zbor de la FlightAware. Încearcă din nou mai târziu.',
                $airport
            );
        }
    }

    /**
     * Normalizează datele unui zbor.
     *
     * @return array<string, mixed>
     */
    private function normalizeFlight(
        array $flight,
        string $direction
    ): array {
        $isArrival = $direction === 'arrival';

        $scheduled = $isArrival
            ? ($flight['scheduled_in'] ?? null)
            : ($flight['scheduled_out'] ?? null);

        $estimated = $isArrival
            ? ($flight['estimated_in'] ?? null)
            : ($flight['estimated_out'] ?? null);

        $actual = $isArrival
            ? ($flight['actual_in'] ?? null)
            : ($flight['actual_out'] ?? null);

        $origin = is_array($flight['origin'] ?? null)
            ? $flight['origin']
            : [];

        $destination = is_array($flight['destination'] ?? null)
            ? $flight['destination']
            : [];

        $airportData = $isArrival ? $destination : $origin;

        $originCode = $origin['code_iata']
            ?? $origin['code']
            ?? $flight['origin_iata']
            ?? (is_string($flight['origin'] ?? null)
                ? $flight['origin']
                : null);

        $destinationCode = $destination['code_iata']
            ?? $destination['code']
            ?? $flight['destination_iata']
            ?? (is_string($flight['destination'] ?? null)
                ? $flight['destination']
                : null);

        return [
            'id' => $flight['fa_flight_id']
                ?? $flight['ident']
                ?? null,

            'number' => $flight['ident_iata']
                ?? $flight['ident']
                ?? 'Zbor',

            'operator' => $flight['operator_iata']
                ?? $flight['operator']
                ?? null,

            'origin' => $originCode,
            'origin_name' => $origin['name']
                ?? $origin['city']
                ?? null,

            'destination' => $destinationCode,
            'destination_name' => $destination['name']
                ?? $destination['city']
                ?? null,

            'airport_timezone' => $airportData['timezone']
                ?? null,

            'scheduled_time' => $scheduled,
            'estimated_time' => $estimated,
            'actual_time' => $actual,

            'terminal' => $isArrival
                ? ($flight['terminal_destination'] ?? null)
                : ($flight['terminal_origin'] ?? null),

            'gate' => $isArrival
                ? ($flight['gate_destination'] ?? null)
                : ($flight['gate_origin'] ?? null),

            'baggage_claim' => $isArrival
                ? ($flight['baggage_claim'] ?? null)
                : null,

            'status' => $flight['status'] ?? null,

            'cancelled' => (bool) ($flight['cancelled'] ?? false),
            'diverted' => (bool) ($flight['diverted'] ?? false),

            'direction' => $direction,
        ];
    }

    /**
     * Construiește răspunsul pentru situațiile indisponibile.
     *
     * @return array<string, mixed>
     */
    private function unavailable(
        string $reason,
        string $message,
        string $airport
    ): array {
        return [
            'available' => false,
            'reason' => $reason,
            'message' => $message,

            'has_flights' => false,

            'arrivals' => [],
            'departures' => [],

            'counts' => [
                'arrivals' => 0,
                'departures' => 0,
                'total' => 0,
            ],

            'source' => 'FlightAware AeroAPI',
            'airport' => $airport,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
