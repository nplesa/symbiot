<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Prices from the official Monitorul Prețurilor Carburanți (Consiliul Concurenței). */
class FuelPriceService
{
    private const ENDPOINT = 'https://monitorulpreturilor.info/pmonsvc/Gas/GetGasItemsByLatLon';

    private const PRODUCTS = [
        '11' => 'Benzină standard',
        '12' => 'Benzină premium',
        '21' => 'Motorină standard',
        '22' => 'Motorină premium',
        '31' => 'GPL',
    ];

    private const MATCH_METERS = 250;

    private const SOURCE = 'Monitorul Prețurilor Carburanți';

    /** County seats (and Bucharest) used to sample prices across the country. */
    private const CITIES = [
        'Alba Iulia' => [46.07, 23.58], 'Arad' => [46.18, 21.32], 'Pitești' => [44.86, 24.87],
        'Bacău' => [46.57, 26.91], 'Oradea' => [47.05, 21.93], 'Bistrița' => [47.13, 24.50],
        'Botoșani' => [47.75, 26.66], 'Brașov' => [45.65, 25.60], 'Brăila' => [45.27, 27.97],
        'Buzău' => [45.15, 26.82], 'Reșița' => [45.30, 21.89], 'Călărași' => [44.20, 27.33],
        'Cluj-Napoca' => [46.77, 23.59], 'Constanța' => [44.18, 28.65], 'Sfântu Gheorghe' => [45.86, 25.79],
        'Târgoviște' => [44.93, 25.46], 'Craiova' => [44.32, 23.80], 'Galați' => [45.44, 28.05],
        'Giurgiu' => [43.90, 25.97], 'Târgu Jiu' => [45.04, 23.27], 'Miercurea Ciuc' => [46.36, 25.80],
        'Deva' => [45.88, 22.91], 'Slobozia' => [44.57, 27.37], 'Iași' => [47.16, 27.59],
        'București' => [44.43, 26.10], 'Baia Mare' => [47.66, 23.57], 'Drobeta-Turnu Severin' => [44.63, 22.66],
        'Târgu Mureș' => [46.54, 24.56], 'Piatra Neamț' => [46.93, 26.37], 'Slatina' => [44.43, 24.37],
        'Ploiești' => [44.94, 26.03], 'Satu Mare' => [47.79, 22.88], 'Zalău' => [47.19, 23.06],
        'Sibiu' => [45.80, 24.15], 'Suceava' => [47.65, 26.26], 'Alexandria' => [43.97, 25.33],
        'Timișoara' => [45.75, 21.23], 'Tulcea' => [45.18, 28.80], 'Vaslui' => [46.64, 27.73],
        'Râmnicu Vâlcea' => [45.10, 24.37], 'Focșani' => [45.70, 27.18],
    ];

    // The service returns nothing for larger search buffers.
    private const MAX_BUFFER = 5000;

    /** @return array{station: ?array<string, mixed>, prices: list<array<string, mixed>>, source: string} */
    public function forLocation(float $lat, float $lon, ?string $brand = null): array
    {
        $key = sprintf('fuel_prices:v2:%.4f:%.4f:%s', $lat, $lon, mb_strtolower((string) $brand));

        return Cache::remember($key, now()->addMinutes(15), fn (): array => $this->fetch($lat, $lon, $brand));
    }

