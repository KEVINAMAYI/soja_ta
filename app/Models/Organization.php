<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'address',
        'location',
        'email',
        'phone_number',
        'description',
        'website',
        'logo_path',
    ];

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    public function employeeTypes()
    {
        return $this->hasMany(EmployeeType::class);
    }

    public function services()
    {
        return $this->hasMany(Service::class);
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

