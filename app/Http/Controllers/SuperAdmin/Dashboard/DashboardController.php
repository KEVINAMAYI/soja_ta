<?php

namespace App\Http\Controllers\SuperAdmin\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\DashboardAnalyticsService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

#[Group('Superadmin/Dashboard')]
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardAnalyticsService $analyticsService)
    {
    }

    /**
     * GET /super-man/dashboard/analytics
     *
     * Returns clients, workforce, attendance and platform-utilization
     * highlights for the selected period (today, this_week, last_30_days,
     * last_90_days or a custom start_date/end_date range).
     */
    public function analytics(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'period' => 'required|string|in:' . implode(',', DashboardAnalyticsService::PERIODS),
            'start_date' => 'required_if:period,custom|nullable|date',
            'end_date' => 'required_if:period,custom|nullable|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'code' => 1003,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $this->analyticsService->getAnalytics(
                $request->string('period')->toString(),
                $request->input('start_date'),
                $request->input('end_date'),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'code' => 1003,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'code' => 1000,
            'data' => $data,
        ]);
    }
}
