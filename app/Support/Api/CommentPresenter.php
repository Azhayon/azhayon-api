<?php

namespace App\Support\Api;

use App\Models\Comment;

/** The public shape of a comment. The author is a display name only. */
final class CommentPresenter
{
    /** @return array<string, mixed> */
    public static function reply(Comment $comment): array
    {
        return [
            'id' => $comment->id,
            'author' => $comment->display_name,
            'body' => $comment->body,
            'is_spoiler' => $comment->is_spoiler,
            'created_at' => $comment->created_at->clone()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** @return array<string, mixed> */
    public static function comment(Comment $comment): array
    {
        return self::reply($comment) + [
            'replies' => $comment->replies->map(fn (Comment $reply): array => self::reply($reply))->values()->all(),
        ];
    }

    /** @return array<string, mixed> What the author gets back: their own comment, still waiting for approval. */
    public static function created(Comment $comment): array
    {
        return [
            'id' => $comment->id,
            'status' => $comment->status->value,
            'parent_id' => $comment->parent_id,
            'body' => $comment->body,
            'is_spoiler' => $comment->is_spoiler,
            'created_at' => $comment->created_at->clone()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}