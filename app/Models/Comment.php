<?php

namespace App\Models;

use App\Enums\CommentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use HasFactory, SoftDeletes;

    // status is moderation-controlled and intentionally NOT fillable.
    protected $fillable = [
        'post_id',
        'user_id',
        'parent_id',
        'body',
        'is_spoiler',
    ];

    protected function casts(): array
    {
        return [
            'status' => CommentStatus::class,
            'is_spoiler' => 'boolean',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    // withTrashed: soft-deleted users still resolve, so we can label them.
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_id');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', CommentStatus::Approved->value);
    }

    /** Deleted, missing or banned authors display as [Deleted User]. */
    protected function displayName(): Attribute
    {
        return Attribute::get(function (): string {
            $user = $this->user;

            if (! $user || $user->trashed() || $user->isBanned()) {
                return '[Deleted User]';
            }

            return $user->name;
        });
    }
}