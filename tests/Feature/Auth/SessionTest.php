<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class SessionTest extends TestCase
{
    use RefreshDatabase;

    private const VERIFIER = 'correct-verifier-correct-verifier-1234';

    private const WRONG_VERIFIER = 'wrong-verifier-wrong-verifier-123456';

    private function issueCode(User $user, string $verifier = self::VERIFIER): string
    {
        $code = Str::random(64);

        Cache::put(
            'auth_code:'.hash('sha256', $code),
            ['user_id' => $user->id, 'challenge' => hash('sha256', $verifier)],
            now()->addSeconds(60),
        );

        return $code;
    }

    private function exchange(string $code, string $verifier = self::VERIFIER)
    {
        return $this->postJson('/api/v1/auth/exchange', ['code' => $code, 'verifier' => $verifier]);
    }

    public function test_exchange_returns_a_working_token(): void
    {
        $user = User::factory()->create();

        $response = $this->exchange($this->issueCode($user));

        $response->assertOk()
            ->assertJsonStructure(['token', 'expires_at', 'user' => ['id', 'name', 'email', 'avatar_url', 'role']])
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', 'user');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $expires = \Carbon\Carbon::parse($response->json('expires_at'));
        $this->assertEqualsWithDelta(
            now()->addDays(config('azhayon.token_ttl_days'))->timestamp,
            $expires->timestamp,
            60,
        );

        $this->withToken($response->json('token'))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_a_code_can_only_be_used_once(): void
    {
        $code = $this->issueCode(User::factory()->create());

        $this->exchange($code)->assertOk();
        $this->exchange($code)->assertStatus(400);
    }

    public function test_a_wrong_verifier_burns_the_code(): void
    {
        $code = $this->issueCode(User::factory()->create());

        $this->exchange($code, self::WRONG_VERIFIER)->assertStatus(400);
        $this->exchange($code)->assertStatus(400);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unknown_and_malformed_codes_are_rejected(): void
    {
        $this->exchange(Str::random(64))->assertStatus(400);

        $this->postJson('/api/v1/auth/exchange', [])->assertStatus(422);
        $this->postJson('/api/v1/auth/exchange', ['code' => 'short', 'verifier' => 'short'])->assertStatus(422);
    }

    public function test_a_banned_user_cannot_redeem_a_code(): void
    {
        $user = User::factory()->create(['banned_at' => now()]);

        $this->exchange($this->issueCode($user))->assertStatus(400);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_me_requires_authentication_and_returns_json(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);

        // No Accept header: must still be a JSON 401, never a redirect or a 500.
        $this->get('/api/v1/me')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);

        $this->withToken('invalid-token')->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_me_never_exposes_secrets(): void
    {
        $user = User::factory()->editor()->create();
        $token = $user->createToken('web')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonStructure(['data' => ['id', 'name', 'email', 'avatar_url', 'role']])
            ->assertJsonPath('data.role', 'editor')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('web', ['*'], now()->subMinute())->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('web')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // The guard caches the resolved user within one test process.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_banning_a_user_cuts_off_live_tokens(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('web')->plainTextToken;

        $user->forceFill(['banned_at' => now()])->save();

        $this->withToken($token)->getJson('/api/v1/me')->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }
}