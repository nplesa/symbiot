<?php

namespace Tests\Feature\Transit;

use App\Jobs\ImportTransitFeedJob;
use App\Models\User;
use App\Transit\Models\TransitFeed;
use App\Transit\Services\FeedCatalogSync;
use App\Transit\Services\FeedDiscovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Real catalog bounding boxes for Romanian cities, to make sure each area picks the right feed. */
class RomanianCitiesCoverageTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'id,data_type,entity_type,location.country_code,location.subdivision_name,location.municipality,provider,is_official,name,note,feed_contact_email,static_reference,urls.direct_download,urls.authentication_type,urls.authentication_info,urls.api_key_parameter_name,urls.latest,urls.license,location.bounding_box.minimum_latitude,location.bounding_box.maximum_latitude,location.bounding_box.minimum_longitude,location.bounding_box.maximum_longitude,location.bounding_box.extracted_on,status,features,redirect.id,redirect.comment';

    protected function setUp(): void
    {
        parent::setUp();

        $rows = [
            'mdb-tpbi,gtfs,,RO,Bucharest,Bucharest,TPBI Bucuresti,True,,,,,https://feeds.test/tpbi.zip,0,,,,,44.2566,44.7505,25.8700,26.3861,2026-01-01,active,,,',
            'mdb-ploiesti,gtfs,,RO,Prahova,Prahova,Ploiesti Express,True,,,,,https://feeds.test/ploiesti.zip,0,,,,,44.9031,44.9744,25.9357,26.0904,2026-01-01,active,,,',
            'mdb-brasov,gtfs,,RO,Brasov,Brasov,RATBV Brasov,True,,,,,https://feeds.test/brasov.zip,0,,,,,45.4631,45.8374,25.3137,26.0407,2026-01-01,active,,,',
            'mdb-targoviste,gtfs,,RO,Dambovita,Dambovita,SPM Targoviste,True,,,,,https://feeds.test/targoviste.zip,0,,,,,44.8433,45.0123,25.2860,25.6010,2026-01-01,active,,,',
            'mdb-rail,gtfs,,RO,Alba,Alba,Romanian Railway Operators,True,,,,,https://feeds.test/rail.zip,0,,,,,43.7719,47.9845,20.4048,28.7900,2026-01-01,active,,,',
            'mdb-de,gtfs,,DE,,,Public Transport Germany,True,,,,,https://feeds.test/de.zip,0,,,,,39.0843,60.1512,0.1494,31.2684,2026-01-01,active,,,',
        ];
        $path = tempnam(sys_get_temp_dir(), 'cat');
        file_put_contents($path, self::HEADER . "\n" . implode("\n", $rows) . "\n");
        app(FeedCatalogSync::class)->importCsv($path);
        unlink($path);
    }

    /** @return array<string, array{0: float, 1: float, 2: string}> */
    public static function cities(): array
    {
        return [
            'Bucuresti' => [44.4268, 26.1025, 'TPBI Bucuresti'],
            'Ploiesti' => [44.9419, 26.0230, 'Ploiesti Express'],
            'Brasov' => [45.6427, 25.5887, 'RATBV Brasov'],
            'Targoviste (Dambovita)' => [44.9256, 25.4567, 'SPM Targoviste'],
        ];
    }

    #[DataProvider('cities')]
    public function test_each_city_gets_its_own_feed_and_the_national_railway(float $lat, float $lon, string $expected): void
    {
        $names = $this->coverageNames($lat, $lon);

        $this->assertSame([$expected, 'Romanian Railway Operators'], $names);
        $this->assertNotContains('Public Transport Germany', $names);
    }

    public function test_requesting_a_city_queues_only_that_city_feed_and_the_railway(): void
    {
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/transit/coverage?lat=44.9256&lon=25.4567')
            ->assertOk()
            ->assertJsonCount(2, 'feeds')
            ->assertJsonPath('feeds.0.name', 'SPM Targoviste')
            ->assertJsonPath('preparing', true);

        Queue::assertPushed(ImportTransitFeedJob::class, 2);
        $this->assertSame('pending', TransitFeed::where('name', 'Ploiesti Express')->value('import_status'));
        $this->assertSame('pending', TransitFeed::where('name', 'RATBV Brasov')->value('import_status'));
    }

    public function test_towns_outside_every_city_feed_only_get_the_railway(): void
    {
        // Moreni (Dambovita county) and Fagaras (Brasov county) are outside the city feeds.
        $this->assertSame(['Romanian Railway Operators'], $this->coverageNames(44.9833, 25.6500));
        $this->assertSame(['Romanian Railway Operators'], $this->coverageNames(45.8427, 24.9710));
    }

    /** @return list<string> */
    private function coverageNames(float $lat, float $lon): array
    {
        return app(FeedDiscovery::class)->coverageFor($lat, $lon)->pluck('name')->all();
    }
}
