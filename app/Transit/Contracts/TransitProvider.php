<?php

namespace App\Transit\Contracts;

use App\Transit\Models\TransitFeed;

/**
 * A source of transit data. GTFS is the first implementation; other
 * sources (proprietary APIs, rail, flights) implement the same contract.
 */
interface TransitProvider
{
    public function key(): string;

    /** Imports the static network (stops, routes, trips, schedules, shapes). */
    public function importStatic(TransitFeed $feed, bool $force = false): bool;
}
