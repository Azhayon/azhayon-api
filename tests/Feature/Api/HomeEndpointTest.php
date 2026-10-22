<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use App\Support\Cache\ContentVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class HomeEndpointTest extends TestCase
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

    private function recordViews(Post $post, int $views, int $daysAgo = 0): void
    {
        DB::table('post_views_daily')->insert([
            'post_id' => $post->id,
            'date' => now()->subDays($daysAgo)->toDateString(),
            'views' => $views,
        ]);
    }

    /** @return list<string> */
    private function section(string $name): array
    {
        return $this->getJson('/api/v1/home')->assertOk()->json("data.{$name}.*.slug") ?? [];
    }

    private function useSizes(int $trending, int $latest, int $popular): void
    {
        config(['azhayon.api.home' => ['trending' => $trending, 'latest' => $latest, 'popular' => $popular]]);
    }

    public function test_home_has_three_sections_of_cards(): void
    {
        foreach (range(1, 10) as $number) {
            $this->makePost(['published_at' => now()->subDays($number), 'view_count' => $number]);
        }

        $response = $this->getJson('/api/v1/home')->assertOk();

        $card = ['slug', 'path', 'title', 'category' => ['name', 'slug'], 'image', 'published_at', 'rating' => ['average', 'count']];

        $response->assertJsonStructure(['data' => [
            'trending' => ['*' => $card],
            'latest' => ['*' => $card],
            'popular' => ['*' => $card],
        ]]);

        $this->assertCount(6, $response->json('data.trending'));
        $this->assertCount(8, $response->json('data.latest'));
        $this->assertCount(6, $response->json('data.popular'));
        $response->assertJsonMissingPath('data.latest.0.content');

        $cache = (string) $response->headers->get('Cache-Control');
        foreach (['public', 'max-age=60', 's-maxage=300', 'stale-while-revalidate=600'] as $part) {
            $this->assertStringContainsString($part, $cache);
        }
    }

    public function test_trending_ranks_by_views_in_the_last_two_days(): void
    {
        $this->useSizes(2, 5, 5);

        $one = $this->makePost(['slug' => 'one']);
        $two = $this->makePost(['slug' => 'two']);
        $old = $this->makePost(['slug' => 'old', 'view_count' => 5000]);
        $boundary = $this->makePost(['slug' => 'boundary']);
        $yesterday = $this->makePost(['slug' => 'yesterday']);

        $this->recordViews($one, 10);
        $this->recordViews($one, 5, 1);        // 15 in total
        $this->recordViews($two, 20);          // 20
        $this->recordViews($yesterday, 12, 1); // 12
        $this->recordViews($old, 1000, 3);     // outside the window
        $this->recordViews($boundary, 500, 2); // also outside: only today and yesterday count

        $this->assertSame(['two', 'one'], $this->section('trending'));

        $this->useSizes(3, 5, 5);
        $this->assertSame(['two', 'one', 'yesterday'], $this->section('trending'));
    }

    public function test_trending_is_topped_up_with_popular_posts_until_enough_views_exist(): void
    {
        $this->useSizes(3, 5, 5);

        $rising = $this->makePost(['slug' => 'rising']);
        $this->makePost(['slug' => 'favorite-a', 'view_count' => 500]);
        $this->makePost(['slug' => 'favorite-b', 'view_count' => 300]);
        $this->makePost(['slug' => 'favorite-c', 'view_count' => 100]);

        $this->recordViews($rising, 40);

        $this->assertSame(['rising', 'favorite-a', 'favorite-b'], $this->section('trending'));
    }

    public function test_popular_and_latest_follow_their_definitions(): void
    {
        $this->useSizes(3, 3, 3);

        $this->makePost(['slug' => 'a', 'view_count' => 50, 'published_at' => now()->subDays(5)]);
        $this->makePost(['slug' => 'b', 'view_count' => 900, 'published_at' => now()->subDays(4)]);
        $this->makePost(['slug' => 'c', 'view_count' => 50, 'published_at' => now()->subDays(1)]); // ties with a: newer wins
        $this->makePost(['slug' => 'd', 'view_count' => 10, 'published_at' => now()->subDays(2)]);
        $this->makePost(['slug' => 'e', 'view_count' => 0, 'published_at' => now()->subDays(3)]);

        $this->assertSame(['b', 'c', 'a'], $this->section('popular'));
        $this->assertSame(['c', 'd', 'e'], $this->section('latest'));
    }

    public function test_hidden_posts_never_appear_in_any_section(): void
    {
        $this->useSizes(6, 6, 6);

        $this->makePost(['slug' => 'live', 'view_count' => 1]);
        $this->makePost(['slug' => 'indexless', 'noindex' => true, 'view_count' => 1]);

        $hidden = [
            $this->makePost(['slug' => 'draft', 'status' => 'draft', 'published_at' => null, 'view_count' => 9999]),
            $this->makePost(['slug' => 'scheduled', 'status' => 'scheduled', 'published_at' => now()->addDay(), 'view_count' => 9999]),
            $this->makePost(['slug' => 'future', 'published_at' => now()->addHour(), 'view_count' => 9999]),
            $this->makePost(['slug' => 'trashed', 'view_count' => 9999]),
        ];

        foreach ($hidden as $post) {
            $this->recordViews($post, 9999);
        }

        $hidden[3]->delete();

        $response = $this->getJson('/api/v1/home')->assertOk();

        $every = array_merge(
            $response->json('data.trending.*.slug'),
            $response->json('data.latest.*.slug'),
            $response->json('data.popular.*.slug'),
        );

        foreach (['draft', 'scheduled', 'future', 'trashed'] as $slug) {
            $this->assertNotContains($slug, $every, $slug);
        }

        // Posts hidden from search engines are still shown on the site itself.
        $this->assertContains('indexless', $every);
        $this->assertContains('live', $every);
    }

    public function test_content_changes_show_up_but_view_changes_wait_for_the_cache(): void
    {
        $this->useSizes(1, 2, 2);

        $first = $this->makePost(['slug' => 'first', 'published_at' => now()->subDays(2)]);
        $second = $this->makePost(['slug' => 'second', 'published_at' => now()->subDays(3)]);

        $this->recordViews($first, 10);
        $this->assertSame(['first'], $this->section('trending'));

        // A new post is a content change: it appears straight away.
        $this->makePost(['slug' => 'third', 'published_at' => now()]);
        $this->assertSame('third', $this->section('latest')[0]);

        // Views are not content: the cached trending list holds until its 2 minutes are up.
        $this->recordViews($second, 100);
        $this->assertSame(['first'], $this->section('trending'));

        $this->travel(3)->minutes();
        $this->assertSame(['second'], $this->section('trending'));
    }

    public function test_home_uses_a_constant_number_of_queries(): void
    {
        $countQueries = function (): int {
            ContentVersion::bump(); // forces a fresh build instead of a cached answer
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/home')->assertOk();

            return count(DB::getQueryLog());
        };

        Post::factory()->count(3)->create(['author_id' => $this->author->id, 'category_id' => $this->category->id]);
        $few = $countQueries();

        Post::factory()->count(15)->create(['author_id' => $this->author->id, 'category_id' => $this->category->id]);
        $many = $countQueries();

        $this->assertSame($few, $many);
    }
}