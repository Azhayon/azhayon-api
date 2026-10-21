<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use App\Models\Concerns\BumpsContentVersion;

class Redirect extends Model
{
    use BumpsContentVersion;
    // hits is incremented by application code only.
    protected $fillable = ['from_path', 'to_path', 'status_code'];

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'hits' => 'integer',
        ];
    }

    /** Removes a trailing slash, so "/old/" and "/old" are the same redirect. */
    public static function normalizePath(string $path): string
    {
        $path = trim($path);

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    protected function fromPath(): Attribute
    {
        return Attribute::set(fn (string $value): string => self::normalizePath($value));
    }

    protected function toPath(): Attribute
    {
        // Only site paths are normalised; full URLs are kept as typed.
        return Attribute::set(fn (string $value): string => str_starts_with($value, '/')
            ? self::normalizePath($value)
            : trim($value));
    }
}