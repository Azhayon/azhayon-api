<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy extends AdminOnlyPolicy
{
    /** Admins can ban anyone except themselves. */
    public function ban(User $user, User $target): bool
    {
        return $user->isAdmin() && $user->id !== $target->id;
    }
}