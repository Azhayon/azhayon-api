<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Post;
use App\Models\Series;
use App\Models\Tag;
use App\Models\User;
use App\Support\Images\ImageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PostsListEndpointTest extends TestCase
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

    /** @return list<string> */
    private function slugs(string $url): array
    {
        return $this->getJson($url)->assertOk()->json('data.*.slug') ?? [];
    }

    public function test_the_list_shows_only_live_posts_newest_first_without_internal_fields(): void
    {
        $this->makePost(['slug' => 'older', 'published_at' => now()->subDays(3)]);
        $this->makePost(['slug' => 'newer', 'published_at' => now()->subDay()]);
        $this->makePost(['slug' => 'hidden-from-search', 'noindex' => true, 'published_at' => now()->subDays(2)]);
        $this->makePost(['slug' => 'a-draft', 'status' => 'draft', 'published_at' => null]);
        $this->makePost(['slug' => 'scheduled', 'status' => 'scheduled', 'published_at' => now()->addDay()]);
        $this->makePost(['slug' => 'future', 'published_at' => now()->addHour()]);
        $this->makePost(['slug' => 'trashed'])->delete();

        $response = $this->getJson('/api/v1/posts')->assertOk();

        $this->assertSame(['newer', 'hidden-from-search', 'older'], $response->json('data.*.slug'));

        $response
            ->assertJsonStructure([
                'data' => ['*' => [
                    'slug', 'path', 'title', 'subtitle', 'excerpt', 'type', 'subject',
                    'category' => ['name', 'slug'], 'image', 'published_at', 'updated_at',
                    'reading_time_minutes', 'rating' => ['average', 'count'], 'review_score',
                ]],
                'meta' => ['page', 'per_page', 'total', 'last_page'],
            ])
            ->assertJsonMissingPath('data.0.content')
            ->assertJsonMissingPath('data.0.id')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.path', '/blog/newer')
            ->assertJsonPath('data.0.category', ['name' => 'Anime', 'slug' => 'anime'])
            ->assertJsonPath('data.0.image', null);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $response->json('data.0.published_at'));
    }

    public function test_pagination_follows_the_approved_limits(): void
    {
        Post::factory()->count(30)->create(['author_id' => $this->author->id, 'category_id' => $this->category->id]);

        $first = $this->getJson('/api/v1/posts')->assertOk();
        $first->assertJsonPath('meta', ['page' => 1, 'per_page' => 12, 'total' => 30, 'last_page' => 3]);
        $this->assertCount(12, $first->json('data'));

        $this->assertCount(6, $this->getJson('/api/v1/posts?page=3')->assertOk()->json('data'));
        $this->assertCount(24, $this->getJson('/api/v1/posts?per_page=24')->assertOk()->json('data'));

        $this->getJson('/api/v1/posts?page=4')->assertStatus(404)->assertExactJson(['message' => 'Not found.']);

        // An empty site still answers the first page.
        Post::query()->delete();

        $this->getJson('/api/v1/posts')->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
    }

    public function test_filters_narrow_the_list(): void
    {
        $manga = Category::create(['name' => 'Manga', 'slug' => 'manga']);
        $tag = Tag::create(['name' => 'MAPPA', 'slug' => 'mappa', 'type' => 'studio']);
        $series = Series::create(['title' => 'S One', 'slug' => 's-one']);

        $a = $this->makePost(['slug' => 'a', 'post_type' => 'review', 'series_id' => $series->id]);
        $this->makePost(['slug' => 'b', 'category_id' => $manga->id]);
        $c = $this->makePost(['slug' => 'c', 'post_type' => 'review']);
        $a->tags()->attach($tag->id);
        $c->tags()->attach($tag->id);

        $same = fn (array $expected, string $url) => $this->assertEqualsCanonicalizing($expected, $this->slugs($url), $url);

        $same(['a', 'b', 'c'], '/api/v1/posts');
        $same(['b'], '/api/v1/posts?category=manga');
        $same(['a', 'c'], '/api/v1/posts?tag=mappa');
        $same(['a'], '/api/v1/posts?series=s-one');
        $same(['a', 'c'], '/api/v1/posts?type=review');
        $same(['a', 'c'], '/api/v1/posts?category=anime&tag=mappa&type=review');
        $same(['a'], '/api/v1/posts?tag=mappa&series=s-one');
        $same([], '/api/v1/posts?category=manga&type=review');
    }

    public function test_sorting_by_popularity_and_rating(): void
    {
        $this->makePost(['slug' => 'low', 'view_count' => 10, 'rating_count' => 5, 'rating_avg' => 4.0]);
        $this->makePost(['slug' => 'high', 'view_count' => 500, 'rating_count' => 3, 'rating_avg' => 4.8]);
        $this->makePost(['slug' => 'mid', 'view_count' => 50, 'rating_count' => 10, 'rating_avg' => 4.8]);
        $this->makePost(['slug' => 'few', 'view_count' => 1, 'rating_count' => 2, 'rating_avg' => 5.0]);

        $this->assertSame(['high', 'mid', 'low', 'few'], $this->slugs('/api/v1/posts?sort=popular'));

        // Top rated: at least 3 ratings, best average first, more ratings break ties.
        $this->assertSame(['mid', 'high', 'low'], $this->slugs('/api/v1/posts?sort=rating'));
    }

    public function test_bad_input_is_refused(): void
    {
        $this->makePost();

        $rejected = [
            'category=nope', 'tag=nope', 'series=nope', 'type=podcast', 'sort=random',
            'category=Bad%20Slug', 'category%5B%5D=anime', 'page=0', 'page=501', 'page=abc',
            'per_page=0', 'per_page=25', 'foo=1',
        ];

        foreach ($rejected as $query) {
            $this->getJson('/api/v1/posts?'.$query)->assertStatus(422);
        }

        $this->getJson('/api/v1/posts?foo=1')->assertJsonValidationErrors(['foo']);
        $this->getJson('/api/v1/posts?category=nope')->assertJsonValidationErrors(['category']);

        // Empty values mean "not set", not an error.
        $this->getJson('/api/v1/posts?category=&sort=')->assertOk();
    }

    public function test_cards_carry_the_featured_image_object(): void
    {
        Storage::fake('uploads');

        $image = imagecreatetruecolor(1000, 500);
        ob_start();
        imagejpeg($image);
        $path = app(ImageProcessor::class)->store((string) ob_get_clean());

        $this->makePost(['slug' => 'with-image', 'featured_image' => $path, 'featured_image_alt' => 'A cover']);

        $response = $this->getJson('/api/v1/posts')->assertOk();

        $response
            ->assertJsonPath('data.0.image.width', 1000)
            ->assertJsonPath('data.0.image.height', 500)
            ->assertJsonPath('data.0.image.alt', 'A cover');

        $this->assertSame([1000, 768, 480], $response->json('data.0.image.variants.*.width'));
        $this->assertSame([500, 384, 240], $response->json('data.0.image.variants.*.height'));
    }

    public function test_the_list_uses_a_constant_number_of_queries(): void
    {
        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/posts')->assertOk();

            return count(DB::getQueryLog());
        };

        Post::factory()->count(3)->create(['author_id' => $this->author->id, 'category_id' => $this->category->id]);
        $few = $countQueries();

        Post::factory()->count(15)->create(['author_id' => $this->author->id, 'category_id' => $this->category->id]);
        $many = $countQueries();

        $this->assertSame($few, $many);
    }

    public function test_list_responses_carry_the_approved_cache_headers(): void
    {
        $response = $this->getJson('/api/v1/posts')->assertOk();
        $cache = (string) $response->headers->get('Cache-Control');

        foreach (['public', 'max-age=60', 's-maxage=300', 'stale-while-revalidate=600'] as $part) {
            $this->assertStringContainsString($part, $cache);
        }

        $this->assertNotNull($response->headers->get('ETag'));
    }
}