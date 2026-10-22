<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Post;
use App\Models\Redirect;
use App\Models\Series;
use App\Models\Tag;
use App\Models\User;
use App\Support\Images\ImageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PostDetailEndpointTest extends TestCase
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

    private function storeImage(int $width = 1000, int $height = 500): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);

        return app(ImageProcessor::class)->store((string) ob_get_clean());
    }

    public function test_the_detail_has_everything_the_frontend_needs(): void
    {
        $series = Series::create(['title' => 'Jujutsu Kaisen', 'slug' => 'jujutsu-kaisen']);
        $tag = Tag::create(['name' => 'MAPPA', 'slug' => 'mappa', 'type' => 'studio']);

        $first = $this->makePost(['slug' => 'first', 'series_id' => $series->id, 'published_at' => now()->subDays(3)]);
        $middle = $this->makePost([
            'slug' => 'middle',
            'title' => 'The middle post',
            'series_id' => $series->id,
            'published_at' => now()->subDays(2),
            'meta_title' => 'Custom SEO title',
            'meta_description' => 'Custom SEO description',
            'post_type' => 'review',
            'review_score' => 8.5,
            'content' => ['type' => 'doc', 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello world']]],
            ]],
        ]);
        $this->makePost(['slug' => 'last', 'series_id' => $series->id, 'published_at' => now()->subDay()]);
        $middle->tags()->attach($tag->id);

        $response = $this->getJson('/api/v1/posts/middle')->assertOk();

        $response
            ->assertJsonPath('data.title', 'The middle post')
            ->assertJsonPath('data.path', '/blog/middle')
            ->assertJsonPath('data.type', 'review')
            ->assertJsonPath('data.review_score', 8.5)
            ->assertJsonPath('data.content.type', 'doc')
            ->assertJsonPath('data.content.content.0.content.0.text', 'Hello world')
            ->assertJsonPath('data.author', ['name' => $this->author->name])
            ->assertJsonPath('data.tags', [['name' => 'MAPPA', 'slug' => 'mappa', 'type' => 'studio']])
            ->assertJsonPath('data.seo.title', 'Custom SEO title')
            ->assertJsonPath('data.seo.description', 'Custom SEO description')
            ->assertJsonPath('data.seo.canonical_url', 'http://localhost:3000/blog/middle')
            ->assertJsonPath('data.seo.noindex', false)
            ->assertJsonPath('data.seo.image', null)
            ->assertJsonPath('data.series.title', 'Jujutsu Kaisen')
            ->assertJsonPath('data.series.path', '/series/jujutsu-kaisen')
            ->assertJsonPath('data.series.total', 3)
            ->assertJsonPath('data.series.previous', ['title' => $first->title, 'slug' => 'first', 'path' => '/blog/first'])
            ->assertJsonPath('data.series.next.slug', 'last')
            ->assertJsonMissingPath('data.id');

        $this->assertEqualsCanonicalizing(['first', 'last'], $response->json('data.related.*.slug'));

        $cache = (string) $response->headers->get('Cache-Control');
        foreach (['public', 'max-age=60', 's-maxage=300', 'stale-while-revalidate=600'] as $part) {
            $this->assertStringContainsString($part, $cache);
        }
    }

    public function test_seo_fields_fall_back_to_the_post_itself(): void
    {
        Storage::fake('uploads');

        $this->makePost(['slug' => 'plain', 'title' => 'Plain title', 'excerpt' => 'An excerpt.']);
        $featured = $this->storeImage();
        $social = $this->storeImage(1200, 630);
        $this->makePost(['slug' => 'with-featured', 'featured_image' => $featured]);
        $this->makePost(['slug' => 'with-social', 'featured_image' => $featured, 'og_image' => $social]);
        $this->makePost(['slug' => 'override', 'canonical_url' => 'https://example.com/original', 'noindex' => true]);

        $this->getJson('/api/v1/posts/plain')
            ->assertJsonPath('data.seo.title', 'Plain title')
            ->assertJsonPath('data.seo.description', 'An excerpt.')
            ->assertJsonPath('data.seo.canonical_url', 'http://localhost:3000/blog/plain')
            ->assertJsonPath('data.series', null);

        $withFeatured = $this->getJson('/api/v1/posts/with-featured')->json('data.seo.image');
        $this->assertStringEndsWith(dirname($featured).'/1000.webp', $withFeatured);

        $withSocial = $this->getJson('/api/v1/posts/with-social')->json('data.seo.image');
        $this->assertStringEndsWith(dirname($social).'/1200.webp', $withSocial);

        $this->getJson('/api/v1/posts/override')
            ->assertJsonPath('data.seo.canonical_url', 'https://example.com/original')
            ->assertJsonPath('data.seo.noindex', true);
    }

    public function test_series_navigation_at_both_ends(): void
    {
        $series = Series::create(['title' => 'S', 'slug' => 's']);

        $this->makePost(['slug' => 'one', 'series_id' => $series->id, 'published_at' => now()->subDays(3)]);
        $this->makePost(['slug' => 'two', 'series_id' => $series->id, 'published_at' => now()->subDays(2)]);
        $this->makePost(['slug' => 'three', 'series_id' => $series->id, 'published_at' => now()->subDay()]);
        $this->makePost(['slug' => 'draft-four', 'series_id' => $series->id, 'status' => 'draft', 'published_at' => null]);

        $this->getJson('/api/v1/posts/one')
            ->assertJsonPath('data.series.previous', null)
            ->assertJsonPath('data.series.next.slug', 'two')
            ->assertJsonPath('data.series.total', 3);

        $this->getJson('/api/v1/posts/three')
            ->assertJsonPath('data.series.previous.slug', 'two')
            ->assertJsonPath('data.series.next', null);
    }

    public function test_related_posts_are_ranked_and_limited(): void
    {
        $series = Series::create(['title' => 'S', 'slug' => 's']);
        $movies = Category::create(['name' => 'Movies', 'slug' => 'movies']);

        $this->makePost(['slug' => 'target', 'series_id' => $series->id]);
        $this->makePost(['slug' => 'mate', 'series_id' => $series->id]);

        foreach (range(1, 8) as $number) {
            $this->makePost(['slug' => "same-category-{$number}", 'published_at' => now()->subHours($number)]);
        }

        $this->makePost(['slug' => 'elsewhere', 'category_id' => $movies->id]);

        $related = $this->getJson('/api/v1/posts/target')->assertOk()->json('data.related.*.slug');

        $this->assertCount(6, $related);
        $this->assertSame('mate', $related[0]); // shares the series and the category
        $this->assertNotContains('target', $related);
        $this->assertNotContains('elsewhere', $related);
    }

    public function test_unavailable_posts_are_a_generic_404(): void
    {
        $this->makePost(['slug' => 'draft-one', 'status' => 'draft', 'published_at' => null]);
        $this->makePost(['slug' => 'scheduled-one', 'status' => 'scheduled', 'published_at' => now()->addDay()]);
        $this->makePost(['slug' => 'future-one', 'published_at' => now()->addHour()]);
        $this->makePost(['slug' => 'trashed-one'])->delete();
        $this->makePost(['slug' => 'live']);
        Redirect::create(['from_path' => '/blog/old-slug', 'to_path' => '/blog/live', 'status_code' => 301]);

        foreach (['draft-one', 'scheduled-one', 'future-one', 'trashed-one', 'missing', 'old-slug', 'Bad_Slug', 'UPPER'] as $slug) {
            $this->getJson('/api/v1/posts/'.$slug)->assertStatus(404)->assertExactJson(['message' => 'Not found.']);
        }

        $this->getJson('/api/v1/posts/live')->assertOk();
    }

    public function test_changes_and_unpublishing_show_up_despite_caching(): void
    {
        $post = $this->makePost(['slug' => 'live-one', 'title' => 'Before']);

        $this->getJson('/api/v1/posts/live-one')->assertJsonPath('data.title', 'Before');

        $post->update(['title' => 'After']);
        $this->getJson('/api/v1/posts/live-one')->assertJsonPath('data.title', 'After');

        $post->update(['status' => 'draft']);
        $this->getJson('/api/v1/posts/live-one')->assertStatus(404);
    }
}