<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LevelApprover extends Model
{
    use HasFactory;

    protected $fillable = [
        'leave_approval_log_id',
        'level_approver_id',
        'action',
    ];

    public function levelApprover()
    {
        return $this->belongsTo(User::class, 'level_approver_id');
    }

    public static function getActionedApproversCountForLog(int $leave_approval_log_id)
    {
        return LevelApprover::where('leave_approval_log_id', $leave_approval_log_id)->count();
    }
}
