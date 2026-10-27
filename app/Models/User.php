<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * role, trust_score and avatar_path are intentionally not fillable: roles are changed only with
 * `php artisan arc:set-role`, ARC manages the score, and the avatar path is only set by the upload
 * endpoint.
 */
#[Fillable(['name', 'email', 'phone', 'address', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Matches the column default, so a newly created user has a role before it is reloaded.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'renter',
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
            'trust_score' => 'integer',
            'role' => UserRole::class,
        ];
    }

    /**
     * The account's role, falling back to the least-privileged one if the column has no value
     * (e.g. before the role migration has run).
     */
    public function effectiveRole(): UserRole
    {
        return $this->role ?? UserRole::Renter;
    }

    public function canManageVehicles(): bool
    {
        return $this->effectiveRole()->canManageVehicles();
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }
}
