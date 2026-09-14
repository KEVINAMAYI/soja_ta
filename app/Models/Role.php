<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    protected $fillable = [
        'name',
        'guard_name',
        'organization_id',
        'is_internal',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
    ];
}

