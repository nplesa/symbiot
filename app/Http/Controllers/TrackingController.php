<?php

namespace App\Http\Controllers;

use App\Models\Tracking;
use App\Models\TrackingSession;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class TrackingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('tracking.index');
    }

    public function route(TrackingSession $session)
    {
        abort_if($session->user_id !== auth()->id(), 403);

        $points = $session->trackings()
            ->orderBy('tracked_at')
            ->get([
                'latitude',
                'longitude',
            ]);

        if ($points->count() < 2) {
            return response()->json([
                'type' => 'FeatureCollection',
                'features' => [],
            ]);
        }

        // elimină punctele consecutive identice
        $coordinates = [];
        $last = null;

        foreach ($points as $point) {

            $current = [
                round((float) $point->longitude, 6),
                round((float) $point->latitude, 6),
            ];

            if ($current !== $last) {
                $coordinates[] = $current;
                $last = $current;
            }
        }

        if (count($coordinates) < 2) {
            return response()->json([
                'type' => 'FeatureCollection',
                'features' => [],
            ]);
        }

        $response = Http::timeout(20)
            ->post(
                'https://api.geoapify.com/v1/routing?apiKey=' . config('services.geoapify.key'),
                [
                    'mode' => 'drive',
                    'waypoints' => $coordinates,
                    'details' => ['instruction_details'],
                ]
            );

        if (!$response->successful()) {
            return response()->json([
                'message' => 'Geoapify routing failed.',
                'error' => $response->body(),
            ], 500);
        }

        return response()->json($response->json());
    }

    public function tile($z, $x, $y)
    {
        $url = sprintf(
            'https://maps.geoapify.com/v1/tile/osm-carto/%d/%d/%d.png?apiKey=%s',
            $z,
            $x,
            $y,
            config('services.geoapify.key')
        );

        $response = Http::get($url);

        return response($response->body(), $response->status())
            ->header('Content-Type', 'image/png')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    public function toggle(Request $request)
    {
        $request->validate([
            'trackingmyself' => ['required', 'boolean'],
        ]);

        $user = $request->user();

        $user->tracking = $request->boolean('trackingmyself');
        $user->save();

        return response()->json([
            'success' => true,
            'tracking' => $user->tracking,
        ]);
    }

    public function start(Request $request)
    {
        // dacă există deja o sesiune activă nu mai creez alta
        if (session()->has('tracking_session_id')) {
            return response()->json([
                'success' => true
            ]);
        }

        $session = TrackingSession::create([
            'user_id'    => auth()->id(),
            'started_at' => now(),
        ]);

        session([
            'tracking_session_id' => $session->id
        ]);

        return response()->json([
            'success' => true
        ]);
    }

    public function point(Request $request)
    {
        $request->validate([
            'latitude'   => 'required|numeric',
            'longitude'  => 'required|numeric',
            'tracked_at' => 'required|date',
        ]);

        $sessionId = session('tracking_session_id');

        if (!$sessionId) {
            return response()->json([
                'success' => false,
                'message' => 'No active tracking session.'
            ], 409);
        }

        Tracking::create([
            'tracking_session_id' => $sessionId,
            'latitude'            => $request->latitude,
            'longitude'           => $request->longitude,
            'tracked_at'          => $request->tracked_at,
        ]);

        return response()->json([
            'success' => true
        ]);
    }

    public function stop(Request $request)
    {
        $sessionId = session('tracking_session_id');

        if ($sessionId) {

            TrackingSession::whereKey($sessionId)
                ->update([
                    'ended_at' => now()
                ]);

            session()->forget('tracking_session_id');
        }

        return response()->json([
            'success' => true
        ]);
    }

    public function show(TrackingSession $session)
    {
        abort_if($session->user_id != auth()->id(), 403);

        return $session->trackings()
            ->orderBy('tracked_at')
            ->get();
    }

    public function sessions(Request $request)
    {
        $date = Carbon::parse($request->date);

        return TrackingSession::where('user_id', auth()->id())
            ->whereDate('started_at', $date)
            ->orderBy('started_at')
            ->get([
                'id',
                'started_at',
                'ended_at'
            ]);
    }


    public function points(TrackingSession $session)
    {
        abort_if($session->user_id !== auth()->id(), 403);

        return response()->json(
            $session->trackings()
                ->orderBy('tracked_at')
                ->get([
                    'latitude',
                    'longitude',
                    'tracked_at',
                ])
        );
    }

}
