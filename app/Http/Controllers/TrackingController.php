<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tracking;
use App\Models\TrackingSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use App\Http\Requests\Tracking\StoreTrackingPointRequest;
use App\Services\TrackingSessionService;

class TrackingController extends Controller
{
    public function __construct(
        private readonly TrackingSessionService $trackingSessions
    ) {}

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
    public function index(): \Illuminate\Contracts\View\View
    {
        return view('tracking.index');
    }

    public function tile(int $z, int $x, int $y): Response
    {
        abort_unless($z >= 0 && $z <= 22 && $x >= 0 && $y >= 0, 404);

        $maxTile = (2 ** $z) - 1;
        abort_if($x > $maxTile || $y > $maxTile, 404);

        $key = config('services.geoapify.key');
        abort_if(blank($key), 503, 'Map provider is not configured.');

        $url = "https://maps.geoapify.com/v1/tile/osm-bright-smooth/{$z}/{$x}/{$y}.png";

        try {
            $response = Http::connectTimeout(5)
                ->timeout(10)
                ->retry(2, 250, throw: false)
                ->get($url, ['apiKey' => $key]);
        } catch (ConnectionException $e) {
            report($e);

            return response('Map tile provider is temporarily unreachable.', 503)
                ->header('Retry-After', '30');
        }

        if (! $response->successful()) {
            \Illuminate\Support\Facades\Log::warning('Geoapify map tile request failed.', [
                'status' => $response->status(),
                'content_type' => $response->header('Content-Type'),
                'body' => mb_substr($response->body(), 0, 1000),
                'z' => $z,
                'x' => $x,
                'y' => $y,
            ]);

            return response('Map tile provider returned an error.', 503)
                ->header('Retry-After', '30');
        }

        return response($response->body(), 200, [
            'Content-Type' => $response->header('Content-Type', 'image/png'),
            'Cache-Control' => 'public, max-age=86400, s-maxage=86400',
        ]);
    }

    public function toggle(Request $request): JsonResponse
    {
        $request->validate([
            'trackingmyself' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        $tracking = $request->boolean('trackingmyself');

        $user->update(['tracking' => $tracking]);

        if ($tracking) {
            $session = $this->trackingSessions->start($user);

            return response()->json([
                'success' => true,
                'tracking' => true,
                'session_id' => $session->id,
            ]);
        }

        $this->trackingSessions->stop($user);

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
    public function point(StoreTrackingPointRequest $request): JsonResponse
    {
        $data = $request->validated();


        $session = TrackingSession::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first();

        if (! $session) {
            return response()->json([
                'success' => false,
                'message' => 'No active tracking session.',
            ], 409);
        }

        $point = $this->trackingSessions->addPoint($request->user(), $session, $data);

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
        $query = TrackingSession::query()
            ->where('user_id', $request->user()->id);

        if ($request->filled('date')) {
            $date = $request->date('date');

            $query->whereBetween('started_at', [
                $date->copy()->startOfDay(),
                $date->copy()->endOfDay(),
            ]);
        }

        $sessions = $query->latest('started_at')->get();

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
            ->orderBy('id')
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
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
        ]);

        $session = $this->trackingSessions->start(
            $request->user(),
            $data['device_id'] ?? null
        );

        return response()->json([
            'success' => true,
            'session' => $session,
        ]);
    }

    /**
     * Primește o poziție GPS.
     */
    public function location(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tracking_session_id' => ['required', 'integer', 'exists:tracking_sessions,id'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'speed' => ['nullable', 'numeric', 'min:0'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'altitude' => ['nullable', 'numeric'],
            'battery' => ['nullable', 'integer', 'min:0', 'max:100'],
            'tracked_at' => ['nullable', 'date'],
        ]);

        $session = TrackingSession::query()->findOrFail($data['tracking_session_id']);
        $tracking = $this->trackingSessions->addPoint($request->user(), $session, $data);

        return response()->json($tracking, 201);
    }

    /**
     * Oprește tracking-ul.
     */
    public function stop(Request $request): JsonResponse
    {
        $session = $this->trackingSessions->stop($request->user());

        if (! $session) {
            return response()->json([
                'success' => true,
                'tracking' => false,
                'message' => 'No active tracking session.',
            ]);
        }

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