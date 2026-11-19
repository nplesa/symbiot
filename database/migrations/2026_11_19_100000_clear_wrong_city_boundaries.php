<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Whole city" stored wrong boundaries before 2026-11-19: Brașov's county (counties carry their seat's
 * name), a district for Berlin (Mitte, whose "city" is Berlin), a metropolitan area as fallback.
 * They are dropped; each city's boundary is fetched again, correctly, the first time it is asked for.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('city_boundaries')) {
            DB::table('city_boundaries')->delete();
        }
    }

    public function down(): void
    {
        // Nothing to restore: the boundaries are fetched again when needed.
    }
};
