<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrackingSession extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'device_id',
        'status',
        'started_at',
        'ended_at',
        'duration',
        'distance',
        'route_geojson',
        'processed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'processed_at' => 'datetime',
        'duration' => 'integer',
        'distance' => 'float',
        'route_geojson' => 'array',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return HasMany<Tracking, $this>
     */
    public function trackings(): HasMany
    {
        return $this->hasMany(Tracking::class);
    }
}