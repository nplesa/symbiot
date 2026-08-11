<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    
    public function up(): void
    {
        Schema::create('transport_points', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->nullable(); 
            $table->decimal('lat', 10, 7);
            $table->decimal('lon', 10, 7);
            $table->timestamps();
        });
    }

    
    public function down(): void
    {
        Schema::dropIfExists('transport_points');
    }
};
