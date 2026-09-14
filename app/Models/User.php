<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\CustomResetPassword;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Auth\Notifications\ResetPassword;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasRoles {
        assignRole as protected assignRoleUsingTrait;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'last_login_at',
        'last_login_ip',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn($word) => Str::substr($word, 0, 1))
            ->implode('');
    }


    public function employee()
    {
        return $this->hasOne(Employee::class)->withTrashed();
    }

    public function assignRole(...$roles)
    {
        $resolvedRoles = collect($roles)->flatten();

        if ($this->employee()->exists()) {
            foreach ($resolvedRoles as $candidate) {
                $role = $candidate instanceof Role
                    ? $candidate
                    : (is_int($candidate) || ctype_digit((string) $candidate)
                        ? Role::findOrFail($candidate)
                        : Role::findByName($candidate, 'web'));

                $isBootstrapSuperAdmin = $role?->name === 'super-admin' && !$this->employee()->exists();
                $isEstablishedSuperAdmin = $this->exists && $this->hasRole('super-admin');
                if ($role?->is_internal && !$isBootstrapSuperAdmin && !$isEstablishedSuperAdmin) {
                    throw new AuthorizationException('Internal roles can only be assigned to superadmins.');
                }
            }
        }

        return $this->assignRoleUsingTrait(...$roles);
    }


    public function sendPasswordResetNotificationWithOrganization($token, Organization $organization): void
    {
        $url = url(route('password.reset', [
            'token' => $token,
            'email' => $this->email,
        ], false));

        $this->notify(new CustomResetPassword($url, $organization->name, $organization->primary_color));
    }


}
