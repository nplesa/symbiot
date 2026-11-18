<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GTFS imports write the new data under a hidden "shadow" feed row in small committed batches and
 * then switch the public feed to it (data_feed_id), instead of one transaction over millions of rows.
 * Only the small transit_feeds table changes; the data tables keep their feed_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transit_feeds', function (Blueprint $table): void {
            // Feed row holding the data currently served for this feed; null = the feed itself.
            $table->foreignId('data_feed_id')->nullable()->after('id')->constrained('transit_feeds')->nullOnDelete();
            // Set on shadow rows: the public feed they belong to (deleted with it).
            $table->foreignId('shadow_of')->nullable()->after('data_feed_id')->constrained('transit_feeds')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transit_feeds', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('shadow_of');
            $table->dropConstrainedForeignId('data_feed_id');
        });
    }
};
