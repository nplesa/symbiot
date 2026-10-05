<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transit_feeds', function (Blueprint $table): void {
            $table->longText('boundary')->nullable()->after('max_lon');
        });
    }

    public function down(): void
    {
        Schema::table('transit_feeds', function (Blueprint $table): void {
            $table->dropColumn('boundary');
        });
    }
};
