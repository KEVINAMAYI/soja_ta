<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImpersonationSession extends Model
{
    protected $fillable = [
        'super_admin_id',
        'impersonated_user_id',
        'organization_id',
        'token_hash',
        'token_expires_at',
        'consumed_at',
        'started_at',
        'expires_at',
        'ended_at',
        'ended_reason',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function superAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'super_admin_id');
    }

    public function impersonatedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonated_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isActive(): bool
    {
        return $this->ended_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
