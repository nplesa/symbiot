<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transit_frequencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feed_id')->constrained('transit_feeds')->cascadeOnDelete();
            $table->string('trip_id', 150);
            $table->unsignedInteger('start_seconds');
            $table->unsignedInteger('end_seconds');
            $table->unsignedInteger('headway_secs');
            $table->unsignedTinyInteger('exact_times')->default(0);
            $table->index(['feed_id', 'trip_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transit_frequencies');
    }
};
