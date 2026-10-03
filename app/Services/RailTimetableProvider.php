<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SimpleXMLElement;

class RailTimetableProvider
{
    /**
     * @param  list<array<string, mixed>>  $pois
     * @return list<array<string, mixed>>
     */
    public function addScheduledServices(array $pois): array
    {
        $stationPois = array_values(array_filter(
            $pois,
            fn (array $poi): bool => ($poi['type'] ?? null) === 'train'
        ));
        if ($stationPois === []) {
            return $pois;
        }

        $stationKeys = [];
        foreach ($stationPois as $poi) {
            $key = $this->normalizeStationName((string) ($poi['name'] ?? ''));
            if ($key !== '') {
                $stationKeys[$key] = true;
            }
        }

        $servicesByStation = [];
        $available = true;
        $validUntil = null;

        foreach (config('services.rail_timetable.packages', []) as $source) {
            try {
                $timetable = $this->loadPackageTimetable($source);
                if ($timetable === null
                    || now()->format('Ymd') < $timetable['valid_from']
                    || now()->format('Ymd') > $timetable['valid_until']) {
                    continue;
                }

                $validUntil = max($validUntil ?? '', $timetable['valid_until']);
                foreach ($stationKeys as $stationKey => $_) {
                    foreach ($timetable['services'][$stationKey] ?? [] as $service) {
                        $servicesByStation[$stationKey][] = $service;
                    }
                }
            } catch (\Throwable $e) {
                $available = false;
                Log::warning('Rail timetable source lookup failed', [
                    'package' => $source['package'] ?? 'unknown',
                    'message' => $e->getMessage(),
                ]);
            }
        }

        foreach ($pois as &$poi) {
            if (($poi['type'] ?? null) !== 'train') {
                continue;
            }

            $stationKey = $this->normalizeStationName((string) ($poi['name'] ?? ''));
            $services = $servicesByStation[$stationKey] ?? [];
            $poi['train_services'] = collect($services)
                ->unique(fn (array $service): string => implode('|', [
                    $service['operator'],
                    $service['number'],
                    $service['departure'] ?? '',
                    $service['direction'],
                ]))
                ->sortBy(fn (array $service): string => ($service['departure'] ?? '99:99') . '|' . $service['number'])
                ->values()
                ->all();
            $poi['train_services_available'] = $available;
            $poi['train_timetable_valid_until'] = $validUntil === null
                ? null
                : $this->formatDate($validUntil);
            $poi['train_timetable_source'] = 'data.gov.ro';
        }
        unset($poi);

        return $pois;
    }

    /**
     * @param  array{package: string, operator: string}  $source
     * @return array{valid_from: string, valid_until: string, services: array<string, list<array{number: string, category: string, operator: string, direction: string, departure: ?string, service_days: ?string}>>}|null
     */
    private function loadPackageTimetable(array $source): ?array
    {
        $package = $source['package'];
        $metadata = Cache::remember(
            'rail_timetable_package:v1:' . $package,
            now()->addHours(12),
            function () use ($package): array {
                $response = Http::timeout(20)
                    ->retry(1, 300)
                    ->acceptJson()
                    ->get('https://data.gov.ro/api/3/action/package_show', ['id' => $package]);

                if (! $response->successful() || ! $response->json('success')) {
                    throw new \RuntimeException('data.gov.ro package metadata request failed.');
                }

                return $response->json('result') ?? [];
            }
        );

        $resources = collect($metadata['resources'] ?? [])
            ->filter(fn (array $resource): bool => preg_match('/\.xml(?:$|\?)/i', (string) ($resource['url'] ?? '')) === 1)
            ->sortByDesc('created')
            ->values();

        foreach ($resources as $resource) {
            $resourceId = (string) ($resource['id'] ?? '');
            $url = (string) ($resource['url'] ?? '');
            if ($resourceId === '' || ! str_starts_with($url, 'https://data.gov.ro/')) {
                continue;
            }

            $timetable = Cache::remember(
                'rail_timetable_data:v1:' . $resourceId,
                now()->addHours(24),
                fn (): array => $this->downloadAndParse($url, $source['operator'])
            );

            if (now()->format('Ymd') >= $timetable['valid_from']
                && now()->format('Ymd') <= $timetable['valid_until']) {
                return $timetable;
            }
        }

        return null;
    }

    /**
     * @return array{valid_from: string, valid_until: string, services: array<string, list<array{number: string, category: string, operator: string, direction: string, departure: ?string, service_days: ?string}>>}
     */
    private function downloadAndParse(string $url, string $operator): array
    {
        $response = Http::timeout(45)
            ->retry(1, 500)
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException('data.gov.ro timetable download returned HTTP ' . $response->status() . '.');
        }

