<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QueryParameterRejectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_public_endpoint_refuses_unknown_query_parameters(): void
    {
        $urls = [
            '/api/v1/site',
            '/api/v1/home',
            '/api/v1/categories',
            '/api/v1/categories/anime',
            '/api/v1/tags/mappa',
            '/api/v1/series/solo-leveling',
            '/api/v1/posts/some-post',
            '/api/v1/sitemap',
            '/api/v1/sitemap/taxonomy',
            '/api/v1/redirects',
        ];

        foreach ($urls as $url) {
            $this->getJson($url.'?cache_buster=123')
                ->assertStatus(422)
                ->assertJsonValidationErrors(['cache_buster']);
        }
    }

    public function test_the_same_endpoints_still_work_without_parameters(): void
    {
        Category::create(['name' => 'Anime', 'slug' => 'anime']);

        foreach ([
            '/api/v1/site',
            '/api/v1/home',
            '/api/v1/categories',
            '/api/v1/categories/anime',
            '/api/v1/sitemap',
            '/api/v1/sitemap/taxonomy',
            '/api/v1/redirects',
        ] as $url) {
            $this->getJson($url)->assertOk();
        }

        foreach (['/api/v1/tags/mappa', '/api/v1/series/solo-leveling', '/api/v1/posts/some-post'] as $url) {
            $this->getJson($url)->assertStatus(404);
        }
    }
}