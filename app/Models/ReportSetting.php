<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ReportSetting extends Model
{
    use HasFactory;

    protected $table = 'report_settings';

    protected $fillable = [
        'organization_id',
        'email',
        'report_type',
        'frequency',
        'time',
        'day_of_week',
        'timezone',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'time' => 'datetime:H:i', // if stored as TIME in DB, Laravel will format
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }


    // Optional: If `email` always belongs to a User
    public function user()
    {
        return $this->belongsTo(User::class, 'email', 'email');
    }


    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeForOrganization($query, $orgId)
    {
        return $query->where('organization_id', $orgId);
    }

    public function scopeForReportType($query, $type)
    {
        return $query->where('report_type', $type);
    }
}
