<?php

namespace App\Policies;

use App\Models\Rating;
use App\Models\User;

class RatingPolicy
{
    public function create(User $user): bool
    {
        return true;
    }

    public function delete(User $user, Rating $rating): bool
    {
        return $user->isAdmin() || $rating->user_id === $user->id;
    }
}