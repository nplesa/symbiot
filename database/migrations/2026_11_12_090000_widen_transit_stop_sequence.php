<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Some GTFS feeds number stops above 65535; with smallint, INSERT IGNORE silently clamped
 * them and broke the stop order. Note: this rewrites transit_stop_times (can take minutes
 * on a large table and blocks writes to it meanwhile).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transit_stop_times', function (Blueprint $table): void {
            $table->unsignedInteger('stop_sequence')->change();
        });
    }

    public function down(): void
    {
        DB::table('transit_stop_times')
            ->where('stop_sequence', '>', 65535)
            ->update(['stop_sequence' => 65535]);

        Schema::table('transit_stop_times', function (Blueprint $table): void {
            $table->unsignedSmallInteger('stop_sequence')->change();
        });
    }
};
