<?php

namespace Tests\Feature;

use App\Exceptions\UserFacingException;
use App\Services\RouteImportService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class RouteImportServiceTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function gpxFile(int $points): string
    {
        $trackPoints = '';
        for ($i = 0; $i < $points; $i++) {
            $trackPoints .= sprintf('<trkpt lat="%.6F" lon="25.600000"></trkpt>', 45.0 + $i / 10000);
        }
        $path = tempnam(sys_get_temp_dir(), 'gpx');
        file_put_contents($path, '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1"><trk><trkseg>'
            . $trackPoints . '</trkseg></trk></gpx>');
        $this->files[] = $path;

        return $path;
    }

    public function test_elevations_are_fetched_in_parallel_chunks_and_mapped_to_the_right_points(): void
    {
        config(['services.geoapify.key' => 'test-key']);
        Http::fake(['api.geoapify.com/v1/geodata/elevation*' => function ($request) {
            // Elevation derived from latitude, so a mix-up between chunks would be visible.
            return Http::response(['results' => array_map(
                fn (array $location): array => ['elevation' => round(($location[1] - 45.0) * 10000)],
                $request['locations']
            )]);
        }]);

        $parsed = app(RouteImportService::class)->import($this->gpxFile(2500), 'gpx');

        Http::assertSentCount(3);
        $this->assertSame(0.0, $parsed['points'][0]['elevation']);
        $this->assertSame(1500.0, $parsed['points'][1500]['elevation']);
        $this->assertSame(2499.0, $parsed['points'][2499]['elevation']);
        $this->assertSame(2499.0, $parsed['geometry']['coordinates'][2499][2]);
    }

    public function test_a_failed_elevation_chunk_keeps_the_import_without_elevations(): void
    {
        config(['services.geoapify.key' => 'test-key']);
        $calls = 0;
        Http::fake(['api.geoapify.com/v1/geodata/elevation*' => function ($request) use (&$calls) {
            $calls++;

            return $calls === 2
                ? Http::response('rate limited', 429)
                : Http::response(['results' => array_fill(0, count($request['locations']), ['elevation' => 100])]);
        }]);

        $parsed = app(RouteImportService::class)->import($this->gpxFile(2500), 'gpx');

        $this->assertCount(2500, $parsed['points']);
        $this->assertNull($parsed['points'][0]['elevation']);
        $this->assertNull($parsed['elevation_gain']);
    }

    public function test_kmz_archives_over_20_mb_uncompressed_are_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kmz');
        $this->files[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        // Compresses to a few KB, but would take ~21 MB (and much more as a DOM) once opened.
        $zip->addFromString('doc.kml', '<kml>' . str_repeat(' ', 21 * 1024 * 1024) . '</kml>');
        $zip->close();

        $this->expectException(UserFacingException::class);
        $this->expectExceptionMessage('Arhiva KMZ este prea mare după decomprimare.');

        app(RouteImportService::class)->import($path, 'kmz');
    }
}
