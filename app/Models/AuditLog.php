<?php

namespace App\Models;

use App\Utils\ApiConstants;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'action',
        'user_type',
        'user_id',
        'ip_address',
        'user_agent',
        'project_location',
        'description',
        'request_data',
        'response_data',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function storeAuditLog(string $action, ?string $projectLocation, ?string $description, mixed $requestData, mixed $responseData)
    {
        return self::create([
            'action' => $action,
            'user_type' => auth()->user()?->role[0]?->name ?? ApiConstants::USER_TYPE_SYSTEM,
            'user_id' => auth()->user()?->id,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'project_location' => $projectLocation,
            'description' => $description,
            'request_data' => is_array($requestData) ? json_encode($requestData) : $requestData,
            'response_data' => is_array($responseData) ? json_encode($responseData) : $responseData,
        ]);
    }

}
