<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrganizationFeature extends Model
{
    protected $fillable = [
        'organization_id',
        'feature_id',
        'description',
        'key',
        'value',
        'type',
    ];

}
