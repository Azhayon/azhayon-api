<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class UserPolicy extends AdminOnlyPolicy
{
    /** Admins can ban or unban anyone except themselves. */
    public function ban(User $user, User $target): bool
    {
        return $user->isAdmin() && $user->id !== $target->id;
    }

    /** Only between staff roles: readers never become staff. */
    public function changeRole(User $user, User $target): bool
    {
        return $user->isAdmin()
            && $user->id !== $target->id
            && $target->isStaff()
            && ! $target->trashed();
    }

    public function resetTwoFactor(User $user, User $target): bool
    {
        return $user->isAdmin()
            && $user->id !== $target->id
            && $target->isStaff();
    }

    /** Nobody can delete their own account from the admin. */
    public function delete(User $user, Model $model): bool
    {
        return parent::delete($user, $model)
            && $model instanceof User
            && $user->id !== $model->id;
    }
}