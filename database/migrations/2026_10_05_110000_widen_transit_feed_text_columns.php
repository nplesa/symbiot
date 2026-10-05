<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transit_feeds', function (Blueprint $table): void {
            $table->dropIndex(['city']);
        });

        Schema::table('transit_feeds', function (Blueprint $table): void {
            $table->text('name')->change();
            $table->text('city')->nullable()->change();
            $table->text('license')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('transit_feeds', function (Blueprint $table): void {
            $table->string('name')->change();
            $table->string('city')->nullable()->change();
            $table->string('license')->nullable()->change();
            $table->index('city');
        });
    }
};
