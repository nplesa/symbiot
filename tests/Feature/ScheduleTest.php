<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    /** @return array<string, Event> scheduled events keyed by their artisan command */
    private function events(): array
    {
        $events = [];
        foreach (app(Schedule::class)->events() as $event) {
            // e.g. '/usr/bin/php' 'artisan' osm:sync-romania  or  "C:\php\php.exe" "artisan" osm:sync-romania
            if (preg_match('/artisan[\'"]?\s+(.+)$/', (string) $event->command, $matches)) {
                $events[trim($matches[1], "'\" ")] = $event;
            }
        }

        return $events;
    }

    public function test_maintenance_and_sync_commands_are_scheduled(): void
    {
        $events = $this->events();

        foreach ([
            'osm:sync-romania' => '30 2 * * *',
            'transit:sync-osm-counties' => '0 3 * * 1',
            'transit:sync-catalog' => '30 3 * * *',
            'map:prune-tiles' => '15 4 * * *',
            'sanctum:prune-expired --hours=24' => '30 4 * * *',
            'queue:prune-failed --hours=168' => '45 4 * * *',
        ] as $command => $expression) {
            $this->assertArrayHasKey($command, $events, "{$command} is not scheduled.");
            $this->assertSame($expression, $events[$command]->expression, $command);
        }
    }

    public function test_scheduled_times_are_romanian_local_time(): void
    {
        foreach ($this->events() as $command => $event) {
            $this->assertSame('Europe/Bucharest', (string) $event->timezone, $command);
        }
    }

    public function test_no_overlapping_lock_outlives_a_crashed_run_for_a_day(): void
    {
        $events = $this->events();

        $this->assertSame(2, $events['transit:poll-realtime']->expiresAt);
        foreach ($events as $command => $event) {
            if ($event->withoutOverlapping) {
                $this->assertLessThan(1440, $event->expiresAt, "{$command} keeps its overlap lock for 24 h.");
            }
        }
    }
}
