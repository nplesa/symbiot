<?php

namespace Database\Factories;

use App\Models\TrackingSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TrackingSession> */
class TrackingSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => 'gps',
            'source' => 'device',
            'status' => 'active',
            'started_at' => now(),
            'ended_at' => null,
            'distance' => 0,
            'duration' => 0,
        ];
    }
}
