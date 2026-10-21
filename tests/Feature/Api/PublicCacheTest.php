<?php

namespace Tests\Feature\Api;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_responses_are_cacheable_and_carry_an_etag(): void
    {
        $response = $this->getJson('/api/v1/site')->assertOk();

        $cache = (string) $response->headers->get('Cache-Control');

        foreach (['public', 'max-age=30', 's-maxage=60', 'stale-while-revalidate=120'] as $part) {
            $this->assertStringContainsString($part, $cache);
        }

        $this->assertMatchesRegularExpression('/^W\/"[a-f0-9]{32}"$/', (string) $response->headers->get('ETag'));
    }

    public function test_a_matching_etag_gets_an_empty_304(): void
    {
        $etag = (string) $this->getJson('/api/v1/site')->headers->get('ETag');

        $second = $this->getJson('/api/v1/site', ['If-None-Match' => $etag]);

        $second->assertStatus(304);
        $this->assertSame('', $second->getContent());
        $this->assertStringContainsString('public', (string) $second->headers->get('Cache-Control'));
    }

    public function test_a_content_change_produces_a_new_etag(): void
    {
        $etag = (string) $this->getJson('/api/v1/site')->headers->get('ETag');

        Setting::put('site', ['name' => 'Renamed', 'tagline' => 'New tagline']);

        $changed = $this->getJson('/api/v1/site', ['If-None-Match' => $etag]);

        $changed->assertOk()->assertJsonPath('data.name', 'Renamed');
        $this->assertNotSame($etag, $changed->headers->get('ETag'));
    }

    public function test_errors_are_never_marked_public(): void
    {
        $response = $this->getJson('/api/v1/nope')->assertStatus(404);

        $this->assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertNull($response->headers->get('ETag'));
    }
}