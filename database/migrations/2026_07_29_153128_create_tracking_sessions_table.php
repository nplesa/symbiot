<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tracking_sessions', function (Blueprint $table) {

            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('device_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->timestamp('started_at');

            $table->timestamp('ended_at')->nullable();

            $table->timestamp('processed_at')->nullable();

            $table->enum('status', [
                'active',
                'completed',
                'cancelled',
            ])->default('active');

            $table->decimal('distance', 10, 2)->default(0);

            $table->unsignedInteger('duration')->default(0);

            $table->json('route_geojson')->nullable();

            $table->index([
                'user_id',
                'started_at',
            ]);

            $table->index([
                'user_id',
                'status',
            ]);

            $table->index('device_id');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tracking_sessions');
    }
};
