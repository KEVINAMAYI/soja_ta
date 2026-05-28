<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ZkbioArea extends Model
{
    protected $fillable = [
        'organization_id',
        'area_code',
        'area_name',
        'zkbio_area_id',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_areas', 'zkbio_area_id', 'employee_id')
            ->withTimestamps();
    }
}
