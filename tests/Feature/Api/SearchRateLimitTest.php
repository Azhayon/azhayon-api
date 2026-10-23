<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function key(): string
    {
        return str_repeat('k', 40);
    }

    public function test_suggestions_allow_sixty_requests_a_minute_per_visitor(): void
    {
        config(['azhayon.api.frontend_key' => $this->key()]);

        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/search/suggest?q=ab')->assertOk();
        }

        $this->getJson('/api/v1/search/suggest?q=ab')->assertStatus(429)->assertHeader('Retry-After');

        // A different visitor, forwarded by our frontend, has their own allowance.
        $this->getJson('/api/v1/search/suggest?q=ab', ['X-Frontend-Key' => $this->key(), 'X-Client-IP' => '198.51.100.9'])
            ->assertOk();
    }

    public function test_full_search_allows_thirty_requests_a_minute_per_visitor(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/v1/search?q=ab')->assertOk();
        }

        $this->getJson('/api/v1/search?q=ab')->assertStatus(429)->assertHeader('Retry-After');
    }
}