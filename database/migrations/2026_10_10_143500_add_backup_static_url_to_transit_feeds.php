<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transit_feeds', function (Blueprint $table): void {
            $table->string('backup_static_url', 1000)->nullable()->after('static_url');
        });
    }

    public function down(): void
    {
        Schema::table('transit_feeds', function (Blueprint $table): void {
            $table->dropColumn('backup_static_url');
        });
    }
};
