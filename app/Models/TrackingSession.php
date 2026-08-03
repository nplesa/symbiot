<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrackingSession extends Model
{
    protected $fillable = [
        'user_id',
        'started_at',
        'ended_at',
        'processed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'processed_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Device, self>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return HasMany<Tracking, self>
     */
    public function trackings(): HasMany
    {
        return $this->hasMany(Tracking::class);
    }
}
