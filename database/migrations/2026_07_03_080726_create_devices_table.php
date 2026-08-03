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
        Schema::create('devices', function (Blueprint $table) {

            $table->id();

            $table->string('device_name')->nullable();
            $table->string('push_token')->nullable();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->uuid('uuid');

            $table->unique([
                'user_id',
                'uuid'
            ]);

            $table->string('platform', 20);          // android / ios
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();

            $table->string('os_version')->nullable();
            $table->string('app_version')->nullable();

            $table->timestamp('last_seen')->nullable();

            $table->unsignedTinyInteger('battery')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
