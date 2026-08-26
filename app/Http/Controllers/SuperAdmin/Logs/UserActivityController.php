<?php

namespace App\Http\Controllers\SuperAdmin\Logs;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class UserActivityController extends Controller
{
    //method to filter user activity logs
    public function filterUserActivityLogs(Request $request)
    {
        // validate user input to avoid sql injection
        $request->validate([
            'action' => 'nullable|string',
            'user_type' => 'nullable|string',
            'user_id' => 'nullable|integer',
            'ip_address' => 'nullable|ip',
            'user_agent' => 'nullable|string',
            'project_location' => 'nullable|string',
        ]);
        $query = \App\Models\UserActivityLog::query();

        // use input% which is faster than %input% for filtering user activity logs (in case we have indexes)
        if ($request->has('action')) {
            $query->where('action', 'LIKE', $request->input('action') . '%');
        }

        if ($request->has('user_type')) {
            $query->where('user_type', 'LIKE', $request->input('user_type') . '%');
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        if ($request->has('ip_address')) {
            $query->where('ip_address', 'LIKE', $request->input('ip_address') . '%');
        }

        if ($request->has('user_agent')) {
            $query->where('user_agent', 'like', '%' . $request->input('user_agent') . '%');
        }

        if ($request->has('project_location')) {
            $query->where('project_location', 'like', '%' . $request->input('project_location') . '%');
        }

        // Paginate the results
        $logs = $query->paginate(10);

        return response()->json($logs);
    }
}
