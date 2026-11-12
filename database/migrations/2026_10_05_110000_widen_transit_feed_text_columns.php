<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        // Strict MariaDB rejects values longer than the old varchar(255); shorten them explicitly.
        foreach (['name', 'city', 'license'] as $column) {
            DB::table('transit_feeds')
                ->whereRaw("LENGTH({$column}) > 255")
                ->update([$column => DB::raw("SUBSTR({$column}, 1, 255)")]);
        }

        Schema::table('transit_feeds', function (Blueprint $table): void {
            $table->string('name')->change();
            $table->string('city')->nullable()->change();
            $table->string('license')->nullable()->change();
            $table->index('city');
        });
    }
};
