<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('routes')) {
            Schema::create('routes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('name')->nullable();
                $table->string('format', 30)->default('geojson');
                $table->json('geometry');
                $table->double('distance')->default(0);
                $table->unsignedInteger('duration')->default(0);
                $table->double('elevation_gain')->nullable();
                $table->double('elevation_loss')->nullable();
                $table->string('source', 50)->nullable();
                $table->string('source_url', 2048)->nullable();
                $table->timestamps();
                $table->index(['user_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('route_points')) {
            Schema::create('route_points', function (Blueprint $table) {
                $table->id();
                $table->foreignId('route_id')->constrained('routes')->cascadeOnDelete();
                $table->unsignedInteger('sequence');
                $table->double('latitude');
                $table->double('longitude');
                $table->double('elevation')->nullable();
                $table->timestamps();
                $table->unique(['route_id', 'sequence']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('route_points');
        Schema::dropIfExists('routes');
    }
};
