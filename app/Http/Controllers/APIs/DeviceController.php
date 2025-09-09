<?php

namespace App\Http\Controllers\APIs;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceLocationResource;
use App\Models\Device;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function verify(Request $request)
    {
        $validated = $request->validate([
            'checkpoint_id' => 'required|string',
            'pin' => 'nullable|string|max:10',
        ]);

        $device = Device::where('checkpoint_id', $validated['checkpoint_id'])->first();

        if (!$device) {
            return response()->json([
                'code' => 1001,
                'message' => 'Device not found.',
            ], 404);
        }

        if (!$device->active) {
            return response()->json([
                'code' => 1002,
                'message' => 'Device is inactive.',
            ], 403);
        }

        if ($device->pin && $device->pin !== $validated['pin']) {
            return response()->json([
                'code' => 1003,
                'message' => 'Invalid PIN.',
            ], 401);
        }

        return response()->json([
            'code' => 1000,
            'message' => 'Device verified successfully.',
            'data' => [
                'device_id' => $device->id,
                'device_name' => $device->device_name,
                'platform' => $device->platform,
                'device_location' => new DeviceLocationResource($device->deviceLocation),
            ],
        ]);
    }

}
