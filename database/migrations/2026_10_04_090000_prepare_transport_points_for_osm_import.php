<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_points', function (Blueprint $table) {
            $table->string('source')->default('manual')->after('type');
            $table->string('osm_type', 8)->nullable()->after('source');
            $table->string('osm_id', 32)->nullable()->after('osm_type');
            $table->json('tags')->nullable()->after('osm_id');
            $table->timestamp('imported_at')->nullable()->after('tags');
            $table->unique(['source', 'osm_type', 'osm_id'], 'transport_points_source_osm_unique');
            $table->index(['type', 'lat', 'lon'], 'transport_points_type_location_index');
        });

        Schema::create('osm_import_states', function (Blueprint $table) {
            $table->string('region', 32)->primary();
            $table->unsignedBigInteger('replication_sequence');
            $table->timestamp('snapshot_at')->nullable();
            $table->unsignedBigInteger('records_count');
            $table->timestamp('last_synced_at');
        });

        Schema::create('osm_transport_point_staging', function (Blueprint $table) {
            $table->uuid('run_id');
            $table->string('osm_type', 8);
            $table->string('osm_id', 32);
            $table->string('name');
            $table->string('type', 64);
            $table->decimal('lat', 10, 7);
            $table->decimal('lon', 10, 7);
            $table->json('tags');
            $table->timestamp('imported_at');
            $table->unique(['run_id', 'osm_type', 'osm_id'], 'osm_transport_staging_identity_unique');
            $table->index(['run_id', 'type'], 'osm_transport_staging_run_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('osm_transport_point_staging');
        Schema::dropIfExists('osm_import_states');

        Schema::table('transport_points', function (Blueprint $table) {
            $table->dropIndex('transport_points_type_location_index');
            $table->dropUnique('transport_points_source_osm_unique');
            $table->dropColumn(['source', 'osm_type', 'osm_id', 'tags', 'imported_at']);
        });
    }
};
