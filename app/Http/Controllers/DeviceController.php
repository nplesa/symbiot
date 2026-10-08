<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function verify(Request $request): JsonResponse
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'authenticated' => false,
                'message' => 'Missing authentication token',
            ], 401);
        }

        $expectedToken = config('services.symbiot.device_token');

        if (!$expectedToken || !hash_equals($expectedToken, $token)) {
            return response()->json([
                'authenticated' => false,
                'message' => 'Invalid authentication token',
            ], 401);
        }

        return response()->json([
            'authenticated' => true,
            'message' => 'Device authenticated',
        ]);
    }
}
