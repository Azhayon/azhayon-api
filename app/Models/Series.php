<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Series extends Model
{
    use HasFactory;

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
}