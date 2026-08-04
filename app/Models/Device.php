<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    protected $fillable = [
        'user_id',
        'device_name',
        'push_token',
        'uuid',
        'platform',
        'manufacturer',
        'model',
        'os_version',
        'app_version',
        'last_seen',
        'battery',
    ];

    protected $casts = [
        'last_seen' => 'datetime',
        'battery' => 'integer',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<TrackingSession, $this>
     */
    public function trackingSessions(): HasMany
    {
        return $this->hasMany(TrackingSession::class);
    }

    /**
     * @return HasMany<TrackingSession, $this>
     */
    public function activeSession(): HasMany
    {
        return $this->hasMany(TrackingSession::class)
            ->whereNull('ended_at');
    }

    public function touchLastSeen(?int $battery = null): void
    {
        $this->last_seen = now();

        if (! is_null($battery)) {
            $this->battery = $battery;
        }

        $this->save();
    }
}
