<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Post;
use App\Models\Series;
use App\Models\Tag;
use App\Models\User;
use App\Support\Cache\ContentVersion;
use App\Support\Images\ImageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SitemapEndpointTest extends TestCase
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
            'noindex' => false,
            'published_at' => now()->subDays(30),
            'updated_at' => now()->subDays(20),
        ], $extra));
    }

    private function makeSeries(string $slug): Series
    {
        return Series::create(['title' => Str::title(str_replace('-', ' ', $slug)), 'slug' => $slug]);
    }

    /** Sets timestamps directly in the database, so no model events or version bumps are involved. */
    private function setTimestamps(string $table, int $id, array $timestamps): void
    {
        DB::table($table)->where('id', $id)->update($timestamps);
    }

    private function iso(\Carbon\CarbonInterface $date): string
    {
        return $date->copy()->startOfSecond()->utc()->toIso8601ZuluString();
    }

    private function hiddenPosts(): void
    {
        $this->makePost(['slug' => 'hidden-noindex', 'noindex' => true]);
        $this->makePost(['slug' => 'hidden-draft', 'status' => 'draft', 'published_at' => null]);
        $this->makePost(['slug' => 'hidden-scheduled', 'status' => 'scheduled', 'published_at' => now()->addDay()]);
        $this->makePost(['slug' => 'hidden-future', 'published_at' => now()->addHour()]);
        $this->makePost(['slug' => 'hidden-trashed'])->delete();
    }

    public function test_the_summary_counts_only_indexable_live_posts(): void
    {
        config(['azhayon.api.sitemap_per_page' => 2]);

        $newest = now()->subHours(3)->startOfSecond();

        $this->makePost(['slug' => 'a']);
        $this->makePost(['slug' => 'b']);
        $this->makePost(['slug' => 'c', 'updated_at' => $newest]);
        $this->hiddenPosts(); // all of these are newer than the live posts

        $this->getJson('/api/v1/sitemap')
            ->assertOk()
            ->assertJsonPath('data.posts', ['total' => 3, 'per_page' => 2, 'pages' => 2])
            ->assertJsonPath('data.last_modified', $this->iso($newest));
    }

    public function test_post_entries_are_paged_in_a_stable_order(): void
    {
        config(['azhayon.api.sitemap_per_page' => 2]);

        foreach (['s1', 's2', 's3', 's4', 's5'] as $slug) {
            $this->makePost(['slug' => $slug]);
        }

        $this->hiddenPosts();

        $first = $this->getJson('/api/v1/sitemap/posts')->assertOk();

        $this->assertSame(['/blog/s1', '/blog/s2'], $first->json('data.*.path'));
        $first->assertJsonPath('meta', ['page' => 1, 'pages' => 3]);

        $this->assertSame(['/blog/s3', '/blog/s4'], $this->getJson('/api/v1/sitemap/posts?page=2')->json('data.*.path'));
        $this->assertSame(['/blog/s5'], $this->getJson('/api/v1/sitemap/posts?page=3')->json('data.*.path'));

        $this->getJson('/api/v1/sitemap/posts?page=4')->assertStatus(404)->assertExactJson(['message' => 'Not found.']);
    }

    public function test_lastmod_is_the_later_of_the_edit_and_the_publish_date(): void
    {
        $edited = now()->subDays(2)->startOfSecond();
        $published = now()->subDays(1)->startOfSecond();

        $this->makePost(['slug' => 'edited-later', 'published_at' => now()->subDays(10), 'updated_at' => $edited]);
        $this->makePost(['slug' => 'published-later', 'published_at' => $published, 'updated_at' => now()->subDays(5)]);

        $entries = collect($this->getJson('/api/v1/sitemap/posts')->assertOk()->json('data'))->keyBy('path');

        $this->assertSame($this->iso($edited), $entries['/blog/edited-later']['lastmod']);
        $this->assertSame($this->iso($published), $entries['/blog/published-later']['lastmod']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $entries['/blog/edited-later']['lastmod']);
        $this->assertNull($entries['/blog/edited-later']['image']);
    }

    public function test_entries_carry_the_featured_image(): void
    {
        Storage::fake('uploads');

        $image = imagecreatetruecolor(1000, 500);
        ob_start();
        imagejpeg($image);
        $path = app(ImageProcessor::class)->store((string) ob_get_clean());

        $this->makePost(['slug' => 'with-image', 'featured_image' => $path]);

        $imageUrl = $this->getJson('/api/v1/sitemap/posts')->json('data.0.image');

        $this->assertStringEndsWith(dirname($path).'/1000.webp', $imageUrl);
    }

    public function test_taxonomy_lists_only_pages_worth_indexing(): void
    {
        $manga = Category::create(['name' => 'Manga', 'slug' => 'manga']);
        Category::create(['name' => 'Empty', 'slug' => 'empty']);
        $draftsOnly = Category::create(['name' => 'Drafts', 'slug' => 'drafts']);

        $busy = Tag::create(['name' => 'Busy', 'slug' => 'busy', 'type' => 'genre']);
        $thin = Tag::create(['name' => 'Thin', 'slug' => 'thin', 'type' => 'genre']);
        $draftTag = Tag::create(['name' => 'Drafted', 'slug' => 'drafted', 'type' => 'genre']);

        $series = $this->makeSeries('live-series');
        $this->makeSeries('empty-series');

        foreach (range(1, 3) as $number) {
            $this->makePost(['series_id' => $number === 1 ? $series->id : null])->tags()->attach($busy->id);
        }

        foreach (range(1, 2) as $number) {
            $this->makePost(['category_id' => $manga->id])->tags()->attach($thin->id);
        }

        foreach (range(1, 3) as $number) {
            $this->makePost(['category_id' => $draftsOnly->id, 'status' => 'draft', 'published_at' => null])
                ->tags()->attach($draftTag->id);
        }

        $data = $this->getJson('/api/v1/sitemap/taxonomy')->assertOk()->json('data');

        $this->assertSame(['/category/anime', '/category/manga'], array_column($data['categories'], 'path'));
        $this->assertSame(['/tag/busy'], array_column($data['tags'], 'path'));
        $this->assertSame(['/series/live-series'], array_column($data['series'], 'path'));
        $this->assertNull($data['series'][0]['image']);
    }

    public function test_taxonomy_lastmod_is_the_later_of_the_page_and_its_newest_post(): void
    {
        $newer = now()->subDays(1)->startOfSecond();
        $older = now()->subDays(6)->startOfSecond();

        $postEdited = $this->makeSeries('post-edited');
        $seriesEdited = $this->makeSeries('series-edited');

        $first = $this->makePost(['series_id' => $postEdited->id]);
        $second = $this->makePost(['series_id' => $seriesEdited->id]);

        $this->setTimestamps('series', $postEdited->id, ['updated_at' => now()->subDays(10)]);
        $this->setTimestamps('posts', $first->id, ['updated_at' => $newer]);
        $this->setTimestamps('series', $seriesEdited->id, ['updated_at' => $newer]);
        $this->setTimestamps('posts', $second->id, ['updated_at' => $older]);

        $series = collect($this->getJson('/api/v1/sitemap/taxonomy')->assertOk()->json('data.series'))->keyBy('path');

        $this->assertSame($this->iso($newer), $series['/series/post-edited']['lastmod']);
        $this->assertSame($this->iso($newer), $series['/series/series-edited']['lastmod']);
    }

    public function test_empty_sites_and_bad_input(): void
    {
        $this->getJson('/api/v1/sitemap')->assertOk()
            ->assertJsonPath('data.posts', ['total' => 0, 'per_page' => 2000, 'pages' => 1])
            ->assertJsonPath('data.last_modified', null);
        $this->getJson('/api/v1/sitemap/posts')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v1/sitemap/posts?page=2')->assertStatus(404);
        $this->getJson('/api/v1/sitemap/taxonomy')->assertOk()
            ->assertExactJson(['data' => ['categories' => [], 'tags' => [], 'series' => []]]);

        foreach (['page=0', 'page=1001', 'page=abc', 'foo=1'] as $query) {
            $this->getJson('/api/v1/sitemap/posts?'.$query)->assertStatus(422);
        }

        $this->getJson('/api/v1/sitemap?page=1')->assertStatus(422);
        $this->getJson('/api/v1/sitemap/taxonomy?foo=1')->assertStatus(422);
    }

    public function test_responses_are_cacheable_and_follow_content_changes(): void
    {
        $response = $this->getJson('/api/v1/sitemap')->assertOk()->assertJsonPath('data.posts.total', 0);
        $cache = (string) $response->headers->get('Cache-Control');

        foreach (['public', 'max-age=60', 's-maxage=300', 'stale-while-revalidate=600'] as $part) {
            $this->assertStringContainsString($part, $cache);
        }

        $this->makePost(['slug' => 'fresh']);

        $this->getJson('/api/v1/sitemap')->assertJsonPath('data.posts.total', 1);
        $this->assertSame(['/blog/fresh'], $this->getJson('/api/v1/sitemap/posts')->json('data.*.path'));
    }

    public function test_sitemap_endpoints_use_a_constant_number_of_queries(): void
    {
        $countQueries = function (string $url): int {
            ContentVersion::bump(); // forces a fresh build instead of a cached answer
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($url)->assertOk();

            return count(DB::getQueryLog());
        };

        $this->makePost();
        $postsFew = $countQueries('/api/v1/sitemap/posts');
        $taxonomyFew = $countQueries('/api/v1/sitemap/taxonomy');

        foreach (range(1, 10) as $number) {
            $tag = Tag::create(['name' => "Tag {$number}", 'slug' => "tag-{$number}", 'type' => 'genre']);
            $series = $this->makeSeries("series-{$number}");
            $this->makePost(['series_id' => $series->id])->tags()->attach($tag->id);
        }

        $this->assertSame($postsFew, $countQueries('/api/v1/sitemap/posts'));
        $this->assertSame($taxonomyFew, $countQueries('/api/v1/sitemap/taxonomy'));
    }
}