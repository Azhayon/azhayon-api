<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class ForwardedRateLimitTest extends TestCase
{
    private function key(): string
    {
        return str_repeat('k', 40);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['azhayon.api.frontend_key' => $this->key()]);
    }

    public function test_each_forwarded_visitor_gets_their_own_limit(): void
    {
        $as = fn (string $ip) => ['X-Frontend-Key' => $this->key(), 'X-Client-IP' => $ip];

        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/api/v1/auth/exchange', [], $as('198.51.100.1'))->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/exchange', [], $as('198.51.100.1'))->assertStatus(429);
        $this->postJson('/api/v1/auth/exchange', [], $as('198.51.100.2'))->assertStatus(422);
    }

    public function test_spoofed_ips_without_the_key_do_not_help(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->postJson('/api/v1/auth/exchange', [], ['X-Client-IP' => "198.51.100.{$i}"])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/exchange', [], ['X-Client-IP' => '198.51.100.200'])->assertStatus(429);

        $this->postJson('/api/v1/auth/exchange', [], [
            'X-Frontend-Key' => str_repeat('x', 40),
            'X-Client-IP' => '198.51.100.201',
        ])->assertStatus(429);
    }
}