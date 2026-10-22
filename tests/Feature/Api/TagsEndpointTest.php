<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TagsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->create(['role' => 'admin', 'provider_name' => null, 'provider_id' => null]);
        $this->category = Category::create(['name' => 'Anime', 'slug' => 'anime']);
    }

    private function makePost(array $extra = []): Post
    {
        return Post::factory()->create(array_merge([
            'author_id' => $this->author->id,
            'category_id' => $this->category->id,
            'subject_title' => 'Subject '.Str::random(8),
            'post_type' => 'news',
            'review_score' => null,
            'view_count' => 0,
        ], $extra));
    }

    private function makeTag(string $name, string $type, int $live, int $hidden = 0): Tag
    {
        $tag = Tag::create(['name' => $name, 'slug' => Str::slug($name), 'type' => $type]);

        for ($i = 0; $i < $live; $i++) {
            $this->makePost()->tags()->attach($tag->id);
        }

        for ($i = 0; $i < $hidden; $i++) {
            $this->makePost(['status' => 'draft', 'published_at' => null])->tags()->attach($tag->id);
        }

        return $tag;
    }

    private function seedTags(): void
    {
        $this->makeTag('Mappa', 'studio', 3, 1);
        $this->makeTag('Ufotable', 'studio', 1);
        $this->makeTag('Action', 'genre', 2);
        $this->makeTag('Unused', 'general', 0, 2); // only drafts, so invisible
    }

    /** @return list<string> */
    private function slugs(string $url): array
    {
        return $this->getJson($url)->assertOk()->json('data.*.slug') ?? [];
    }

    public function test_only_tags_with_live_posts_are_listed_and_counted(): void
    {
        $this->seedTags();

        $response = $this->getJson('/api/v1/tags')->assertOk();

        $this->assertSame(['action', 'mappa', 'ufotable'], $response->json('data.*.slug'));
        $this->assertSame([2, 3, 1], $response->json('data.*.posts_count'));

        $response
            ->assertJsonPath('data.0.type', 'genre')
            ->assertJsonPath('data.0.path', '/tag/action')
            ->assertJsonPath('meta.total', 3);
    }

    public function test_filters_sorting_and_pagination(): void
    {
        $this->seedTags();

        $this->assertSame(['mappa', 'ufotable'], $this->slugs('/api/v1/tags?type=studio'));
        $this->assertSame(['action'], $this->slugs('/api/v1/tags?type=genre'));
        $this->assertSame([], $this->slugs('/api/v1/tags?type=general'));
        $this->assertSame(['mappa', 'action', 'ufotable'], $this->slugs('/api/v1/tags?sort=popular'));

        $first = $this->getJson('/api/v1/tags?per_page=2')->assertOk();
        $this->assertSame(['action', 'mappa'], $first->json('data.*.slug'));
        $first->assertJsonPath('meta', ['page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2]);

        $this->assertSame(['ufotable'], $this->slugs('/api/v1/tags?per_page=2&page=2'));
        $this->getJson('/api/v1/tags?per_page=2&page=3')->assertStatus(404)->assertExactJson(['message' => 'Not found.']);
    }

    public function test_bad_input_is_refused(): void
    {
        $this->seedTags();

        foreach (['type=nope', 'sort=random', 'per_page=0', 'per_page=101', 'page=0', 'page=501', 'foo=1'] as $query) {
            $this->getJson('/api/v1/tags?'.$query)->assertStatus(422);
        }

        $this->getJson('/api/v1/tags?foo=1')->assertJsonValidationErrors(['foo']);

        $this->getJson('/api/v1/tags?per_page=100')->assertOk();
        $this->getJson('/api/v1/tags?type=&sort=')->assertOk();
    }

    public function test_the_detail_flags_thin_tags_and_hides_unused_ones(): void
    {
        $this->seedTags();

        $this->getJson('/api/v1/tags/mappa')
            ->assertOk()
            ->assertJsonPath('data.name', 'Mappa')
            ->assertJsonPath('data.type', 'studio')
            ->assertJsonPath('data.path', '/tag/mappa')
            ->assertJsonPath('data.posts_count', 3)
            ->assertJsonPath('data.seo.title', 'Mappa')
            ->assertJsonPath('data.seo.canonical_url', 'http://localhost:3000/tag/mappa')
            ->assertJsonPath('data.seo.noindex', false); // 3 live posts reaches the default threshold

        $this->getJson('/api/v1/tags/action')->assertJsonPath('data.seo.noindex', true);   // 2 live posts
        $this->getJson('/api/v1/tags/ufotable')->assertJsonPath('data.seo.noindex', true); // 1 live post

        foreach (['unused', 'missing', 'Bad_Slug'] as $slug) {
            $this->getJson('/api/v1/tags/'.$slug)->assertStatus(404)->assertExactJson(['message' => 'Not found.']);
        }
    }
}