<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_super_admin',
        'is_active',
        'portal_theme',
        'avatar_path',
        'requires_profile_setup',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'avatar_path',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
            'requires_profile_setup' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function hasPermission(string $slug): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        if (! $this->is_active) {
            return false;
        }

        return $this->permissions->contains('slug', $slug);
    }

    public function permissionSlugs(): array
    {
        if ($this->is_super_admin) {
            return collect(config('sfo.permissions'))->pluck('slug')->all();
        }

        return $this->permissions->pluck('slug')->all();
    }

    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_super_admin' => $this->is_super_admin,
            'is_active' => $this->is_active,
            'permissions' => $this->permissionSlugs(),
            'portal_theme' => $this->portal_theme ?? 'light',
            'avatar_version' => $this->avatar_path ? basename($this->avatar_path) : null,
            'requires_profile_setup' => (bool) $this->requires_profile_setup,
        ];
    }
}
