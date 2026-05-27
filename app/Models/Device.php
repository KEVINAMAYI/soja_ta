<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    protected $fillable = [
        'device_name',
        'platform',
        'checkpoint_id',
        'pin',
        'device_location_id',
        'organization_id',
        'active',
    ];

    // 🔁 Each device belongs to a DeviceLocation
    public function deviceLocation()
    {
        return $this->belongsTo(DeviceLocation::class);
    }
}
