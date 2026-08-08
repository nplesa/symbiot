<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reprezintă un punct de tracking.
 *
 * Poate fi:
 * - gps: punct primit de la dispozitiv;
 * - planned: punct al unui traseu creat pe hartă.
 *
 * @property int $id
 * @property int $tracking_session_id
 * @property string $type
 * @property string $source
 * @property int|null $sequence
 * @property float $latitude
 * @property float $longitude
 * @property float|null $accuracy
 * @property float|null $speed
 * @property float|null $heading
 * @property float|null $altitude
 * @property \Carbon\Carbon|null $tracked_at
 */
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


    /**
     * Sesiunea de tracking.
     *
     * @return BelongsTo<TrackingSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(
            TrackingSession::class,
            'tracking_session_id'
        );
    }


    /**
     * Selectează punctele GPS.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeGps(Builder $query): Builder
    {
        return $query->where('type', 'gps');
    }


    /**
     * Selectează punctele unui traseu planificat.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopePlanned(Builder $query): Builder
    {
        return $query->where('type', 'planned');
    }


    /**
     * Sortează punctele traseului.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderByRaw('sequence IS NULL')
            ->orderBy('sequence')
            ->orderBy('tracked_at')
            ->orderBy('id');
    }


    /**
     * Punct venit de la telefon.
     */
    public function isGps(): bool
    {
        return $this->type === 'gps';
    }


    /**
     * Punct din traseu planificat.
     */
    public function isPlanned(): bool
    {
        return $this->type === 'planned';
    }


    /**
     * Punct creat manual pe hartă.
     */
    public function isManual(): bool
    {
        return $this->source === 'manual';
    }
}