<?php

namespace App\Http\Controllers\APIs;

use Illuminate\Support\Facades\Log;
use App\Http\Middleware\ApiKeyAuth;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\Request;
use App\Services\AttendanceReportHttpService;
use Carbon\Carbon;

class ElevateHRApiController extends Controller
{
    /**
     * Sample endpoint to verify that a request was authenticated using a valid API key.
     */
    public function ping(Request $request)
    {
        Log::info("PING HIT!!!!!1");
        $apiKey = $request->attributes->get('api_key');
        $organization = $request->attributes->get('api_key_organization');

        return response()->json([
            'code' => 1000,
            'message' => 'Endpoint hit successfully with a valid API key.',
            'data' => [
                'organization' => $organization?->name,
                'environment' => $apiKey?->environment,
                'key_name' => $apiKey?->name,
            ],
        ]);
    }

    public function getAttendanceReport(Request $request)
    {
        // This is a placeholder for the actual implementation of the attendance report retrieval.
        // You would typically call a service or repository method here to fetch the data.
        // rules to ensure valid start and end dates are provided, and then call the service to get the report.
        try {
            $request->validate([
                'view_type' => 'nullable|string|in:summary,detailed',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'page' => 'nullable|integer|min:1',
                'page_size' => 'nullable|integer|min:1|max:100',
        ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code' => 1001,
                'message' => 'Validation failed. Please check the provided data.',
                'errors' => $e->errors(),
            ], 422);
        }

        $plainTextKey = ApiKeyAuth::getApiKeyFromRequestHeader($request);
        // $plainTextKey = $request->header('X-Api-Key') ?? $request->bearerToken();
        $keyDetails = ApiKey::keyOrgDetails($plainTextKey);

        if (!$keyDetails) {
            return response()->json([
                'code' => 1003,
                'message' => 'Invalid or revoked API key.',
            ], 401);
        }

        $data = ''; // Placeholder for the actual data

        try {
            $view_type = 'summary'; // Default view type
            if ($request->has('view_type')) {
                $view_type = $request->input('view_type');
            }
            $service = app(AttendanceReportHttpService::class);

            if ($view_type === 'summary') {
                $data = $service->getCustomAttendanceSummary(
                    $keyDetails->organization_id,
                    [],
                    Carbon::parse($request->input('start_date')),
                    Carbon::parse($request->input('end_date')),
                    [],
                    $request->integer('page', 1),
                    $request->integer('pageSize') ?: 10
                );
            } elseif ($view_type === 'detailed') {
                $data = 'Under construction'; // Placeholder for detailed view implementation
            }
        } catch (\Exception $e) {
            Log::error('FAILED TO INITIALIZE ATTENDANCE REPORT SERVICE: ' . $e->getMessage());
            return response()->json([
                'code' => 1002,
                'message' => 'Oh no its not you, its me.',
                // TODO: Generate a unique track ID for this error and log it for support reference.
                'error' => 'We are unable to process your request at this time. Please try again later. If the issue persists, contact support with the following track ID: ' . 1,
            ], 500);
        }

        if ($data instanceof \Illuminate\Pagination\LengthAwarePaginator) {

            $newData = [];
            $newData[] = [
                'start_date' => Carbon::parse($request->input('start_date')),
                'end_date' => Carbon::parse($request->input('end_date')),
                'view_type' => 'monthly',
                'data' => $data->items(),
            ];
            return response()->json([
                'code' => 1000,
                'message' => 'Attendance report retrieved successfully.',
                'data' => $newData[0],
                // 'data' => $data->items(),
                // 'data' => $data, // this will depend on developer preference
                'pagination' => [
                    'page' => $data->currentPage(),
                    'page_size' => $data->perPage(),
                    'total' => $data->total(),
                    'last_page' => $data->lastPage(),
                ],
            ]);
        }

        return response()->json([
            'code' => 1000,
            'message' => 'Attendance report retrieved successfully.',
            'data' => $data
        ]);
    }
}
