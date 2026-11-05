<?php

namespace Tests\Feature;

use App\Enums\NewsletterStatus;
use App\Models\NewsletterSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsletterSubscriberTest extends TestCase
{
    use RefreshDatabase;

    private function makeSubscriber(array $extra = []): NewsletterSubscriber
    {
        return NewsletterSubscriber::factory()->create(array_merge([
            'email' => 'reader@example.test',
            'status' => NewsletterStatus::Pending,
        ], $extra));
    }

    public function test_the_address_is_trimmed_and_lowercased_on_create(): void
    {
        $subscriber = NewsletterSubscriber::create(['email' => '  Reader@Example.TEST ']);

        $this->assertSame('reader@example.test', $subscriber->email);
    }

    public function test_a_new_subscriber_is_pending_and_gets_an_unsubscribe_token(): void
    {
        $subscriber = $this->makeSubscriber();

        $this->assertSame(NewsletterStatus::Pending, $subscriber->refresh()->status);
        $this->assertSame(40, strlen($subscriber->unsubscribe_token));
    }

    public function test_status_and_tokens_are_not_mass_assignable(): void
    {
        $fillable = (new NewsletterSubscriber)->getFillable();

        $this->assertSame(['email'], $fillable);
    }

    public function test_requesting_confirmation_stores_only_a_hash_and_an_expiry(): void
    {
        $subscriber = $this->makeSubscriber();

        $token = $subscriber->requestConfirmation();
        $subscriber->refresh();

        $this->assertSame(hash('sha256', $token), $subscriber->confirm_token_hash);
        $this->assertNotSame($token, $subscriber->confirm_token_hash);
        $this->assertTrue($subscriber->confirm_expires_at->between(now()->addHours(47), now()->addHours(49)));
        $this->assertNotNull($subscriber->last_confirmation_sent_at);
    }

    public function test_the_confirm_token_finds_its_subscriber_and_nobody_else(): void
    {
        $subscriber = $this->makeSubscriber();
        $token = $subscriber->requestConfirmation();

        $this->assertTrue($subscriber->is(NewsletterSubscriber::findByConfirmToken($token)));
        $this->assertNull(NewsletterSubscriber::findByConfirmToken('not-the-token'));
        $this->assertNull(NewsletterSubscriber::findByConfirmToken(''));
    }

    public function test_confirming_works_once_and_stays_true_when_repeated(): void
    {
        $subscriber = $this->makeSubscriber();
        $subscriber->requestConfirmation();

        $this->assertTrue($subscriber->refresh()->confirm());
        $this->assertSame(NewsletterStatus::Confirmed, $subscriber->refresh()->status);
        $this->assertNotNull($subscriber->confirmed_at);
        $this->assertTrue($subscriber->confirm());
    }

    public function test_an_expired_confirmation_is_refused(): void
    {
        $subscriber = $this->makeSubscriber();
        $subscriber->requestConfirmation();

        $this->travel(49)->hours();

        $this->assertFalse($subscriber->refresh()->confirm());
        $this->assertSame(NewsletterStatus::Pending, $subscriber->refresh()->status);
    }

    public function test_unsubscribing_blocks_the_old_confirmation_link(): void
    {
        $subscriber = $this->makeSubscriber();
        $token = $subscriber->requestConfirmation();
        $subscriber->refresh()->confirm();

        $subscriber->refresh()->unsubscribe();

        $this->assertSame(NewsletterStatus::Unsubscribed, $subscriber->refresh()->status);
        $this->assertNotNull($subscriber->unsubscribed_at);
        $this->assertNull(NewsletterSubscriber::findByConfirmToken($token));
        $this->assertFalse($subscriber->confirm());
    }

    public function test_asking_again_after_unsubscribing_starts_over_as_pending(): void
    {
        $subscriber = $this->makeSubscriber();
        $subscriber->requestConfirmation();
        $subscriber->refresh()->confirm();
        $subscriber->refresh()->unsubscribe();

        $subscriber->refresh()->requestConfirmation();
        $subscriber->refresh();

        $this->assertSame(NewsletterStatus::Pending, $subscriber->status);
        $this->assertNull($subscriber->unsubscribed_at);
        $this->assertNull($subscriber->confirmed_at);
    }

    public function test_the_unsubscribe_token_must_match_exactly(): void
    {
        $subscriber = $this->makeSubscriber();
        $token = $subscriber->unsubscribe_token;

        $this->assertTrue($subscriber->is(NewsletterSubscriber::findByUnsubscribeToken($token)));
        $this->assertNull(NewsletterSubscriber::findByUnsubscribeToken(strtoupper($token) === $token ? strtolower($token) : strtoupper($token)));
        $this->assertNull(NewsletterSubscriber::findByUnsubscribeToken('short'));
    }

    public function test_only_confirmed_subscribers_are_in_the_confirmed_scope(): void
    {
        $this->makeSubscriber(['email' => 'a@example.test']);
        $this->makeSubscriber(['email' => 'b@example.test', 'status' => NewsletterStatus::Confirmed]);
        $this->makeSubscriber(['email' => 'c@example.test', 'status' => NewsletterStatus::Unsubscribed]);

        $this->assertSame(['b@example.test'], NewsletterSubscriber::confirmed()->pluck('email')->all());
    }
}