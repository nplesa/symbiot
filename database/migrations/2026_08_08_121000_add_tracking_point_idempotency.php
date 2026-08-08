<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('trackings')
            ->select('tracking_session_id', 'tracked_at', 'latitude', 'longitude', DB::raw('COUNT(*) as total'))
            ->groupBy('tracking_session_id', 'tracked_at', 'latitude', 'longitude')
            ->having('total', '>', 1)
            ->exists();

        if ($duplicates) {
            throw new RuntimeException(
                'Cannot enforce tracking point idempotency: duplicate session/timestamp/coordinate points exist.'
            );
        }

        Schema::table('trackings', function (Blueprint $table): void {
            $table->unique(
                ['tracking_session_id', 'tracked_at', 'latitude', 'longitude'],
                'trackings_session_time_coordinate_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('trackings', function (Blueprint $table): void {
            $table->dropUnique('trackings_session_time_coordinate_unique');
        });
    }
};
