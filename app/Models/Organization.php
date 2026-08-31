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
        'accent_color',
        'logo_height',
        'logo_width',
        'sidebar_bg_color',
        'page_bg_color',
        'zkbio_sync_enabled',
        'zkbio_base_url',
        'zkbio_access_token',
        'zkbio_pin_start',
        'subscription_plan_id',
        'max_locations',
        'max_devices',
        'ad_sync_enabled',
        'ad_tenant_id',
        'ad_client_id',
        'ad_client_secret',
        'api_docs_enabled',
        'api_docs_url',
    ];

    protected $casts = [
        'active' => 'boolean',
        'ad_sync_enabled' => 'boolean',
        'ad_client_secret' => 'encrypted',
        'api_docs_enabled' => 'boolean',
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

    public function subscriptionPlan()
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    public function apiKeys()
    {
        return $this->hasMany(OrganizationApiKey::class);
    }

}

