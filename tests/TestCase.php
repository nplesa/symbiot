<?php

namespace Tests;

use App\Support\PublicUrlGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerSqliteMathFunctions();

        // No real DNS in tests: fake hosts like feeds.test resolve to a public documentation address.
        $this->app->instance(PublicUrlGuard::class, new PublicUrlGuard(fn (string $host): array => ['203.0.113.10']));
    }

    /**
     * Production runs on MariaDB; the test suite runs on SQLite, which may be built
     * without math functions and never has LEAST(). Register the ones the app's raw SQL uses.
     */
    private function registerSqliteMathFunctions(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $pdo = DB::connection()->getPdo();
        $functions = [
            'LEAST' => [fn (...$values) => min($values), -1],
            'GREATEST' => [fn (...$values) => max($values), -1],
            'SQRT' => [fn ($value) => $value === null ? null : sqrt((float) $value), 1],
            'POWER' => [fn ($base, $exponent) => $base === null || $exponent === null ? null : ((float) $base) ** ((float) $exponent), 2],
            'ASIN' => [fn ($value) => $value === null ? null : asin((float) $value), 1],
            'SIN' => [fn ($value) => $value === null ? null : sin((float) $value), 1],
            'COS' => [fn ($value) => $value === null ? null : cos((float) $value), 1],
            'RADIANS' => [fn ($value) => $value === null ? null : deg2rad((float) $value), 1],
        ];

        foreach ($functions as $name => [$callback, $arguments]) {
            method_exists($pdo, 'createFunction')
                ? $pdo->createFunction($name, $callback, $arguments)
                : $pdo->sqliteCreateFunction($name, $callback, $arguments);
        }
    }
}
