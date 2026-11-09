<?php

namespace App\Http\Controllers;

use App\Services\FlightAwareProvider;
use Illuminate\Http\JsonResponse;

class AirportFlightsController extends Controller
{
    public function __invoke(string $airport, FlightAwareProvider $provider): JsonResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{3,4}$/', $airport) === 1, 422);

        return response()->json($provider->airportBoard($airport));
    }
}
