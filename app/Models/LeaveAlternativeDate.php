<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveAlternativeDate extends Model
{
    use HasFactory;

    protected $fillable = [
        'leave_id',
        'new_start_date',
        'new_end_date',
        'new_num_of_days',
        'status',
        'created_by',
        'actioned_by',
        'notes',
    ];

    public function leave()
    {
        return $this->belongsTo(Leave::class, 'leave_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function actionedBy()
    {
        return $this->belongsTo(User::class, 'actioned_by');
    }

}
