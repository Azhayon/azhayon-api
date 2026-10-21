<?php

namespace App\Models;

use App\Enums\TagType;
use App\Models\Concerns\BumpsContentVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    use BumpsContentVersion, HasFactory;

    protected $fillable = ['name', 'slug', 'type'];

    protected function casts(): array
    {
        return ['type' => TagType::class];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
    }

    public function scopeOfType(Builder $query, TagType $type): Builder
    {
        return $query->where('type', $type->value);
    }
}