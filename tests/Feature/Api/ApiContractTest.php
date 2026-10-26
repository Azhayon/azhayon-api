<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Post;
use App\Models\Redirect;
use App\Models\Series;
use App\Models\Tag;
use App\Models\User;
use App\Support\Images\ImageProcessor;
use App\Support\Settings\AdSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\AssertsApiShape;
use Tests\TestCase;

class ApiContractTest extends TestCase
{
    use AssertsApiShape;
    use RefreshDatabase;

    private User $author;

    private string $cover;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        $this->seedFixture();
    }

    private function storeImage(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);

        return app(ImageProcessor::class)->store((string) ob_get_clean());
    }

    private function makePost(array $extra = []): Post
    {
        return Post::factory()->create(array_merge([
            'author_id' => $this->author->id,
            'subject_title' => 'Subject '.Str::random(8),
            'post_type' => 'news',
            'review_score' => null,
            'view_count' => 0,
            'rating_count' => 0,
            'rating_avg' => 0,
            'noindex' => false,
            'excerpt' => 'An excerpt.',
        ], $extra));
    }

    /** @return array<string, mixed> */
    private function contentWithEveryBlock(): array
    {
        $block = fn (string $id, array $config) => ['type' => 'customBlock', 'attrs' => ['id' => $id, 'config' => $config]];

        return ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Intro paragraph.']]],
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'A heading']]],
            $block('image', ['image' => $this->cover, 'alt' => 'Block alt', 'caption' => 'A caption']),
            $block('youtube', ['video' => 'dQw4w9WgXcQ', 'title' => 'A video']),
            $block('x-post', ['url' => 'https://x.com/jack/status/20', 'text' => 'A quote']),
            $block('spoiler', ['label' => 'Spoiler', 'text' => 'Hidden text', 'image' => $this->cover, 'image_alt' => 'Hidden alt']),
            $block('ad-break', []),
        ]];
    }

    private function seedFixture(): void
    {
        $this->author = User::factory()->create(['role' => 'admin', 'provider_name' => null, 'provider_id' => null]);
        $this->cover = $this->storeImage(1000, 500);

        $anime = Category::create(['name' => 'Anime', 'slug' => 'anime', 'description' => 'All about anime.']);
        $manga = Category::create(['name' => 'Manga', 'slug' => 'manga']);

        $series = Series::create([
            'title' => 'Jujutsu Kaisen',
            'slug' => 'jujutsu-kaisen',
            'description' => 'The hub page.',
            'cover_image' => $this->cover,
        ]);

        $studio = Tag::create(['name' => 'MAPPA', 'slug' => 'mappa', 'type' => 'studio']);
        $genre = Tag::create(['name' => 'Action', 'slug' => 'action', 'type' => 'genre']);
        $fans = Tag::create(['name' => 'Jujutsu Fans', 'slug' => 'jujutsu-fans', 'type' => 'general']);

        $first = $this->makePost([
            'title' => 'Jujutsu Kaisen Season Guide',
            'slug' => 'season-guide',
            'series_id' => $series->id,
            'category_id' => $anime->id,
            'subject_title' => 'Jujutsu Kaisen',
            'published_at' => now()->subDays(5),
        ]);

        $middle = $this->makePost([
            'title' => 'Review One',
            'slug' => 'review-one',
            'post_type' => 'review',
            'review_score' => 8.5,
            'series_id' => $series->id,
            'category_id' => $anime->id,
            'subject_title' => 'Jujutsu Kaisen',
            'featured_image' => $this->cover,
            'featured_image_alt' => 'The cover',
            'meta_title' => 'A custom SEO title',
            'meta_description' => 'A custom SEO description.',
            'published_at' => now()->subDays(3),
            'view_count' => 50,
            'rating_count' => 5,
            'rating_avg' => 4.2,
            'content' => $this->contentWithEveryBlock(),
        ]);

        $last = $this->makePost([
            'title' => 'Latest Update',
            'slug' => 'latest-update',
            'series_id' => $series->id,
            'category_id' => $manga->id,
            'published_at' => now()->subDay(),
        ]);

        foreach ([$first, $middle, $last] as $post) {
            $post->tags()->attach($studio->id);
        }

        $middle->tags()->attach($genre->id);
        $first->tags()->attach($fans->id);

        Redirect::create(['from_path' => '/blog/old-review', 'to_path' => '/blog/review-one', 'status_code' => 301]);
    }

    public function test_every_public_response_matches_its_contract(): void
    {
        $endpoints = [
            '/api/v1/site' => 'SiteResponse',
            '/api/v1/home' => 'HomeResponse',
            '/api/v1/posts' => 'PostsResponse',
            '/api/v1/posts/review-one' => 'PostResponse',
            '/api/v1/categories' => 'CategoriesResponse',
            '/api/v1/categories/anime' => 'CategoryResponse',
            '/api/v1/tags' => 'TagsResponse',
            '/api/v1/tags/mappa' => 'TagResponse',
            '/api/v1/series' => 'SeriesListResponse',
            '/api/v1/series/jujutsu-kaisen' => 'SeriesResponse',
            '/api/v1/search?q=jujutsu' => 'SearchResponse',
            '/api/v1/search/suggest?q=jujutsu' => 'SuggestResponse',
            '/api/v1/sitemap' => 'SitemapSummaryResponse',
            '/api/v1/sitemap/posts' => 'SitemapPostsResponse',
            '/api/v1/sitemap/taxonomy' => 'SitemapTaxonomyResponse',
            '/api/v1/redirects' => 'RedirectsResponse',
            '/api/v1/health' => 'HealthResponse',
        ];

        foreach ($endpoints as $url => $definition) {
            $response = $this->getJson($url)->assertOk();

            $this->assertApiShape($definition, $response->json(), "GET {$url}");
        }
    }

    public function test_the_fixture_exercises_every_list_so_the_checks_are_not_empty(): void
    {
        $count = fn (string $url, string $path = 'data') => count($this->getJson($url)->assertOk()->json($path) ?? []);

        foreach (['posts', 'categories', 'tags', 'series', 'search?q=jujutsu', 'search/suggest?q=jujutsu', 'sitemap/posts', 'redirects'] as $url) {
            $this->assertGreaterThan(0, $count("/api/v1/{$url}"), $url);
        }

        foreach (['trending', 'latest', 'popular'] as $section) {
            $this->assertGreaterThan(0, $count('/api/v1/home', "data.{$section}"), $section);
        }

        foreach (['categories', 'tags', 'series'] as $kind) {
            $this->assertGreaterThan(0, $count('/api/v1/sitemap/taxonomy', "data.{$kind}"), $kind);
        }

        $detail = $this->getJson('/api/v1/posts/review-one')->assertOk()->json('data');

        $this->assertNotEmpty($detail['tags']);
        $this->assertNotEmpty($detail['related']);
        $this->assertNotNull($detail['series']['previous']);
        $this->assertNotNull($detail['series']['next']);
        $this->assertNotNull($detail['image']);
        $this->assertNotNull($detail['review_score']);

        $suggestions = $this->getJson('/api/v1/search/suggest?q=jujutsu')->json('data.*.type');
        $this->assertEqualsCanonicalizing(['series', 'tag', 'subject', 'post'], array_values(array_unique($suggestions)));
    }

    public function test_both_ad_states_match_the_contract(): void
    {
        $this->assertApiShape('SiteResponse', $this->getJson('/api/v1/site')->json(), 'ads off');

        AdSettings::save([
            'enabled' => true,
            'client' => 'ca-pub-1234567890123456',
            'placements' => [
                'sidebar' => ['enabled' => true, 'slot' => '1234567890'],
                'in_article' => ['enabled' => false, 'slot' => null],
                'anchor' => ['enabled' => false, 'slot' => null],
            ],
        ]);

        $on = $this->getJson('/api/v1/site');

        $this->assertApiShape('SiteResponse', $on->json(), 'ads on');
        $on->assertJsonPath('data.ads.enabled', true);
    }

    public function test_content_blocks_match_their_configs_and_carry_resolved_media(): void
    {
        $nodes = collect($this->getJson('/api/v1/posts/review-one')->assertOk()->json('data.content.content'))
            ->where('type', 'customBlock');

        $byId = $nodes->keyBy(fn (array $node) => $node['attrs']['id']);

        $this->assertEqualsCanonicalizing(['image', 'youtube', 'x-post', 'spoiler', 'ad-break'], $byId->keys()->all());

        $definitions = [
            'image' => 'ImageBlockConfig',
            'youtube' => 'YouTubeBlockConfig',
            'x-post' => 'XPostBlockConfig',
            'spoiler' => 'SpoilerBlockConfig',
        ];

        foreach ($definitions as $id => $definition) {
            $this->assertApiShape($definition, $byId[$id]['attrs']['config'], "{$id} block");
        }

        $this->assertSame([], $byId['ad-break']['attrs']['config']);

        // Images arrive ready to render: absolute URLs, every variant, and their sizes.
        $media = $byId['image']['attrs']['config']['media'];

        $this->assertSame([1000, 768, 480], array_column($media['variants'], 'width'));
        $this->assertSame([500, 384, 240], array_column($media['variants'], 'height'));
        $this->assertStringEndsWith(dirname($this->cover).'/1000.webp', $media['url']);
        $this->assertSame('Block alt', $media['alt']);
        $this->assertNotNull($byId['spoiler']['attrs']['config']['media']);

        // A spoiler without an image has no media.
        $this->makePost([
            'slug' => 'plain-spoiler',
            'category_id' => Category::query()->value('id'),
            'content' => ['type' => 'doc', 'content' => [
                ['type' => 'customBlock', 'attrs' => ['id' => 'spoiler', 'config' => ['text' => 'Only text']]],
            ]],
        ]);

        $plain = $this->getJson('/api/v1/posts/plain-spoiler')->assertOk()->json('data.content.content.0.attrs.config');

        $this->assertApiShape('SpoilerBlockConfig', $plain, 'plain spoiler');
        $this->assertNull($plain['media']);
    }

    public function test_error_responses_match_the_contract(): void
    {
        $this->assertApiShape('ErrorResponse', $this->getJson('/api/v1/posts/does-not-exist')->assertStatus(404)->json(), '404');
        $this->assertApiShape('ValidationErrorResponse', $this->getJson('/api/v1/posts?foo=1')->assertStatus(422)->json(), '422');
        $this->assertApiShape('ValidationErrorResponse', $this->getJson('/api/v1/search')->assertStatus(422)->json(), '422 search');
    }
}