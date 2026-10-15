<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Comment $comment): bool
    {
        return $user->isStaff();
    }

    /** Any signed-in, non-banned user (banned users are blocked by Gate::before). */
    public function create(User $user): bool
    {
        return true;
    }

    /** Editing is moderation only for now; users can't edit their own comments. */
    public function update(User $user, Comment $comment): bool
    {
        return $user->isStaff();
    }

    public function delete(User $user, Comment $comment): bool
    {
        return $user->isStaff() || $comment->user_id === $user->id;
    }

    public function deleteAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function restore(User $user, Comment $comment): bool
    {
        return $user->isAdmin();
    }

    public function restoreAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function forceDelete(User $user, Comment $comment): bool
    {
        return $user->isAdmin();
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function moderate(User $user, Comment $comment): bool
    {
        return $user->isStaff();
    }
}