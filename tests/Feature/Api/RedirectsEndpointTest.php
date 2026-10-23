<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Post;
use App\Models\Redirect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RedirectsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirects_are_listed_without_internal_fields(): void
    {
        Redirect::create(['from_path' => '/blog/old-one', 'to_path' => '/blog/new-one', 'status_code' => 301]);
        Redirect::create(['from_path' => '/promo/', 'to_path' => 'https://example.com/offer', 'status_code' => 302]);

        $response = $this->getJson('/api/v1/redirects')->assertOk();

        $response
            ->assertExactJson([
                'data' => [
                    ['from' => '/blog/old-one', 'to' => '/blog/new-one', 'status' => 301],
                    ['from' => '/promo', 'to' => 'https://example.com/offer', 'status' => 302],
                ],
                'meta' => ['total' => 2, 'truncated' => false],
            ]);
    }

    public function test_an_empty_list_is_fine(): void
    {
        $this->getJson('/api/v1/redirects')
            ->assertOk()
            ->assertExactJson(['data' => [], 'meta' => ['total' => 0, 'truncated' => false]]);
    }

    public function test_the_list_is_capped_and_says_so(): void
    {
        config(['azhayon.api.redirects_limit' => 2]);

        foreach (['/a', '/b', '/c'] as $from) {
            Redirect::create(['from_path' => $from, 'to_path' => '/target', 'status_code' => 301]);
        }

        $response = $this->getJson('/api/v1/redirects')->assertOk();

        $this->assertSame(['/a', '/b'], $response->json('data.*.from'));
        $response->assertJsonPath('meta', ['total' => 3, 'truncated' => true]);
    }

    public function test_the_list_uses_short_cache_lifetimes_and_follows_changes(): void
    {
        $response = $this->getJson('/api/v1/redirects')->assertOk();
        $cache = (string) $response->headers->get('Cache-Control');

        foreach (['public', 'max-age=30', 's-maxage=60', 'stale-while-revalidate=120'] as $part) {
            $this->assertStringContainsString($part, $cache);
        }

        Redirect::create(['from_path' => '/new', 'to_path' => '/elsewhere', 'status_code' => 301]);

        $this->getJson('/api/v1/redirects')->assertJsonPath('meta.total', 1);
    }

    public function test_changing_a_live_slug_appears_in_the_list(): void
    {
        $author = User::factory()->create(['role' => 'admin', 'provider_name' => null, 'provider_id' => null]);
        $category = Category::create(['name' => 'Anime', 'slug' => 'anime']);

        $post = Post::factory()->create(['author_id' => $author->id, 'category_id' => $category->id, 'slug' => 'before']);

        $this->getJson('/api/v1/redirects')->assertJsonPath('meta.total', 0);

        $post->update(['slug' => 'after']);

        $this->getJson('/api/v1/redirects')
            ->assertJsonPath('data', [['from' => '/blog/before', 'to' => '/blog/after', 'status' => 301]]);
    }
}