<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'environment',
        'key_prefix',
        'last_four',
        'key_hash',
        'last_used_at',
        'created_by',
        'revoked_at',
        'revoked_by',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revoker()
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Masked representation for display, e.g. "ID_sandbox_••••••••ab12".
     */
    public function getMaskedKeyAttribute(): string
    {
        return "{$this->key_prefix}_••••••••{$this->last_four}";
    }

    /**
     * Generate a new API key for an organization.
     * Returns ['model' => ApiKey, 'plainTextKey' => string] - the plaintext is only ever available here.
     */
    public static function generateFor(Organization $organization, string $environment, string $name, ?int $createdBy = null): array
    {
        $orgPrefix = strtoupper(Str::substr(preg_replace('/[^A-Za-z]/', '', $organization->name) ?: 'XX', 0, 2));
        $keyPrefix = "{$orgPrefix}_{$environment}";
        $secret = Str::random(40);
        $plainTextKey = "{$keyPrefix}_{$secret}";

        $model = static::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'environment' => $environment,
            'key_prefix' => $keyPrefix,
            'last_four' => substr($secret, -4),
            'key_hash' => hash('sha256', $plainTextKey),
            'created_by' => $createdBy,
        ]);

        return ['model' => $model, 'plainTextKey' => $plainTextKey];
    }

    public static function findValidByPlainKey(string $plainTextKey): ?self
    {
        return static::whereNull('revoked_at')
            ->where('key_hash', hash('sha256', $plainTextKey))
            ->first();
    }
}
