<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transit_feeds', function (Blueprint $table): void {
            $table->decimal('min_lat', 10, 7)->nullable();
            $table->decimal('max_lat', 10, 7)->nullable();
            $table->decimal('min_lon', 10, 7)->nullable();
            $table->decimal('max_lon', 10, 7)->nullable();
            $table->boolean('is_official')->default(false);
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('catalog_synced_at')->nullable();
            $table->index(['min_lat', 'max_lat', 'min_lon', 'max_lon'], 'transit_feeds_bbox_index');
            $table->index('source_reference');
        });
    }

    public function down(): void
    {
        Schema::table('transit_feeds', function (Blueprint $table): void {
            $table->dropIndex('transit_feeds_bbox_index');
            $table->dropIndex(['source_reference']);
            $table->dropColumn([
                'min_lat', 'max_lat', 'min_lon', 'max_lon',
                'is_official', 'requested_at', 'catalog_synced_at',
            ]);
        });
    }
};
