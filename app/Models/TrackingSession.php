<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reprezintă o sesiune de tracking.
 *
 * Poate fi:
 * - gps: traseu înregistrat de dispozitiv;
 * - planned: traseu creat manual pe hartă.
 *
 * @property int $id
 * @property string $type
 * @property string|null $name
 * @property int|null $user_id
 * @property int|null $device_id
 * @property string $status
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $ended_at
 * @property int|null $duration
 * @property float|null $distance
 * @property array|null $route_geojson
 * @property \Carbon\Carbon|null $planned_at
 * @property \Carbon\Carbon|null $processed_at
 */
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


    /**
     * Utilizatorul asociat sesiunii.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }


    /**
     * Dispozitivul asociat sesiunii.
     *
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }


    /**
     * Punctele de tracking ale sesiunii.
     *
     * @return HasMany<Tracking, $this>
     */
    public function trackings(): HasMany
    {
        return $this->hasMany(Tracking::class);
    }


    /**
     * Verifică dacă este traseu planificat.
     */
    public function isPlanned(): bool
    {
        return $this->type === 'planned';
    }


    /**
     * Verifică dacă este tracking GPS.
     */
    public function isGps(): bool
    {
        return $this->type === 'gps';
    }
}