        $previousErrorMode = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string(
                $response->body(),
                SimpleXMLElement::class,
                LIBXML_NONET | LIBXML_NOBLANKS
            );
            if ($xml === false) {
                throw new \RuntimeException('The timetable XML is invalid.');
            }

            $meta = $xml->XmlMts->Mt ?? null;
            $validFrom = (string) ($meta['MtValabilDeLa'] ?? '');
            $validUntil = (string) ($meta['MtValabilPinaLa'] ?? '');
            if (! preg_match('/^\d{8}$/', $validFrom) || ! preg_match('/^\d{8}$/', $validUntil)) {
                throw new \RuntimeException('The timetable validity dates are missing.');
            }

            $servicesByStation = [];
            foreach ($xml->xpath('//Tren') ?: [] as $train) {
                $segments = $train->xpath('./Trase/Trasa/ElementTrasa') ?: [];
                if ($segments === []) {
                    continue;
                }

                $firstSegment = $segments[0];
                $lastSegment = $segments[array_key_last($segments)];
                $from = trim((string) ($firstSegment['DenStaOrigine'] ?? ''));
                $to = trim((string) ($lastSegment['DenStaDestinatie'] ?? ''));
                $number = trim((string) ($train['Numar'] ?? ''));
                if ($number === '' || $from === '' || $to === '') {
                    continue;
                }
                $serviceDays = $this->serviceDays($train);

                foreach ($segments as $segment) {
                    if (! in_array((string) ($segment['TipOprire'] ?? ''), ['C', 'T'], true)) {
                        continue;
                    }

                    $stationKey = $this->normalizeStationName((string) ($segment['DenStaOrigine'] ?? ''));
                    if ($stationKey === '') {
                        continue;
                    }

                    $servicesByStation[$stationKey][] = [
                        'number' => $number,
                        'category' => trim((string) ($train['CategorieTren'] ?? '')),
                        'operator' => $operator,
                        'direction' => $to,
                        'departure' => $this->formatSeconds((string) ($segment['OraP'] ?? '')),
                        'service_days' => $serviceDays,
                    ];
                }
            }

            return [
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'services' => $servicesByStation,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
    }

    private function normalizeStationName(string $name): string
    {
        $name = Str::ascii(Str::lower($name));
        $name = preg_replace('/\b(?:hm|hc|halt|halta)\b/u', ' ', $name) ?? $name;

        return trim(preg_replace('/[^a-z0-9]+/u', ' ', $name) ?? $name);
    }

    private function formatSeconds(string $seconds): ?string
    {
        if (! ctype_digit($seconds)) {
            return null;
        }

        $seconds = (int) $seconds;
        $dayOffset = intdiv($seconds, 86400);
        $time = gmdate('H:i', $seconds % 86400);

        return $dayOffset > 0 ? $time . ' (+' . $dayOffset . ' zi)' : $time;
    }

    private function formatDate(string $date): string
    {
        return substr($date, 6, 2) . '.' . substr($date, 4, 2) . '.' . substr($date, 0, 4);
    }

    private function serviceDays(SimpleXMLElement $train): ?string
    {
        $calendars = $train->xpath('./RestrictiiTren/CalendarTren[@Tip="Da"]') ?: [];
        $dayMasks = [];
        foreach ($calendars as $calendar) {
            $mask = (int) ($calendar['Zile'] ?? 0) & 0x7F;
            if ($mask > 0) {
                $dayMasks[$mask] = true;
            }
        }
        if ($dayMasks === []) {
            return null;
        }

        $days = [
            1 => 'luni',
            2 => 'marți',
            4 => 'miercuri',
            8 => 'joi',
            16 => 'vineri',
            32 => 'sâmbătă',
            64 => 'duminică',
        ];
        $labels = [];
        foreach (array_keys($dayMasks) as $mask) {
            if ($mask === 0x7F) {
                $labels[] = 'zilnic';

                continue;
            }
            if ($mask === 0x1F) {
                $labels[] = 'luni–vineri';

                continue;
            }
            if ($mask === 0x60) {
                $labels[] = 'weekend';

                continue;
            }

            $labels[] = implode(', ', array_values(array_filter(
                $days,
                fn (string $day, int $bit): bool => ($mask & $bit) !== 0,
                ARRAY_FILTER_USE_BOTH
            )));
        }

        return implode(' / ', $labels);
    }
}
