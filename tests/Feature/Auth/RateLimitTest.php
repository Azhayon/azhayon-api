<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class RateLimitTest extends TestCase
{
    public function test_oauth_routes_redirect_back_with_an_error_when_rate_limited(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->get('/api/v1/auth/google/redirect?challenge=bad')->assertRedirect();
        }

        $this->get('/api/v1/auth/google/redirect?challenge=bad')
            ->assertRedirect('http://localhost:3000/auth/callback?error=rate_limited');
    }

    public function test_code_exchange_is_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/api/v1/auth/exchange', [])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/exchange', [])
            ->assertStatus(429)
            ->assertJsonStructure(['message'])
            ->assertHeader('Retry-After');
    }
}