    /** @return array{station: ?array<string, mixed>, prices: list<array<string, mixed>>, source: string} */
    private function fetch(float $lat, float $lon, ?string $brand): array
    {
        $verify = $this->caBundle();
        $responses = Http::pool(fn (Pool $pool) => collect(array_keys(self::PRODUCTS))
            ->map(fn (string $id) => $pool->as($id)->withOptions(['verify' => $verify])->timeout(15)->get(self::ENDPOINT, [
                'lat' => $lat,
                'lon' => $lon,
                'buffer' => 400,
                'CSVGasCatalogProductIds' => $id,
                'OrderBy' => 'dist',
            ]))
            ->all());

        $stations = [];
        $products = [];
        $services = [];

        foreach (self::PRODUCTS as $id => $label) {
            $response = $responses[$id] ?? null;
            if (! $response instanceof Response || ! $response->successful()) {
                continue;
            }

            $xml = $this->parse($response->body());
            if ($xml === null) {
                continue;
            }

            foreach ($xml->Stations->GasStation ?? [] as $station) {
                $stations[(string) $station->Id] ??= $this->stationData($station);
            }

            foreach ($xml->Services->GasServiceCatalog ?? [] as $service) {
                $name = trim((string) $service->Name);
                if ($name !== '') {
                    $services[(string) $service->Stationid][$name] = $name;
                }
            }

            foreach ($xml->Products->GasProduct ?? [] as $product) {
                $price = (float) $product->Price;
                if ($price <= 0) {
                    continue;
                }
                $products[(string) $product->Stationid][] = [
                    'category' => $id,
                    'label' => $label,
                    'product' => trim((string) $product->Name),
                    'price' => $price,
                ];
            }
        }

        $station = $this->closestStation($stations, $lat, $lon, $brand);
        if ($station !== null) {
            $station['services'] = array_values($services[$station['id']] ?? []);
        }

        return [
            'station' => $station,
            'prices' => $station === null ? [] : $this->cheapestPerCategory($products[$station['id']] ?? []),
            'source' => self::SOURCE,
        ];
    }

    /**
     * Ranks the fuel chains around a location by their average price for one fuel.
     * Only chains listed as fuel brand subcategories are ranked, optionally narrowed to $brands.
     *
     * @param  ?list<string>  $brands  Brand subcategory ids (e.g. "petrom"); null or empty means all known brands.
     * @return array{chains: list<array<string, mixed>>, radius: int, source: string}
     */
    public function bestChains(float $lat, float $lon, int $radius, string $category, ?array $brands = null): array
    {
        $radius = max(500, min($radius, self::MAX_BUFFER));
        $brands = $brands === null || $brands === [] ? null : array_values(array_unique($brands));
        if ($brands !== null) {
            sort($brands);
        }
        $key = sprintf('fuel_best:v2:%.3f:%.3f:%d:%s:%s', $lat, $lon, $radius, $category, md5(implode(',', $brands ?? [])));

        return Cache::remember($key, now()->addMinutes(15), fn (): array => [
            'chains' => $this->rank($this->fetchPriced($lat, $lon, $radius, $category), $lat, $lon, $brands),
            'radius' => $radius,
            'source' => self::SOURCE,
        ]);
    }

    /**
     * Ranks the chains across Romania by sampling the area around each county seat.
     *
     * @return array{chains: list<array<string, mixed>>, cities: int, source: string}
     */
    public function bestChainsInCountry(string $category): array
    {
        return Cache::remember('fuel_best_country:v1:' . $category, now()->addHour(), function () use ($category): array {
            $verify = $this->caBundle();
            $responses = Http::pool(fn (Pool $pool) => collect(self::CITIES)
                ->map(fn (array $city, string $name) => $pool->as($name)->withOptions(['verify' => $verify])->timeout(20)->get(self::ENDPOINT, [
                    'lat' => $city[0],
                    'lon' => $city[1],
                    'buffer' => self::MAX_BUFFER,
                    'CSVGasCatalogProductIds' => $category,
                    'OrderBy' => 'dist',
                ]))
                ->all());

            $priced = [];
            $cities = 0;
            foreach (array_keys(self::CITIES) as $name) {
                $response = $responses[$name] ?? null;
                $xml = $response instanceof Response && $response->successful() ? $this->parse($response->body()) : null;
                if ($xml === null) {
                    continue;
                }
                $cities++;
                foreach ($this->pricedStations($xml) as $id => $station) {
                    $priced[$id] = $station;
                }
            }

            if ($cities === 0) {
                throw new \RuntimeException('Monitorul Prețurilor is unavailable.');
            }

            return ['chains' => $this->rank($priced, null, null, null), 'cities' => $cities, 'source' => self::SOURCE];
        });
    }

