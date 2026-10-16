<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google' => [
                'client_id' => 'test-google-id',
                'client_secret' => 'secret',
                'redirect' => 'http://127.0.0.1:8000/api/v1/auth/google/callback',
            ],
            'services.discord' => [
                'client_id' => 'test-discord-id',
                'client_secret' => 'secret',
                'redirect' => 'http://127.0.0.1:8000/api/v1/auth/discord/callback',
            ],
        ]);
    }

    private function challenge(): string
    {
        return hash('sha256', 'test-verifier');
    }

    private function state(string $provider, ?int $expires = null): string
    {
        return Crypt::encryptString(json_encode([
            'p' => $provider,
            'c' => $this->challenge(),
            'e' => $expires ?? now()->addMinutes(5)->timestamp,
        ]));
    }

    private function callbackUrl(string $provider, ?string $state = null): string
    {
        return "/api/v1/auth/{$provider}/callback?state=".urlencode($state ?? $this->state($provider));
    }

    /** @return array<string, string> */
    private function locationParams($response): array
    {
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $params);

        return $params;
    }

    private function mockDriver(string $provider): MockInterface
    {
        $driver = Mockery::mock(AbstractProvider::class);
        $driver->shouldReceive('stateless')->andReturnSelf();
        Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);

        return $driver;
    }

    private function fakeProviderUser(string $provider, array $attributes, array $raw): void
    {
        $social = (new SocialiteUser)->setRaw($raw)->map($attributes + [
            'nickname' => null,
            'avatar' => null,
        ]);

        $this->mockDriver($provider)->shouldReceive('user')->andReturn($social);
    }

    public function test_unknown_provider_is_not_found(): void
    {
        $this->get('/api/v1/auth/github/redirect?challenge='.$this->challenge())->assertNotFound();
        $this->get('/api/v1/auth/github/callback')->assertNotFound();
    }

    public function test_redirect_rejects_a_malformed_challenge(): void
    {
        $this->get('/api/v1/auth/google/redirect?challenge=abc')
            ->assertRedirect('http://localhost:3000/auth/callback?error=invalid_request');

        $this->get('/api/v1/auth/google/redirect')
            ->assertRedirect('http://localhost:3000/auth/callback?error=invalid_request');
    }

    public function test_redirect_sends_the_visitor_to_google_with_a_bound_state(): void
    {
        $response = $this->get('/api/v1/auth/google/redirect?challenge='.$this->challenge());

        $response->assertRedirect();
        $location = $response->headers->get('Location');

        $this->assertSame('accounts.google.com', parse_url($location, PHP_URL_HOST));

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('test-google-id', $query['client_id']);

        $state = json_decode(Crypt::decryptString($query['state']), true);
        $this->assertSame('google', $state['p']);
        $this->assertSame($this->challenge(), $state['c']);
    }

    public function test_redirect_sends_the_visitor_to_discord(): void
    {
        $response = $this->get('/api/v1/auth/discord/redirect?challenge='.$this->challenge());

        $response->assertRedirect();
        $this->assertSame('discord.com', parse_url($response->headers->get('Location'), PHP_URL_HOST));
    }

    public function test_callback_rejects_bad_state(): void
    {
        $cases = [
            'garbage' => 'garbage',
            'missing' => '',
            'wrong provider' => $this->state('discord'),
            'expired' => $this->state('google', now()->subMinute()->timestamp),
        ];

        foreach ($cases as $label => $state) {
            $response = $this->get($this->callbackUrl('google', $state));

            $this->assertSame('invalid_state', $this->locationParams($response)['error'] ?? null, $label);
        }
    }

    public function test_callback_reports_provider_denial(): void
    {
        $url = $this->callbackUrl('google').'&error=access_denied';

        $this->assertSame('access_denied', $this->locationParams($this->get($url))['error']);
    }

    public function test_callback_reports_provider_failure(): void
    {
        $this->mockDriver('google')->shouldReceive('user')->andThrow(new \RuntimeException('boom'));

        $this->assertSame('oauth_failed', $this->locationParams($this->get($this->callbackUrl('google')))['error']);
    }

    public function test_callback_creates_a_user_and_issues_a_bound_one_time_code(): void
    {
        $this->fakeProviderUser(
            'google',
            ['id' => 'g-1', 'name' => 'Test Reader', 'email' => 'reader@example.test', 'avatar' => 'https://example.test/a.png'],
            ['email_verified' => true],
        );

        $params = $this->locationParams($this->get($this->callbackUrl('google')));

        $this->assertArrayHasKey('code', $params);
        $this->assertSame(64, strlen($params['code']));

        $user = User::where('provider_name', 'google')->where('provider_id', 'g-1')->firstOrFail();
        $this->assertSame('user', $user->role->value);
        $this->assertSame('reader@example.test', $user->email);
        $this->assertNotNull($user->email_verified_at);

        $payload = Cache::get('auth_code:'.hash('sha256', $params['code']));
        $this->assertSame($user->id, $payload['user_id']);
        $this->assertSame($this->challenge(), $payload['challenge']);
    }

    public function test_callback_never_links_to_an_existing_account_by_email(): void
    {
        $existing = User::factory()->create([
            'email' => 'taken@example.test',
            'provider_name' => null,
            'provider_id' => null,
        ]);

        $this->fakeProviderUser(
            'google',
            ['id' => 'g-2', 'name' => 'Someone Else', 'email' => 'taken@example.test'],
            ['email_verified' => true],
        );

        $params = $this->locationParams($this->get($this->callbackUrl('google')));
        $this->assertArrayHasKey('code', $params);

        $new = User::where('provider_id', 'g-2')->firstOrFail();
        $this->assertNull($new->email);
        $this->assertNotSame($existing->id, $new->id);
        $this->assertNull($existing->fresh()->provider_id);
    }

    public function test_unverified_email_is_not_stored(): void
    {
        $this->fakeProviderUser(
            'google',
            ['id' => 'g-4', 'name' => 'Unverified', 'email' => 'unverified@example.test'],
            ['email_verified' => false],
        );

        $this->get($this->callbackUrl('google'));

        $user = User::where('provider_id', 'g-4')->firstOrFail();
        $this->assertNull($user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_discord_verified_email_is_stored(): void
    {
        $this->fakeProviderUser(
            'discord',
            ['id' => 'd-1', 'name' => 'Discord Reader', 'email' => 'discord@example.test'],
            ['verified' => true],
        );

        $this->get($this->callbackUrl('discord'));

        $this->assertSame('discord@example.test', User::where('provider_id', 'd-1')->firstOrFail()->email);
    }

    public function test_banned_user_gets_no_code(): void
    {
        User::factory()->create([
            'provider_name' => 'google',
            'provider_id' => 'g-3',
            'banned_at' => now(),
        ]);

        $this->fakeProviderUser('google', ['id' => 'g-3', 'name' => 'Banned', 'email' => null], []);

        $params = $this->locationParams($this->get($this->callbackUrl('google')));

        $this->assertSame('banned', $params['error']);
        $this->assertArrayNotHasKey('code', $params);
    }

    public function test_deleted_account_cannot_sign_in(): void
    {
        $user = User::factory()->create(['provider_name' => 'google', 'provider_id' => 'g-5']);
        $user->delete();

        $this->fakeProviderUser('google', ['id' => 'g-5', 'name' => 'Gone', 'email' => null], []);

        $params = $this->locationParams($this->get($this->callbackUrl('google')));

        $this->assertSame('account_disabled', $params['error']);
        $this->assertArrayNotHasKey('code', $params);
    }
}