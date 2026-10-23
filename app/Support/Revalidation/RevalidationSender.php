<?php

namespace App\Support\Revalidation;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RevalidationSender
{
    private const TABLE = 'pending_revalidations';

    /**
     * Sends everything pending in one signed request.
     *
     * @return array{status: 'not-configured'|'nothing'|'sent'|'failed', tags: int, dropped: int}
     */
    public function flush(): array
    {
        if (! PendingRevalidation::configured()) {
            return ['status' => 'not-configured', 'tags' => 0, 'dropped' => 0];
        }

        $dropped = $this->dropStale();
        $total = DB::table(self::TABLE)->count();

        if ($total === 0) {
            return ['status' => 'nothing', 'tags' => 0, 'dropped' => $dropped];
        }

        // This attempt covers every row up to here; rows added meanwhile are left for the next one.
        $maxId = (int) DB::table(self::TABLE)->max('id');
        $covered = fn () => DB::table(self::TABLE)->where('id', '<=', $maxId);

        $tags = $total > (int) config('azhayon.revalidate.max_pending')
            ? ['all']
            : $covered()->orderBy('id')->pluck('tag')->all();

        if ($this->send($tags)) {
            $covered()->delete();

            return ['status' => 'sent', 'tags' => count($tags), 'dropped' => $dropped];
        }

        $covered()->increment('attempts');

        return ['status' => 'failed', 'tags' => count($tags), 'dropped' => $dropped];
    }

    /** Tags that keep failing, or are very old, are given up on. */
    private function dropStale(): int
    {
        $dropped = DB::table(self::TABLE)
            ->where('attempts', '>=', (int) config('azhayon.revalidate.max_attempts'))
            ->orWhere('created_at', '<', now()->subHours((int) config('azhayon.revalidate.max_age_hours')))
            ->delete();

        if ($dropped > 0) {
            Log::error('Gave up delivering revalidation tags to the frontend', ['count' => $dropped]);
        }

        return $dropped;
    }

    /** @param  list<string>  $tags */
    private function send(array $tags): bool
    {
        $body = json_encode(['tags' => array_values($tags)], JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, (string) config('azhayon.revalidate.secret'));

        try {
            $response = Http::withBody($body, 'application/json')
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'AzhayonMedia-Revalidator/1.0',
                    'X-Revalidate-Timestamp' => $timestamp,
                    'X-Revalidate-Signature' => 'sha256='.$signature,
                ])
                ->timeout(8)
                ->connectTimeout(4)
                ->withOptions(['allow_redirects' => false])
                ->post((string) PendingRevalidation::endpoint());
        } catch (ConnectionException) {
            Log::warning('Frontend revalidation could not connect');

            return false;
        } catch (Throwable $e) {
            Log::warning('Frontend revalidation failed', ['exception' => $e::class]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Frontend revalidation was refused', ['status' => $response->status()]);

            return false;
        }

        return true;
    }
}