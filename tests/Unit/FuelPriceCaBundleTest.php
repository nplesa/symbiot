<?php

namespace Tests\Unit;

use App\Services\FuelPriceService;
use Tests\TestCase;

class FuelPriceCaBundleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'symbiot-ca-test-' . uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir . '/system.pem', "SYSTEM\n");
        // A regular file used as a "directory": anything below it cannot be created.
        file_put_contents($this->dir . '/blocked', 'x');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    /** @param list<string> $candidates */
    private function service(array $candidates): FuelPriceService
    {
        return new class($this->dir . '/system.pem', $candidates) extends FuelPriceService
        {
            /** @param list<string> $candidates */
            public function __construct(private string $system, private array $candidates) {}

            public function bundle(): string|bool
            {
                return $this->caBundle();
            }

            protected function caBundleCandidates(): array
            {
                return $this->candidates;
            }

            protected function systemCertificateFile(): ?string
            {
                return $this->system;
            }
        };
    }

    public function test_falls_back_to_one_reusable_temp_file_when_storage_is_not_writable(): void
    {
        $fallback = $this->dir . '/fallback.pem';
        $service = $this->service([$this->dir . '/blocked/fuel-prices-ca.pem', $fallback]);

        $this->assertSame($fallback, $service->bundle());
        $this->assertSame($fallback, $service->bundle());
        $this->assertStringContainsString('SYSTEM', file_get_contents($fallback));
        // Only the fixed fallback file was created, no tempnam() leftovers.
        $this->assertCount(3, glob($this->dir . '/*'));
    }

    public function test_uses_default_verification_when_no_location_is_writable(): void
    {
        $service = $this->service([$this->dir . '/blocked/a.pem', $this->dir . '/blocked/b.pem']);

        $this->assertTrue($service->bundle());
    }
}
