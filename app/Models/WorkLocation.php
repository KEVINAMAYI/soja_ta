<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkLocation extends Model
{
    protected $fillable = [
        'organization_id', 'name', 'type', 'address', 'latitude', 'longitude', 'radius_m', 'description', 'active'
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function assignments()
    {
        return $this->hasMany(EmployeeAssignment::class, 'work_location_id');
    }

    // distance in meters using Haversine
    public function distanceTo(float $lat, float $lng): float
    {
        if ($this->latitude === null || $this->longitude === null) return INF;

        $earthRadius = 6371000; // meters

        $latFrom = deg2rad((float)$this->latitude);
        $lonFrom = deg2rad((float)$this->longitude);
        $latTo = deg2rad($lat);
        $lonTo = deg2rad($lng);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
            cos($latFrom) * cos($latTo) *
            sin($lonDelta / 2) * sin($lonDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }
}

