<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApprovalWindowLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'check_in_approval_request_id',
        'window_number',
        'approver_role',
        'timeout_minutes',
        'on_timeout_action',
        'opened_at',
        'expires_at',
        'closed_at',
        'status',
        'actioned_by',
    ];

    protected $casts = [
        'window_number' => 'integer',
        'timeout_minutes' => 'integer',
        'opened_at' => 'datetime',
        'expires_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function request()
    {
        return $this->belongsTo(CheckInApprovalRequest::class, 'check_in_approval_request_id');
    }

    public function actionedBy()
    {
        return $this->belongsTo(\App\Models\User::class, 'actioned_by');
    }

    public function isExpired(): bool
    {
        return $this->status === 'pending' && now()->greaterThanOrEqualTo($this->expires_at);
    }
}
