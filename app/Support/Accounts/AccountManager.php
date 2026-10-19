<?php

namespace App\Support\Accounts;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Every account change made from the admin goes through here, so each one
 * re-checks its permission on the server whatever the screen showed.
 */
class AccountManager
{
    /** @param  array{name: string, email: string, role: string, password: string}  $data */
    public function createStaff(array $data): User
    {
        Gate::authorize('create', User::class);

        $role = UserRole::from($data['role']);

        if (! $role->isStaff()) {
            throw new InvalidArgumentException('Staff accounts must be admins or editors.');
        }

        $user = new User([
            'name' => trim($data['name']),
            'email' => Str::lower(trim($data['email'])),
            'password' => $data['password'], // hashed by the model cast
        ]);

        // role and email_verified_at are deliberately not mass-assignable.
        $user->forceFill(['role' => $role, 'email_verified_at' => now()])->save();

        return $user;
    }

    public function changeRole(User $target, UserRole $role): void
    {
        Gate::authorize('changeRole', $target);

        if (! $role->isStaff()) {
            throw new InvalidArgumentException('Staff can only be admins or editors.');
        }

        $target->forceFill(['role' => $role])->save();
    }

    public function ban(User $target): void
    {
        Gate::authorize('ban', $target);

        $target->forceFill(['banned_at' => now()])->save();
        $target->tokens()->delete(); // sign them out of the API immediately
    }

    public function unban(User $target): void
    {
        Gate::authorize('ban', $target);

        $target->forceFill(['banned_at' => null])->save();
    }

    public function resetTwoFactor(User $target): void
    {
        Gate::authorize('resetTwoFactor', $target);

        $target->forceFill([
            'app_authentication_secret' => null,
            'app_authentication_recovery_codes' => null,
        ])->save();
    }
}