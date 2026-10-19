<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;
    use InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery;

    protected $attributes = [
        'role' => 'user',
    ];

    // role and banned_at are intentionally NOT fillable.
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar_url',
        'provider_name',
        'provider_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'banned_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'author_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    public function isStaff(): bool
    {
        return $this->role?->isStaff() ?? false;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isBanned(): bool
    {
        return array_key_exists('banned_at', $this->attributes) 
            && $this->attributes['banned_at'] !== null;
    }
    /** Staff only, and never banned or deleted accounts. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isStaff() && ! $this->isBanned() && ! $this->trashed();
    }

    protected function accountStatus(): Attribute
    {
        return Attribute::get(fn (): string => $this->trashed()
            ? 'Deleted'
            : ($this->isBanned() ? 'Banned' : 'Active'));
    }

    /** Reads the raw column so the secret is never decrypted just to show a label. */
    protected function twoFactorStatus(): Attribute
    {
        return Attribute::get(fn (): string => ! $this->isStaff()
            ? '—'
            : ($this->getRawOriginal('app_authentication_secret') !== null ? 'On' : 'Off'));
    }
}