<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CategoryPolicy extends ContentPolicy
{
    /**
     * Admins only, and never while posts (including soft-deleted ones) still use
     * the category: the database foreign key would reject it anyway.
     */
    public function delete(User $user, Model $model): bool
    {
        return parent::delete($user, $model)
            && ! ($model instanceof Category && $model->posts()->withTrashed()->exists());
    }
}