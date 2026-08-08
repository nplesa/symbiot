<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateDevices = DB::table('devices')
            ->select('uuid')
            ->whereNotNull('uuid')
            ->groupBy('uuid')
            ->havingRaw('COUNT(*) > 1')
            ->limit(10)
            ->pluck('uuid')
            ->all();

        if ($duplicateDevices !== []) {
            throw new RuntimeException(
                'Cannot enforce globally unique device UUIDs. Duplicate UUIDs exist: ' . implode(', ', $duplicateDevices)
            );
        }

        $duplicateSequences = DB::table('trackings')
            ->select('tracking_session_id', 'sequence')
            ->whereNotNull('sequence')
            ->groupBy('tracking_session_id', 'sequence')
            ->havingRaw('COUNT(*) > 1')
            ->limit(10)
            ->get();

        if ($duplicateSequences->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot enforce unique tracking point sequences. Duplicate sequences exist for tracking sessions.'
            );
        }

        Schema::table('devices', function (Blueprint $table): void {
            // MariaDB may use the composite unique index to satisfy the user_id FK.
            // Keep a standalone user_id index before removing that unique constraint.
            $table->index('user_id');
        });

        Schema::table('devices', function (Blueprint $table): void {
            $table->dropUnique('devices_user_id_uuid_unique');
            $table->unique('uuid');
            $table->index(['user_id', 'id']);
        });

        Schema::table('tracking_sessions', function (Blueprint $table): void {
            $table->index(['user_id', 'status', 'started_at']);
        });

        Schema::table('trackings', function (Blueprint $table): void {
            // Preserve an index usable by the tracking_session_id FK.
            $table->index('tracking_session_id');
        });

        Schema::table('trackings', function (Blueprint $table): void {
            $table->dropIndex('trackings_tracking_session_id_sequence_index');
            $table->unique(['tracking_session_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::table('trackings', function (Blueprint $table): void {
            $table->dropUnique('trackings_tracking_session_id_sequence_unique');
            $table->index(['tracking_session_id', 'sequence']);
        });

        Schema::table('tracking_sessions', function (Blueprint $table): void {
            $table->dropIndex('tracking_sessions_user_id_status_started_at_index');
        });

        Schema::table('devices', function (Blueprint $table): void {
            $table->dropIndex('devices_user_id_id_index');
            $table->dropUnique('devices_uuid_unique');
            $table->unique(['user_id', 'uuid']);
            $table->dropIndex('devices_user_id_index');
        });
    }
};
