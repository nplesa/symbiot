<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutePoint extends Model
{
    protected $table = 'route_points';

    protected $fillable = [
        'route_id', 'sequence', 'latitude', 'longitude', 'elevation',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'elevation' => 'float',
        'sequence' => 'integer',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }
}
