<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function trackings()
    {
        return $this->hasMany(Tracking::class);
    }
}
