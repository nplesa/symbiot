<?php

namespace App\Http\Controllers;

use App\Contracts\TrafficProvider;
use Illuminate\Http\Response;
use RuntimeException;

class TrafficTileController extends Controller
{
    public function __invoke(TrafficProvider $traffic, int $z, int $x, int $y): Response
    {
        abort_unless($z >= 0 && $z <= 22 && $x >= 0 && $y >= 0, 404);
        abort_if($x >= 2 ** $z || $y >= 2 ** $z, 404);

        try {
            $tile = $traffic->flowTile($z, $x, $y);
        } catch (RuntimeException) {
            return response('Traficul este temporar indisponibil.', 503, [
                'Cache-Control' => 'no-store', 'Retry-After' => '60',
            ]);
        }

        return response($tile, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
