<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'type',
        'standard_duration',
        'cost',
        'available_from',
        'available_to',
        'days_available',
        'clocking_type'
    ];

    protected $casts = [
        'days_available' => 'array',
        'available_from' => 'datetime:H:i',
        'available_to' => 'datetime:H:i',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function usages()
    {
        return $this->hasMany(ServiceUsage::class);
    }
}
