<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_sessions', function (Blueprint $table) {

            $table->enum('type', [
                'gps',
                'planned',
            ])
            ->default('gps')
            ->after('id');


            $table->string('source')
                ->default('device')
                ->after('type');


            $table->string('name')
                ->nullable()
                ->after('source');


            $table->timestamp('planned_at')
                ->nullable()
                ->after('ended_at');

        });
    }


    public function down(): void
    {
        Schema::table('tracking_sessions', function (Blueprint $table) {

            $table->dropColumn([
                'type',
                'source',
                'name',
                'planned_at',
            ]);

        });
    }
};