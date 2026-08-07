<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
            'platform' => ['required', 'in:android,ios'],
            'manufacturer' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'os_version' => ['nullable', 'string', 'max:50'],
            'app_version' => ['nullable', 'string', 'max:50'],
            'push_token' => ['nullable', 'string', 'max:1000'],
            'battery' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $device = Device::where('uuid', $data['uuid'])->first();

        if ($device && $device->user_id !== $request->user()->id) {
            abort(409);
        }

        $device = Device::updateOrCreate(
            [
                'uuid' => $data['uuid'],
            ],
            [
                'user_id' => $request->user()->id,
                'platform' => $data['platform'],
                'manufacturer' => $data['manufacturer'] ?? null,
                'model' => $data['model'] ?? null,
                'device_name' => $data['device_name'] ?? null,
                'os_version' => $data['os_version'] ?? null,
                'app_version' => $data['app_version'] ?? null,
                'push_token' => $data['push_token'] ?? null,
                'battery' => $data['battery'] ?? null,
                'last_seen' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'device_id' => $device->id,
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $device = Device::where('user_id', $request->user()->id)
            ->latest('id')
            ->first();

        return response()->json([
            'success' => true,
            'device' => $device,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uuid' => ['sometimes', 'uuid'],
            'platform' => ['sometimes', 'in:android,ios'],
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:100'],
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'os_version' => ['sometimes', 'nullable', 'string', 'max:50'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:50'],
            'push_token' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'battery' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $device = Device::where('user_id', $request->user()->id)
            ->latest('id')
            ->first();

        if (!$device) {
            return response()->json([
                'success' => false,
                'message' => 'Device not found.',
            ], 404);
        }

        // UUID-ul identifică device-ul și nu trebuie schimbat
        // prin endpoint-ul de update.
        unset($data['uuid']);

        $device->fill($data);
        $device->last_seen = now();
        $device->save();

        return response()->json([
            'success' => true,
            'device' => $device->fresh(),
        ]);
    }

}
