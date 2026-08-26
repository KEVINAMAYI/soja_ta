<?php

namespace App\Models;

use App\Utils\ApiConstants;
use Illuminate\Database\Eloquent\Model;

class UserActivityLog extends Model
{
    protected $fillable = [
        'action',
        'user_type',
        'user_id',
        'ip_address',
        'user_agent',
        'project_location',
        'description',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function storeUserActivityLog(string $action, ?string $description)
    {
        return self::create([
            'action' => $action,

            // check if userType is either 'super-admin' or 'admin', if not, set it to using authenticated user's role
            'user_type' =>  auth()->user()?->role[0]?->name ?? ApiConstants::USER_TYPE_USER,
            'user_id' => auth()->user()?->id,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'project_location' => request()->route()?->getActionName() ?? null, // this will store the controller and method name in the database
            'description' => $description,
        ]);
    }
}
