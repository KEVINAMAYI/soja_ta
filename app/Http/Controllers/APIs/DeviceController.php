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
        try {
            // Validate incoming request
            $validated = $request->validate([
                'pin' => 'nullable|string|max:10',
            ]);

            // Try to find the device by checkpoint_id
            $device = Device::where('pin', $validated['pin'])->first();

            // Check if the device exists
            if (!$device) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'Device not found.',
                ], 404);
            }

            // Check if the device is active
            if (!$device->active) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'Device is inactive.',
                ], 403);
            }

            // Check if the PIN matches, if provided
            if ($device->pin && $device->pin !== $validated['pin']) {
                return response()->json([
                    'code' => 1003,
                    'message' => 'Invalid PIN.',
                ], 401);
            }

            // If everything is okay, return success response
            return response()->json([
                'code' => 1000,
                'message' => 'Device verified successfully.',
                'data' => [
                    'device_id' => $device->id,
                    'device_name' => $device->device_name,
                    'platform' => $device->platform,
                    'device_location' => new DeviceLocationResource($device->deviceLocation),
                ],
            ], 200);

        } catch (\Exception $e) {
            // Catch any errors and return a simplified error message
            return response()->json([
                'code' => 1003,
                'message' => 'An error occurred, please try again.',
            ], 500);
        }
    }

}
