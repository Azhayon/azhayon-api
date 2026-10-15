<?php

namespace App\Models;

use App\Enums\SubscriberStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscriber extends Model
{
    use HasFactory;

    // status, token and the timestamps are set by application code only.
    protected $fillable = ['email'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'status' => SubscriberStatus::class,
            'confirmed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }
}