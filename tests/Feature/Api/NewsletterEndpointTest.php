<?php

namespace Tests\Feature\Api;

use App\Enums\NewsletterStatus;
use App\Mail\ConfirmNewsletterMail;
use App\Models\NewsletterSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Support\AssertsApiShape;
use Tests\TestCase;

class NewsletterEndpointTest extends TestCase
{
    use AssertsApiShape;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    private function subscribe(string $email = 'reader@example.test'): TestResponse
    {
        return $this->postJson('/api/v1/newsletter/subscribe', ['email' => $email]);
    }

    private function mailedToken(): string
    {
        $mail = Mail::queued(ConfirmNewsletterMail::class)->last();

        parse_str((string) parse_url($mail->url, PHP_URL_QUERY), $query);

        return $query['token'];
    }

    public function test_subscribing_creates_a_pending_subscriber_and_queues_one_email(): void
    {
        $this->subscribe('reader@example.test')->assertStatus(202);

        $subscriber = NewsletterSubscriber::query()->where('email', 'reader@example.test')->first();

        $this->assertSame(NewsletterStatus::Pending, $subscriber->status);
        Mail::assertQueued(ConfirmNewsletterMail::class, 1);
        Mail::assertQueued(ConfirmNewsletterMail::class, fn (ConfirmNewsletterMail $mail) => $mail->hasTo('reader@example.test')
            && str_contains($mail->url, '/newsletter/confirm?token='));
    }

    public function test_the_address_is_trimmed_and_lowercased(): void
    {
        $this->subscribe('  Reader@Example.TEST ')->assertStatus(202);

        $this->assertSame(1, NewsletterSubscriber::query()->where('email', 'reader@example.test')->count());
    }

    public function test_an_invalid_address_is_refused_and_nothing_is_sent(): void
    {
        $this->subscribe('not-an-email')->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertSame(0, NewsletterSubscriber::query()->count());
        Mail::assertNothingQueued();
    }

    public function test_a_missing_address_is_refused(): void
    {
        $this->postJson('/api/v1/newsletter/subscribe', [])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_an_unknown_query_parameter_is_refused(): void
    {
        $this->postJson('/api/v1/newsletter/subscribe?utm=x', ['email' => 'reader@example.test'])->assertStatus(422);
    }

    public function test_a_confirmed_address_gets_the_same_answer_and_no_email(): void
    {
        $new = $this->subscribe('new@example.test');

        NewsletterSubscriber::factory()->create(['email' => 'old@example.test', 'status' => NewsletterStatus::Confirmed]);
        $old = $this->subscribe('old@example.test');

        $old->assertStatus(202);
        $this->assertSame($new->json(), $old->json());
        Mail::assertQueued(ConfirmNewsletterMail::class, 1);
    }

    public function test_a_second_request_within_five_minutes_sends_nothing_new(): void
    {
        $this->subscribe()->assertStatus(202);
        $this->subscribe()->assertStatus(202);
        Mail::assertQueued(ConfirmNewsletterMail::class, 1);

        $this->travel(6)->minutes();

        $this->subscribe()->assertStatus(202);
        Mail::assertQueued(ConfirmNewsletterMail::class, 2);
    }

    public function test_the_mailed_token_confirms_the_subscription_and_can_be_used_twice(): void
    {
        $this->subscribe()->assertStatus(202);
        $token = $this->mailedToken();

        $this->postJson('/api/v1/newsletter/confirm', ['token' => $token])->assertOk();
        $this->postJson('/api/v1/newsletter/confirm', ['token' => $token])->assertOk();

        $this->assertSame(
            NewsletterStatus::Confirmed,
            NewsletterSubscriber::query()->where('email', 'reader@example.test')->first()->status
        );
    }

    public function test_an_unknown_confirmation_token_is_refused(): void
    {
        $this->postJson('/api/v1/newsletter/confirm', ['token' => str_repeat('a', 48)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');
    }

    public function test_an_expired_confirmation_token_is_refused(): void
    {
        $this->subscribe()->assertStatus(202);
        $token = $this->mailedToken();

        $this->travel(49)->hours();

        $this->postJson('/api/v1/newsletter/confirm', ['token' => $token])->assertStatus(422);
    }

    public function test_unsubscribing_works_and_can_be_repeated(): void
    {
        $subscriber = NewsletterSubscriber::factory()->create(['email' => 'reader@example.test', 'status' => NewsletterStatus::Confirmed]);

        $this->postJson('/api/v1/newsletter/unsubscribe', ['token' => $subscriber->unsubscribe_token])->assertOk();
        $this->postJson('/api/v1/newsletter/unsubscribe', ['token' => $subscriber->unsubscribe_token])->assertOk();

        $this->assertSame(NewsletterStatus::Unsubscribed, $subscriber->refresh()->status);
    }

    public function test_an_unknown_unsubscribe_token_is_a_404(): void
    {
        $this->postJson('/api/v1/newsletter/unsubscribe', ['token' => str_repeat('a', 40)])->assertNotFound();
    }

    public function test_subscribing_is_limited_to_three_requests_a_minute(): void
    {
        foreach (['a', 'b', 'c'] as $name) {
            $this->subscribe($name.'@example.test')->assertStatus(202);
        }

        $this->subscribe('d@example.test')->assertStatus(429);
    }

    public function test_responses_match_the_contract_and_are_never_cached(): void
    {
        $response = $this->subscribe();

        $response->assertStatus(202);
        $this->assertApiShape('NewsletterResponse', $response->json(), 'subscribe');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}