<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tracking;
use App\Models\TrackingSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;

class TrackingController extends Controller
{

    /**
     * Pornește sau oprește tracking-ul pentru utilizatorul autentificat.
     *
     * La pornirea tracking-ului:
     * - activează tracking-ul pe utilizator;
     * - creează o sesiune nouă de tracking.
     *
     * La oprirea tracking-ului:
     * - dezactivează tracking-ul;
     * - închide sesiunea activă prin completarea câmpului ended_at.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function toggle(Request $request): JsonResponse
    {
        $request->validate([
            'trackingmyself' => ['required', 'boolean'],
        ]);

        $user = $request->user();

        $tracking = $request->boolean('trackingmyself');

        $user->tracking = $tracking;
        $user->save();

        if ($tracking) {

            // Evită crearea mai multor sesiuni active pentru același user
            $activeSession = TrackingSession::where('user_id', $user->id)
                ->whereNull('ended_at')
                ->latest('started_at')
                ->first();

            if (! $activeSession) {
                $activeSession = TrackingSession::create([
                    'user_id' => $user->id,
                    'started_at' => now(),
                ]);
            }

            return response()->json([
                'success' => true,
                'tracking' => true,
                'session_id' => $activeSession->id,
            ]);
        }


        // Oprire tracking
        $activeSession = TrackingSession::where('user_id', $user->id)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first();

        if ($activeSession) {
            $activeSession->update([
                'ended_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'tracking' => false,
        ]);
    }


    /**
     * Salvează un punct GPS în sesiunea activă de tracking.
     *
     * Punctul GPS este asociat automat cu ultima sesiune
     * activă a utilizatorului autentificat.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function point(Request $request): JsonResponse
    {
        $request->validate([
            'latitude' => ['required', 'numeric'],
            'longitude' => ['required', 'numeric'],
            'tracked_at' => ['required', 'date'],
        ]);

        $user = $request->user();

        $session = TrackingSession::where('user_id', $user->id)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first();

        if (! $session) {
            return response()->json([
                'success' => false,
                'message' => 'No active tracking session.',
            ], 409);
        }

        $point = Tracking::create([
            'tracking_session_id' => $session->id,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'tracked_at' => $request->tracked_at,
        ]);

        return response()->json([
            'success' => true,
            'point_id' => $point->id,
        ]);
    }

    /**
     * Returnează statusul tracking-ului curent.
     */
    public function status(Request $request)
    {
        $session = TrackingSession::where('user_id', $request->user()->id)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first();

        return response()->json([
            'active' => (bool) $session,
            'session' => $session,
        ]);
    }


    /**
     * Lista sesiunilor utilizatorului.
     */
    public function sessions(Request $request)
    {
        $sessions = TrackingSession::where(
                'user_id',
                $request->user()->id
            )
            ->latest('started_at')
            ->get();

        return response()->json($sessions);
    }


    /**
     * Detalii sesiune.
     */
    public function show(
        Request $request,
        TrackingSession $session
    ) {
        $this->authorizeSession($request, $session);

        return response()->json($session);
    }


    /**
     * Returnează punctele unei sesiuni.
     */
    public function points(
        Request $request,
        TrackingSession $session
    ) {
        $this->authorizeSession($request, $session);

        $points = $session
            ->trackings()
            ->ordered()
            ->get();

        return response()->json($points);
    }


    /**
     * Returnează traseul pentru hartă.
     *
     * Pentru trasee planificate folosește GeoJSON.
     * Pentru GPS construiește LineString.
     */
    public function route(
        Request $request,
        TrackingSession $session
    ) {
        $this->authorizeSession($request, $session);


        if ($session->isPlanned()) {

            return response()->json([
                'type' => 'Feature',
                'geometry' => $session->route_geojson,
            ]);
        }


        $coordinates = $session
            ->trackings()
            ->orderBy('tracked_at')
            ->get()
            ->map(function (Tracking $point) {

                return [
                    $point->longitude,
                    $point->latitude,
                ];

            });


        return response()->json([
            'type' => 'Feature',
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => $coordinates,
            ],
        ]);
    }


    /**
     * Pornește un tracking GPS.
     */
    public function start(Request $request)
    {
        $session = TrackingSession::create([

            'user_id' => $request->user()->id,

            'device_id' => $request->device_id,

            'type' => 'gps',

            'status' => 'active',

            'started_at' => now(),

        ]);


        return response()->json($session, 201);
    }


    /**
     * Primește o poziție GPS.
     */
    public function location(Request $request)
    {
        $data = $request->validate([

            'tracking_session_id' => [
                'required',
                'exists:tracking_sessions,id',
            ],

            'latitude' => [
                'required',
                'numeric',
            ],

            'longitude' => [
                'required',
                'numeric',
            ],

            'accuracy' => [
                'nullable',
                'numeric',
            ],

            'speed' => [
                'nullable',
                'numeric',
            ],

            'heading' => [
                'nullable',
                'numeric',
            ],

            'altitude' => [
                'nullable',
                'numeric',
            ],

        ]);


        $tracking = Tracking::create([

            'tracking_session_id' =>
                $data['tracking_session_id'],

            'type' => 'gps',

            'source' => 'device',

            ...$data,

            'tracked_at' => now(),

        ]);


        return response()->json($tracking, 201);
    }


    /**
     * Oprește tracking-ul.
     */
    public function stop(
        Request $request
    ) {

        $session = TrackingSession::where(
                'user_id',
                $request->user()->id
            )
            ->whereNull('ended_at')
            ->latest('started_at')
            ->firstOrFail();


        $session->update([

            'ended_at' => now(),

            'status' => 'completed',

        ]);


        return response()->json($session);
    }


    /**
     * Șterge o sesiune.
     */
    public function destroy(
        Request $request,
        TrackingSession $session
    ) {
        $this->authorizeSession($request, $session);


        DB::transaction(function () use ($session) {

            $session
                ->trackings()
                ->delete();

            $session
                ->delete();

        });


        return response()->json([
            'success' => true,
        ]);
    }


    /**
     * Verifică proprietarul sesiunii.
     */
    private function authorizeSession(
        Request $request,
        TrackingSession $session
    ): void {

        abort_unless(
            $session->user_id === $request->user()->id,
            403
        );

    }
}