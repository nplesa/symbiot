<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $geoapify_id
 * @property string $name
 * @property string|null $city
 * @property string|null $country_code
 * @property array<string, mixed> $feature
 * @property float $min_lat
 * @property float $max_lat
 * @property float $min_lon
 * @property float $max_lon
 * @property \Illuminate\Support\Carbon $fetched_at
 */
class CityBoundary extends Model
{
    protected $fillable = [
        'geoapify_id', 'name', 'city', 'country_code', 'feature',
        'min_lat', 'max_lat', 'min_lon', 'max_lon', 'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'feature' => 'array',
            'min_lat' => 'float',
            'max_lat' => 'float',
            'min_lon' => 'float',
            'max_lon' => 'float',
            'fetched_at' => 'datetime',
        ];
    }
}
