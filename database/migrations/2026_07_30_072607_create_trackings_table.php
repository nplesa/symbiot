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
        Schema::create('trackings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->nullable();
            $table->foreignId('tracking_session_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->decimal('latitude', 10, 7);

            $table->decimal('longitude', 10, 7);

            $table->double('accuracy')->nullable();

            $table->double('speed')->nullable();

            $table->double('heading')->nullable();

            $table->double('altitude')->nullable();

            $table->unsignedTinyInteger('battery')->nullable();

            $table->timestamp('tracked_at');

            $table->timestamps();

            $table->index([
                'tracking_session_id',
                'tracked_at',
            ]);

            $table->index('tracked_at');
        }); 
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trackings');
    }
};
