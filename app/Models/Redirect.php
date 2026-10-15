<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Redirect extends Model
{
    // hits is incremented by application code only.
    protected $fillable = ['from_path', 'to_path', 'status_code'];

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'hits' => 'integer',
        ];
    }
}