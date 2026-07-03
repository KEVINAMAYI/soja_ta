<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DepartmentLeaveApprovalSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'department_id',
        'enabled',
        'levels',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'levels' => 'array',
    ];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
