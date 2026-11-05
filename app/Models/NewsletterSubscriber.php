<?php

namespace App\Models;

use App\Enums\NewsletterStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class NewsletterSubscriber extends Model
{
    use HasFactory;

    public const CONFIRM_HOURS = 48;

    protected $attributes = [
        'status' => 'pending',
    ];

    // Only the address is fillable: status and tokens are set by the methods below.
    protected $fillable = ['email'];

    protected $hidden = ['confirm_token_hash', 'unsubscribe_token'];

    protected function casts(): array
    {
        return [
            'status' => NewsletterStatus::class,
            'confirm_expires_at' => 'datetime',
            'last_confirmation_sent_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $subscriber): void {
            $subscriber->email = self::normalizeEmail((string) $subscriber->email);

            if (empty($subscriber->unsubscribe_token)) {
                $subscriber->unsubscribe_token = Str::random(40);
            }
        });
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', NewsletterStatus::Confirmed->value);
    }

    /**
     * Starts (or restarts) confirmation and returns the raw token for the email.
     * Only the hash is stored. The caller must not use this for an already confirmed subscriber.
     */
    public function requestConfirmation(): string
    {
        $token = Str::random(48);

        $this->forceFill([
            'status' => NewsletterStatus::Pending,
            'confirm_token_hash' => hash('sha256', $token),
            'confirm_expires_at' => now()->addHours(self::CONFIRM_HOURS),
            'last_confirmation_sent_at' => now(),
            'confirmed_at' => null,
            'unsubscribed_at' => null,
        ])->save();

        return $token;
    }

    public static function findByConfirmToken(string $token): ?self
    {
        if ($token === '' || strlen($token) > 100) {
            return null;
        }

        return self::query()->where('confirm_token_hash', hash('sha256', $token))->first();
    }

    public static function findByUnsubscribeToken(string $token): ?self
    {
        if (strlen($token) !== 40) {
            return null;
        }

        $found = self::query()->where('unsubscribe_token', $token)->first();

        // The database comparison ignores case; this one does not.
        return $found !== null && hash_equals($found->unsubscribe_token, $token) ? $found : null;
    }

    /** True when the subscriber is now confirmed. Opening the same link twice stays true. */
    public function confirm(): bool
    {
        if ($this->status === NewsletterStatus::Confirmed) {
            return true;
        }

        if ($this->status !== NewsletterStatus::Pending) {
            return false;
        }

        if ($this->confirm_expires_at === null || $this->confirm_expires_at->isPast()) {
            return false;
        }

        $this->forceFill([
            'status' => NewsletterStatus::Confirmed,
            'confirmed_at' => now(),
        ])->save();

        return true;
    }

    public function unsubscribe(): void
    {
        if ($this->status === NewsletterStatus::Unsubscribed) {
            return;
        }

        $this->forceFill([
            'status' => NewsletterStatus::Unsubscribed,
            'unsubscribed_at' => now(),
            'confirm_token_hash' => null,
            'confirm_expires_at' => null,
        ])->save();
    }
}