    /** @return array<string, array<string, mixed>> Stations with their lowest price for the requested fuel, by station id. */
    private function fetchPriced(float $lat, float $lon, int $radius, string $category): array
    {
        $response = Http::withOptions(['verify' => $this->caBundle()])->timeout(20)->get(self::ENDPOINT, [
            'lat' => $lat,
            'lon' => $lon,
            'buffer' => $radius,
            'CSVGasCatalogProductIds' => $category,
            'OrderBy' => 'dist',
        ]);

        $xml = $response->successful() ? $this->parse($response->body()) : null;
        if ($xml === null) {
            throw new \RuntimeException('Monitorul Prețurilor is unavailable.');
        }

        return $this->pricedStations($xml);
    }

    /** @return array<string, array<string, mixed>> */
    private function pricedStations(\SimpleXMLElement $xml): array
    {
        $stations = [];
        foreach ($xml->Stations->GasStation ?? [] as $station) {
            $stations[(string) $station->Id] = $this->stationData($station);
        }

        $priced = [];
        foreach ($xml->Products->GasProduct ?? [] as $product) {
            $id = (string) $product->Stationid;
            $price = (float) $product->Price;
            if ($price <= 0 || ! isset($stations[$id])) {
                continue;
            }
            $priced[$id] ??= $stations[$id];
            $priced[$id]['price'] = min($priced[$id]['price'] ?? PHP_FLOAT_MAX, $price);
        }

        return $priced;
    }

    /**
     * @param  array<string, array<string, mixed>>  $priced
     * @param  ?list<string>  $brands
     * @return list<array<string, mixed>>
     */
    private function rank(array $priced, ?float $lat, ?float $lon, ?array $brands): array
    {
        $chains = [];
        foreach ($priced as $station) {
            $brand = $this->brandOf($station['network_id'], $station['network']);
            if ($brand === null || ($brands !== null && ! in_array($brand['id'], $brands, true))) {
                continue;
            }

            $meters = $lat !== null ? (int) round($this->meters($lat, $lon, $station['lat'], $station['lon'])) : null;
            $chains[$brand['id']]['name'] = $brand['label'];
            $chains[$brand['id']]['prices'][] = $station['price'];
            $current = $chains[$brand['id']]['station'] ?? null;
            $better = $current === null
                || ($meters !== null ? $meters < $current['meters'] : $station['price'] < $current['price']);
            if ($better) {
                $chains[$brand['id']]['station'] = [
                    'name' => $station['name'],
                    'address' => $station['address'],
                    'lat' => $station['lat'],
                    'lon' => $station['lon'],
                    'price' => $station['price'],
                    'meters' => $meters,
                ];
            }
        }

        return collect($chains)->map(fn (array $chain, string $id): array => [
            'id' => $id,
            'name' => $chain['name'],
            'average_price' => round(array_sum($chain['prices']) / count($chain['prices']), 2),
            'min_price' => min($chain['prices']),
            'stations' => count($chain['prices']),
            'nearest' => $chain['station'],
        ])->sortBy([['average_price', 'asc'], ['min_price', 'asc']])->values()->all();
    }

    /** @return ?array{id: string, label: string} The fuel brand subcategory a network belongs to. */
    private function brandOf(string $networkId, string $networkName): ?array
    {
        $names = array_filter([$this->normalize($networkId), $this->normalize($networkName)]);

        foreach (config('poi.osm_subcategories.fuel', []) as $subcategory) {
            $patterns = isset($subcategory['osm'][0]['brand']) ? explode('|', $subcategory['osm'][0]['brand']) : [];
            foreach ($patterns as $pattern) {
                $pattern = $this->normalize($pattern);
                foreach ($names as $name) {
                    if ($pattern !== '' && str_starts_with($name, $pattern)) {
                        return ['id' => $subcategory['id'], 'label' => $subcategory['label']];
                    }
                }
            }
        }

        return null;
    }

