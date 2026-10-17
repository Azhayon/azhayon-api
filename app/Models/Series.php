<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\HasProcessedImages;
use App\Support\Images\ImageProcessor;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Series extends Model
{
    use HasFactory, HasProcessedImages;

    protected $table = 'series';

    protected $fillable = [
        'title',
        'slug',
        'description',
        'cover_image',
        'cover_width',
        'cover_height',
        'meta_title',
        'meta_description',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    protected function processedImageFields(): array
    {
        return ['cover_image' => ['cover_width', 'cover_height']];
    }

    /** Smallest variant of the cover, for admin table thumbnails. */
    protected function coverThumb(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->cover_image
            ? app(ImageProcessor::class)->smallestVariant($this->cover_image)
            : null);
    }

}