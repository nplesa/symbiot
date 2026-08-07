<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trackings', function (Blueprint $table) {

            $table->enum('type', [
                'gps',
                'planned',
            ])
            ->default('gps')
            ->after('id');


            $table->string('source')
                ->default('device')
                ->after('type');


            $table->unsignedInteger('sequence')
                ->nullable()
                ->after('source');


            $table->index([
                'tracking_session_id',
                'sequence'
            ]);

        });
    }


    public function down(): void
    {
        Schema::table('trackings', function (Blueprint $table) {

            $table->dropIndex([
                'tracking_session_id',
                'sequence'
            ]);


            $table->dropColumn([
                'type',
                'source',
                'sequence',
            ]);

        });
    }
};