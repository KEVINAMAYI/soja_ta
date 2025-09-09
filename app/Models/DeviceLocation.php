<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceLocation extends Model
{
    protected $fillable = [
        'name',
        'description',
        'work_location_id',
        'organization_id',
        'active',
    ];

    // 🔁 One DeviceLocation belongs to one WorkLocation
    public function workLocation()
    {
        return $this->belongsTo(WorkLocation::class);
    }

    // 🔁 One DeviceLocation has many Devices
    public function devices()
    {
        return $this->hasMany(Device::class);
    }
}
