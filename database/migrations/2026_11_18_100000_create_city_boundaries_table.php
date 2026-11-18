<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * City boundaries ("Whole city"), fetched once from Geoapify and then served from here for every user
 * and every point inside the city. The bounding box finds the candidates; the polygon decides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('city_boundaries', function (Blueprint $table): void {
            $table->id();
            $table->string('geoapify_id')->nullable()->unique();
            $table->string('name');
            $table->string('city')->nullable();
            $table->string('country_code', 2)->nullable();
            // The GeoJSON feature exactly as returned to the page.
            $table->json('feature');
            $table->decimal('min_lat', 9, 6);
            $table->decimal('max_lat', 9, 6);
            $table->decimal('min_lon', 9, 6);
            $table->decimal('max_lon', 9, 6);
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->index(['min_lat', 'max_lat']);
            $table->index(['min_lon', 'max_lon']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_boundaries');
    }
};
