<?php

namespace App\Transit\Models;

use Illuminate\Database\Eloquent\Model;

class TransitFeed extends Model
{
    protected $fillable = [
        'slug', 'name', 'country_code', 'city', 'provider', 'static_url', 'backup_static_url',
        'vehicle_positions_url', 'trip_updates_url', 'alerts_url',
        'source_reference', 'license', 'active', 'static_hash',
        'import_status', 'import_error', 'last_imported_at',
        'min_lat', 'max_lat', 'min_lon', 'max_lon', 'boundary', 'is_official',
        'requested_at', 'catalog_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'boundary' => 'array',
            'is_official' => 'boolean',
            'last_imported_at' => 'datetime',
            'requested_at' => 'datetime',
            'catalog_synced_at' => 'datetime',
        ];
    }
}
