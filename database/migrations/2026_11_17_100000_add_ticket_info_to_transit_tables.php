<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket links for GTFS operators: agency.txt agency_fare_url, and the payment media a feed
 * declares in fare_media.txt (GTFS-Fares v2: contactless bank cards, mobile apps, ...).
 * Both tables are small; feeds fill them on their next import.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transit_agencies', function (Blueprint $table): void {
            $table->string('fare_url', 500)->nullable()->after('url');
        });

        Schema::create('transit_fare_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feed_id')->constrained('transit_feeds')->cascadeOnDelete();
            $table->string('fare_media_id', 100);
            $table->string('name')->nullable();
            // 0 none (paper), 1 physical ticket, 2 transit card, 3 contactless bank card (cEMV), 4 mobile app
            $table->unsignedTinyInteger('type');
            $table->unique(['feed_id', 'fare_media_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transit_fare_media');
        Schema::table('transit_agencies', function (Blueprint $table): void {
            $table->dropColumn('fare_url');
        });
    }
};
