<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElevateHrWebhook extends Model
{
    protected $fillable = [
        'organization_id',
        'token_hash',
        'created_by',
        'creator_name',
        'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
        ];
    }
}
