<?php

namespace Tests\Feature\Revalidation;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RevalidationSenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['azhayon.revalidate.secret' => $this->secret(), 'azhayon.revalidate.url' => null]);
    }

    private function secret(): string
    {
        return str_repeat('s', 40);
    }

    /** @param  list<string>  $tags */
    private function addPending(array $tags, int $attempts = 0, ?CarbonInterface $createdAt = null): void
    {
        DB::table('pending_revalidations')->insert(array_map(fn (string $tag): array => [
            'tag' => $tag,
            'attempts' => $attempts,
            'created_at' => $createdAt ?? now(),
        ], $tags));
    }

    private function pendingCount(): int
    {
        return DB::table('pending_revalidations')->count();
    }

    public function test_pending_tags_are_sent_signed_in_one_request_and_then_removed(): void
    {
        $this->addPending(['posts', 'home']);
        Http::fake(['*' => Http::response(['revalidated' => true], 200)]);

        $this->artisan('revalidate:flush')->expectsOutputToContain('Sent 2 tag(s)')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $timestamp = $request->header('X-Revalidate-Timestamp')[0] ?? '';
            $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), $this->secret());

            return $request->url() === 'http://localhost:3000/api/revalidate'
                && $request->method() === 'POST'
                && abs(time() - (int) $timestamp) <= 10
                && ($request->header('X-Revalidate-Signature')[0] ?? '') === $expected
                && str_contains($request->header('Content-Type')[0] ?? '', 'application/json')
                && json_decode($request->body(), true) === ['tags' => ['posts', 'home']];
        });

        $this->assertSame(0, $this->pendingCount());
    }

    public function test_failures_keep_the_tags_count_attempts_and_recover(): void
    {
        $this->addPending(['posts', 'home']);

        $status = 500;
        Http::fake(function () use (&$status) {
            return Http::response('boom', $status);
        });

        $this->artisan('revalidate:flush')->assertFailed();
        $this->assertSame([1, 1], DB::table('pending_revalidations')->orderBy('id')->pluck('attempts')->all());

        $this->artisan('revalidate:flush')->assertFailed();
        $this->assertSame([2, 2], DB::table('pending_revalidations')->orderBy('id')->pluck('attempts')->all());

        $status = 200;

        $this->artisan('revalidate:flush')->assertSuccessful();
        $this->assertSame(0, $this->pendingCount());
    }

    public function test_connection_errors_are_survived(): void
    {
        $this->addPending(['posts']);
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->artisan('revalidate:flush')->assertFailed();

        $this->assertSame(1, $this->pendingCount());
    }

    public function test_redirect_responses_are_never_followed_and_count_as_failures(): void
    {
        $this->addPending(['posts']);
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://evil.test/steal'])]);

        $this->artisan('revalidate:flush')->assertFailed();

        Http::assertSentCount(1);
        $this->assertSame(1, $this->pendingCount());
    }

    public function test_nothing_is_sent_when_nothing_is_pending(): void
    {
        Http::fake();

        $this->artisan('revalidate:flush')->expectsOutputToContain('Nothing to send')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_nothing_is_sent_when_revalidation_is_not_configured(): void
    {
        $this->addPending(['posts']);
        config(['azhayon.revalidate.secret' => null]);
        Http::fake();

        $this->artisan('revalidate:flush')->expectsOutputToContain('not configured')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(1, $this->pendingCount());
    }

    public function test_a_flood_of_tags_collapses_into_all(): void
    {
        config(['azhayon.revalidate.max_pending' => 3]);
        $this->addPending(['a', 'b', 'c', 'd', 'e']);
        Http::fake(['*' => Http::response('', 200)]);

        $this->artisan('revalidate:flush')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true) === ['tags' => ['all']]);
        $this->assertSame(0, $this->pendingCount());
    }

    public function test_tags_that_keep_failing_or_are_very_old_are_dropped(): void
    {
        $this->addPending(['too-many-tries'], attempts: 60);
        $this->addPending(['too-old'], createdAt: now()->subHours(25));
        $this->addPending(['fresh']);
        Http::fake(['*' => Http::response('', 200)]);

        $this->artisan('revalidate:flush')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true) === ['tags' => ['fresh']]);
        $this->assertSame(0, $this->pendingCount());
    }

    public function test_revalidate_all_sends_everything_straight_away(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $this->artisan('revalidate:all')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true) === ['tags' => ['all']]);
    }

    public function test_the_flush_is_scheduled_every_minute(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('revalidate:flush')->assertSuccessful();
    }
}