    public static function categories(): array
    {
        return self::PRODUCTS;
    }

    /** The host omits its intermediate certificate, so it is appended to the system CA bundle. */
    private function caBundle(): string|bool
    {
        $locations = openssl_get_cert_locations();
        $system = collect([ini_get('curl.cainfo'), ini_get('openssl.cafile'), $locations['default_cert_file'] ?? null, '/etc/ssl/certs/ca-certificates.crt'])
            ->first(fn ($path): bool => is_string($path) && $path !== '' && is_file($path));
        $configured = (string) config('services.petroleum.ca_certificate');
        $intermediate = preg_match('~^(/|[A-Za-z]:[\\\\/])~', $configured) ? $configured : base_path($configured);
        if (! $system || ! is_file($intermediate)) {
            return true;
        }

        $bundle = storage_path('app/certs/fuel-prices-ca.pem');
        if (! is_file($bundle) || filemtime($bundle) < max(filemtime($system), filemtime($intermediate))) {
            $contents = file_get_contents($system) . PHP_EOL . file_get_contents($intermediate);
            $directory = dirname($bundle);
            if ((! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory))
                || @file_put_contents($bundle, $contents) === false) {
                $bundle = tempnam(sys_get_temp_dir(), 'fuel-ca-');
                file_put_contents($bundle, $contents);
            }
        }

        return $bundle;
    }

    private function parse(string $body): ?\SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return null;
        }

        $namespaces = $xml->getNamespaces();

        return $namespaces === [] ? $xml : $xml->children(reset($namespaces));
    }

    /** @return array<string, mixed> */
    private function stationData(\SimpleXMLElement $station): array
    {
        return [
            'id' => (string) $station->Id,
            'name' => trim((string) $station->Name),
            'network' => trim((string) $station->Network->Name),
            'network_id' => trim((string) $station->Network->Id),
            'address' => trim((string) $station->Addr->Addrstring),
            'lat' => (float) $station->Addr->Location->Lat,
            'lon' => (float) $station->Addr->Location->Lon,
            'updated_at' => trim((string) $station->Updatedate),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $stations
     * @return ?array<string, mixed>
     */
    private function closestStation(array $stations, float $lat, float $lon, ?string $brand): ?array
    {
        $best = null;
        $bestScore = PHP_FLOAT_MAX;
        $brand = $brand !== null ? $this->normalize($brand) : '';

        foreach ($stations as $station) {
            $meters = $this->meters($lat, $lon, $station['lat'], $station['lon']);
            if ($meters > self::MATCH_METERS) {
                continue;
            }
            $sameBrand = $brand !== '' && str_contains($this->normalize($station['network'] . ' ' . $station['name']), $brand);
            $score = $meters + ($brand !== '' && ! $sameBrand ? 1000 : 0);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $station;
            }
        }

        return $best !== null && ($brand === '' || $bestScore < 1000) ? $best : null;
    }

    /**
     * @param  list<array<string, mixed>>  $prices
     * @return list<array<string, mixed>>
     */
    private function cheapestPerCategory(array $prices): array
    {
        $byCategory = [];
        foreach ($prices as $price) {
            $current = $byCategory[$price['category']] ?? null;
            if ($current === null || $price['price'] < $current['price']) {
                $byCategory[$price['category']] = $price;
            }
        }
        ksort($byCategory);

        return array_values($byCategory);
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower(Str::ascii($value))) ?? '';
    }

    private function meters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lon2 - $lon1) / 2) ** 2;

        return 6371000 * 2 * asin(min(1, sqrt($a)));
    }
}
