<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OrganizationApiKey extends Model
{
    protected $fillable = [
        'organization_id',
        'environment',
        'key_prefix',
        'last_four',
        'key_hash',
        'active',
        'last_used_at',
    ];

    protected $casts = [
        'active' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Generate a new plaintext key for the given environment.
     * The plaintext is only ever available at generation time; only its
     * hash is persisted.
     *
     * @return array{plain: string, prefix: string, last_four: string, hash: string}
     */
    public static function generatePlainKey(string $environment): array
    {
        $prefix = $environment === 'production' ? 'sk_live_' : 'sk_test_';
        $secret = Str::random(40);
        $plain = $prefix . $secret;

        return [
            'plain' => $plain,
            'prefix' => $prefix,
            'last_four' => substr($secret, -4),
            'hash' => Hash::make($plain),
        ];
    }
}
