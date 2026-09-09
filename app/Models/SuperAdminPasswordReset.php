<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuperAdminPasswordReset extends Model
{
    protected $fillable = [
        'user_id',
        'email',
        'token_hash',
        'attempt_number',
        'requested_at',
        'requested_ip',
        'user_agent',
        'expires_at',
        'used_at',
        'invalidated_at',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'requested_at' => 'datetime',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'invalidated_at' => 'datetime',
    ];

    protected $hidden = [
        'token_hash',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Tokens that have not been consumed, superseded or expired.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('used_at')
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Tokens that have not been consumed or superseded, regardless of expiry.
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('used_at')->whereNull('invalidated_at');
    }
}
