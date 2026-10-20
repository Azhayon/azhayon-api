<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LinkCheck extends Model
{
    public const PENDING = 'pending';

    public const OK = 'ok';

    public const MOVED = 'moved';

    public const FAILING = 'failing';

    public const BROKEN = 'broken';

    public const UNVERIFIABLE = 'unverifiable';

    // System-managed: written with forceFill by the link registry and the checker.
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'failures' => 'integer',
            'checked_at' => 'datetime',
            'next_check_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public static function hash(string $url): string
    {
        return hash('sha256', $url);
    }
}