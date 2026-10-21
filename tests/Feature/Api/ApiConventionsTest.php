<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class ApiConventionsTest extends TestCase
{
    public function test_unknown_api_routes_get_a_generic_json_404(): void
    {
        $this->getJson('/api/v1/nope')
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Not found.']);

        // Without an Accept header too.
        $this->get('/api/v1/nope')
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Not found.']);
    }
}