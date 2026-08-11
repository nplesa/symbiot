<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Route extends Model
{
    protected $table = 'routes';

    protected $fillable = [
        'user_id', 'name', 'format', 'geometry', 'distance', 'duration',
        'elevation_gain', 'elevation_loss', 'source', 'source_url',
    ];

    protected $casts = [
        'geometry' => 'array',
        'distance' => 'float',
        'duration' => 'integer',
        'elevation_gain' => 'float',
        'elevation_loss' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function points(): HasMany
    {
        return $this->hasMany(RoutePoint::class, 'route_id');
    }
}
