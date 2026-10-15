<?php

namespace App\Policies;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    /** Guests may view live posts; drafts are visible to the author and admins. */
    public function view(?User $user, Post $post): bool
    {
        $isLive = $post->status === PostStatus::Published
            && $post->published_at !== null
            && $post->published_at->isPast();

        if ($isLive) {
            return true;
        }

        return $user !== null && $this->canManage($user, $post);
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, Post $post): bool
    {
        return $this->canManage($user, $post);
    }

    /** Publishing or scheduling is an admin-only ability. */
    public function publish(User $user, Post $post): bool
    {
        return $user->isAdmin();
    }

    /** Editors can delete only their own unpublished posts. */
    public function delete(User $user, Post $post): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isStaff()
            && $post->author_id === $user->id
            && $post->status !== PostStatus::Published;
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Post $post): bool
    {
        return $user->isAdmin();
    }

    public function restoreAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function forceDelete(User $user, Post $post): bool
    {
        return $user->isAdmin();
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->isAdmin();
    }

    private function canManage(User $user, Post $post): bool
    {
        return $user->isAdmin()
            || ($user->isStaff() && $post->author_id === $user->id);
    }
}