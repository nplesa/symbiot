<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tracking extends Model
{
    protected $fillable = [
        'tracking_session_id',

        'type',
        'source',
        'provider',
        'sequence',

        'latitude',
        'longitude',

        'accuracy',
        'speed',
        'heading',
        'altitude',
        'battery',

        'tracked_at',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',

        'accuracy' => 'float',
        'speed' => 'float',
        'heading' => 'float',
        'altitude' => 'float',
        'battery' => 'integer',

        'sequence' => 'integer',

        'tracked_at' => 'datetime',
    ];

    /** @return BelongsTo<TrackingSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(
            TrackingSession::class,
            'tracking_session_id'
        );
    }

    /**
     * @param  Builder<Tracking>  $query
     * @return Builder<Tracking>
     */
    public function scopeGps(Builder $query): Builder
    {
        return $query->where('type', 'gps');
    }

    /**
     * @param  Builder<Tracking>  $query
     * @return Builder<Tracking>
     */
    public function scopePlanned(Builder $query): Builder
    {
        return $query->where('type', 'planned');
    }

    /**
     * @param  Builder<Tracking>  $query
     * @return Builder<Tracking>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderByRaw('sequence IS NULL')
            ->orderBy('sequence')
            ->orderBy('tracked_at')
            ->orderBy('id');
    }

    public function isGps(): bool
    {
        return $this->type === 'gps';
    }

    public function isPlanned(): bool
    {
        return $this->type === 'planned';
    }

    public function isManual(): bool
    {
        return $this->source === 'manual';
    }
}
