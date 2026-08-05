<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class TrackingSeeder extends Seeder
{
    public function run(): void
    {
        $margins = [
            [
                'start' => [
                    25.759500, // longitude Tărlungeni, Str. Zizinului 979A
                    45.642700, // latitude
                ],
                'end' => [
                    25.551000, // longitude Poiana Brașov
                    45.595000, // latitude
                ], 
            ],
            [
                'start' => [
                    25.759500, // longitude Tărlungeni, Str. Zizinului 979A
                    45.642700, // latitude
                ],
                'end' => [
                    25.458500, // longitude Centrul Târgoviște
                    44.925000, // latitude
                ], 
            ],
        ];

        $i = 0;
        foreach ($margins as $margin) {
            $i++;
            $rows = $this->buildTrack($i, 
                $margin['start'],
                $margin['end']
            );

            DB::table('trackings')
                ->insert($rows);
            }
    }


    /**
     * @param int $i
     * @param array{0: float, 1: float} $start
     * @param array{0: float, 1: float} $end
     * @return array<int, array<string, mixed>>
     */
    private function buildTrack(int $i, array $start, array $end): array {
        // OSRM route
        $url =
            'https://router.project-osrm.org/route/v1/driving/' .
            "{$start[0]},{$start[1]};{$end[0]},{$end[1]}" .
            '?overview=full&geometries=geojson';

        $response = Http::get($url);

        if (! $response->successful()) {
            throw new \Exception('OSRM route failed');
        }

        $data = $response->json();

        $geometry = $data['routes'][0]['geometry']['coordinates'];

        // luam 100 puncte egale
        $points = $this->samplePoints(
            $geometry,
            100
        );

        $time = Carbon::now()
            ->subMinutes(60);

        $rows = [];

        foreach ($points as $index => $point) {

            $rows[] = [

                'tracking_session_id' => $i,

                'provider' => 'gps',

                // OSRM vine [lng,lat]
                'latitude' => round($point[1], 7),

                'longitude' => round($point[0], 7),

                'accuracy' => rand(3, 10),

                'speed' => rand(20, 600) / 10,

                'heading' => rand(0, 359),

                'altitude' => rand(500, 1200),

                'battery' => 100 - $index,

                'tracked_at' => $time->copy()
                    ->addSeconds($index * 30),

                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $coordinates
     * @return array<int, array{0: float, 1: float}>
     */
    private function samplePoints(
        array $coordinates,
        int $count
    ): array {

        $result = [];

        $max = count($coordinates) - 1;

        for ($i = 0; $i < $count; $i++) {

            $index =
                intval(
                    ($max / ($count - 1))
                    *
                    $i
                );

            $result[] =
                $coordinates[$index];

        }

        return $result;
    }
}
