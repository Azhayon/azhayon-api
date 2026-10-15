<?php

namespace Database\Factories;

use App\Enums\CommentStatus;
use App\Models\Comment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * post_id and user_id (and parent_id for replies) must be supplied by the caller.
 *
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    protected $model = Comment::class;

    public function definition(): array
    {
        return [
            'body' => fake()->sentences(fake()->numberBetween(1, 3), true),
            'is_spoiler' => fake()->boolean(10),
            // Weighted: mostly approved, a few pending, an occasional spam.
            'status' => fake()->randomElement([
                CommentStatus::Approved,
                CommentStatus::Approved,
                CommentStatus::Approved,
                CommentStatus::Approved,
                CommentStatus::Pending,
                CommentStatus::Spam,
            ]),
        ];
    }
}