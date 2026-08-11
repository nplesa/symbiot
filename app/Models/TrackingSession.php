<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


class TrackingSession extends Model
{
    public $timestamps = false;


    protected $fillable = [
        'type',
        'source',
        'name',

        'user_id',
        'device_id',

        'status',

        'started_at',
        'ended_at',

        'duration',
        'distance',

        'route_geojson',

        'planned_at',
        'processed_at',
    ];


    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'planned_at' => 'datetime',
        'processed_at' => 'datetime',

        'duration' => 'integer',
        'distance' => 'float',

        'route_geojson' => 'array',
    ];


    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }


    
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }


    
    public function trackings(): HasMany
    {
        return $this->hasMany(Tracking::class);
    }


    
    public function isPlanned(): bool
    {
        return $this->type === 'planned';
    }


    
    public function isGps(): bool
    {
        return $this->type === 'gps';
    }
}