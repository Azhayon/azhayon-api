<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CategoriesEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->create(['role' => 'admin', 'provider_name' => null, 'provider_id' => null]);
    }

    private function makePost(Category $category, array $extra = []): Post
    {
        return Post::factory()->create(array_merge([
            'author_id' => $this->author->id,
            'category_id' => $category->id,
            'subject_title' => 'Subject '.Str::random(8),
            'post_type' => 'news',
            'review_score' => null,
            'view_count' => 0,
        ], $extra));
    }

    public function test_the_list_counts_only_live_posts(): void
    {
        $anime = Category::create(['name' => 'Anime', 'slug' => 'anime', 'description' => 'All about anime.']);
        Category::create(['name' => 'Movies', 'slug' => 'movies']);

        $this->makePost($anime);
        $this->makePost($anime);
        $this->makePost($anime, ['status' => 'draft', 'published_at' => null]);
        $this->makePost($anime, ['status' => 'scheduled', 'published_at' => now()->addDay()]);
        $this->makePost($anime, ['published_at' => now()->addHour()]);
        $this->makePost($anime)->delete();

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Anime')
            ->assertJsonPath('data.0.slug', 'anime')
            ->assertJsonPath('data.0.path', '/category/anime')
            ->assertJsonPath('data.0.description', 'All about anime.')
            ->assertJsonPath('data.0.posts_count', 2)
            ->assertJsonPath('data.1.slug', 'movies')
            ->assertJsonPath('data.1.description', null)
            ->assertJsonPath('data.1.posts_count', 0);
    }

    public function test_the_detail_describes_one_category(): void
    {
        $anime = Category::create(['name' => 'Anime', 'slug' => 'anime', 'description' => 'All about anime.']);
        $this->makePost($anime);

        $response = $this->getJson('/api/v1/categories/anime')->assertOk();

        $response
            ->assertJsonPath('data.name', 'Anime')
            ->assertJsonPath('data.posts_count', 1)
            ->assertJsonPath('data.seo.title', 'Anime')
            ->assertJsonPath('data.seo.description', 'All about anime.')
            ->assertJsonPath('data.seo.canonical_url', 'http://localhost:3000/category/anime')
            ->assertJsonPath('data.seo.noindex', false);

        $this->assertStringContainsString('s-maxage=300', (string) $response->headers->get('Cache-Control'));

        foreach (['missing', 'Bad_Slug', 'UPPER'] as $slug) {
            $this->getJson('/api/v1/categories/'.$slug)->assertStatus(404)->assertExactJson(['message' => 'Not found.']);
        }
    }

    public function test_changes_show_up_despite_caching(): void
    {
        $anime = Category::create(['name' => 'Anime', 'slug' => 'anime']);

        $this->getJson('/api/v1/categories')->assertJsonPath('data.0.posts_count', 0);

        $this->makePost($anime);
        $this->getJson('/api/v1/categories')->assertJsonPath('data.0.posts_count', 1);

        $anime->update(['name' => 'Anime & Donghua']);
        $this->getJson('/api/v1/categories')->assertJsonPath('data.0.name', 'Anime & Donghua');
    }
}