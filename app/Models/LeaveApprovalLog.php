<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveApprovalLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'leave_id',
        'level_number',
        'approver_type',
        'approver_role',
        'approver_user_id',
        'approver_user_ids',
        'status',
        'opened_at',
        'closed_at',
        'actioned_by',
        'notes',
    ];

    protected $casts = [
        'level_number' => 'integer',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        // column is a text column; array cast JSON-encodes on write and decodes on read
        'approver_user_ids' => 'array',
    ];

    public function leave()
    {
        return $this->belongsTo(Leave::class);
    }

    public function approverUser()
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function actionedBy()
    {
        return $this->belongsTo(User::class, 'actioned_by');
    }

    public function levelApprovers()
    {
        return $this->hasMany(LevelApprover::class, 'leave_approval_log_id');
    }
}
