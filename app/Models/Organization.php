<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'active',
        'address',
        'location',
        'email',
        'phone_number',
        'description',
        'website',
        'logo_path',
        'break_tracking_enabled',
        'primary_color',
        'logo_height',
        'logo_width',
        'sidebar_bg_color',
        'page_bg_color',
        'zkbio_sync_enabled',
        'zkbio_base_url',
        'zkbio_access_token',
        'zkbio_pin_start'
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    // Alias used by existing views (e.g. platform-admin/tenants.blade.php)
    public function getIsActiveAttribute(): bool
    {
        return (bool) $this->active;
    }

    public function employees()
    {
        return $this->hasMany(Employee::class)->withTrashed();
    }

    public function employeeTypes()
    {
        return $this->hasMany(EmployeeType::class);
    }

    public function services()
    {
        return $this->hasMany(Service::class);
    }

    public function leaveTypes()
    {
        return $this->hasMany(LeaveType::class);
    }

    public function settings()
    {
        return $this->hasMany(OrganizationSetting::class);
    }

    public function getSetting($key, $default = null)
    {
        return $this->settings->firstWhere('key', $key)?->value ?? $default;
    }

    public function setSetting($key, $value, $type = 'string')
    {
        return $this->settings()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'type' => $type]
        );
    }

    public function departments()
    {
        return $this->hasMany(Department::class);
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class);
    }

}

