<?php

namespace App\Models;

use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Models\Concerns\BumpsContentVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\HasProcessedImages;
use App\Observers\PostObserver;
use App\Support\Images\ImageProcessor;
use App\Support\Seo\PostAuditor;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;

#[ObservedBy([PostObserver::class])]
class Post extends Model
{
    use BumpsContentVersion, HasFactory, HasProcessedImages, SoftDeletes;

    protected $attributes = [
        'post_type' => 'news',
        'status' => 'draft',
        'noindex' => false,
    ];

    // Counters (view_count, rating_avg, rating_count, reading_time_minutes) are
    // system-managed and intentionally NOT fillable.
    protected $fillable = [
        'author_id',
        'category_id',
        'series_id',
        'title',
        'subtitle',
        'slug',
        'excerpt',
        'content',
        'subject_title',
        'post_type',
        'featured_image',
        'featured_image_alt',
        'featured_image_width',
        'featured_image_height',
        'meta_title',
        'meta_description',
        'og_image',
        'canonical_url',
        'noindex',
        'review_score',
        'status',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'post_type' => PostType::class,
            'status' => PostStatus::class,
            'noindex' => 'boolean',
            'review_score' => 'decimal:1',
            'rating_avg' => 'decimal:2',
            'published_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(PostViewDaily::class);
    }

    /** Only posts that are live right now. Every public query must use this. */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', PostStatus::Published->value)
            ->where('published_at', '<=', now());
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    protected function processedImageFields(): array
    {
        return [
            'featured_image' => ['featured_image_width', 'featured_image_height'],
            'og_image' => [null, null],
        ];
    }

    /** Smallest variant of the featured image, for admin table thumbnails. */
    protected function featuredThumb(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->featured_image
            ? app(ImageProcessor::class)->smallestVariant($this->featured_image)
            : null);
    }

    /** @return list<string> Plain-language SEO and link problems, for the content health page. */
    protected function auditMessages(): Attribute
    {
        return Attribute::get(fn (): array => app(PostAuditor::class)->messages($this));
    }
}