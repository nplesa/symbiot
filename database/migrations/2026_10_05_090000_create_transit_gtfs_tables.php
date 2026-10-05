<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transit_feeds', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('country_code', 2)->nullable()->index();
            $table->string('city')->nullable()->index();
            $table->string('provider')->default('gtfs');
            $table->string('static_url', 1000)->nullable();
            $table->string('vehicle_positions_url', 1000)->nullable();
            $table->string('trip_updates_url', 1000)->nullable();
            $table->string('alerts_url', 1000)->nullable();
            $table->string('source_reference')->nullable();
            $table->string('license')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->string('static_hash', 64)->nullable();
            $table->string('import_status', 20)->default('pending');
            $table->text('import_error')->nullable();
            $table->timestamp('last_imported_at')->nullable();
            $table->timestamps();
        });

        Schema::create('transit_agencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feed_id')->constrained('transit_feeds')->cascadeOnDelete();
            $table->string('agency_id', 100);
            $table->string('name');
            $table->string('url', 500)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->unique(['feed_id', 'agency_id']);
        });

        Schema::create('transit_stops', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feed_id')->constrained('transit_feeds')->cascadeOnDelete();
            $table->string('stop_id', 100);
            $table->string('code', 50)->nullable();
            $table->string('name');
            $table->decimal('lat', 10, 7);
            $table->decimal('lon', 10, 7);
            $table->unsignedTinyInteger('location_type')->default(0);
            $table->string('parent_station', 100)->nullable();
            $table->unsignedTinyInteger('wheelchair_boarding')->nullable();
            $table->unique(['feed_id', 'stop_id']);
            $table->index(['lat', 'lon']);
        });

        Schema::create('transit_routes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feed_id')->constrained('transit_feeds')->cascadeOnDelete();
            $table->string('route_id', 100);
            $table->string('agency_id', 100)->nullable();
            $table->string('short_name', 100)->nullable();
            $table->string('long_name', 255)->nullable();
            $table->unsignedSmallInteger('route_type');
            $table->string('color', 6)->nullable();
            $table->string('text_color', 6)->nullable();
            $table->unique(['feed_id', 'route_id']);
        });

        Schema::create('transit_trips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feed_id')->constrained('transit_feeds')->cascadeOnDelete();
            $table->string('trip_id', 150);
            $table->string('route_id', 100);
            $table->string('service_id', 100);
            $table->string('headsign')->nullable();
            $table->unsignedTinyInteger('direction_id')->nullable();
            $table->string('shape_id', 100)->nullable();
            $table->unique(['feed_id', 'trip_id']);
            $table->index(['feed_id', 'route_id']);
        });

        Schema::create('transit_stop_times', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feed_id')->constrained('transit_feeds')->cascadeOnDelete();
            $table->string('trip_id', 150);
            $table->string('stop_id', 100);
            $table->unsignedSmallInteger('stop_sequence');
            $table->unsignedInteger('arrival_seconds')->nullable();
            $table->unsignedInteger('departure_seconds')->nullable();
            $table->index(['feed_id', 'trip_id', 'stop_sequence']);
            $table->index(['feed_id', 'stop_id']);
        });

        Schema::create('transit_calendars', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feed_id')->constrained('transit_feeds')->cascadeOnDelete();
            $table->string('service_id', 100);
            $table->unsignedTinyInteger('days_mask');
            $table->date('start_date');
            $table->date('end_date');
            $table->unique(['feed_id', 'service_id']);
        });

        Schema::create('transit_calendar_dates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feed_id')->constrained('transit_feeds')->cascadeOnDelete();
            $table->string('service_id', 100);
            $table->date('date');
            $table->unsignedTinyInteger('exception_type');
            $table->index(['feed_id', 'service_id', 'date']);
        });

        Schema::create('transit_shapes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feed_id')->constrained('transit_feeds')->cascadeOnDelete();
            $table->string('shape_id', 100);
            $table->longText('points');
            $table->unique(['feed_id', 'shape_id']);
        });
    }

    public function down(): void
    {
        foreach ([
            'transit_shapes', 'transit_calendar_dates', 'transit_calendars', 'transit_stop_times',
            'transit_trips', 'transit_routes', 'transit_stops', 'transit_agencies', 'transit_feeds',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
