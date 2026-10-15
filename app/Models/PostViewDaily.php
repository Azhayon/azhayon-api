<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read model for the post_views_daily table. It has a composite primary key,
 * which Eloquent doesn't support, so writes go through query-builder upserts.
 */
class PostViewDaily extends Model
{
    protected $table = 'post_views_daily';

    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['post_id', 'date', 'views'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'views' => 'integer',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}