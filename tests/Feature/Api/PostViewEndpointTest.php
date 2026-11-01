<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use App\Support\Http\BotDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PostViewEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0 Safari/537.36';

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
            'slug' => 'a-post',
            'subject_title' => 'Subject '.Str::random(8),
            'post_type' => 'news',
            'review_score' => null,
            'view_count' => 10,
            'noindex' => false,
        ], $extra));
    }

    private function countView(string $slug, string $agent = self::BROWSER): TestResponse
    {
        return $this->postJson("/api/v1/posts/{$slug}/view", [], ['User-Agent' => $agent]);
    }

    private function dailyViews(Post $post): int
    {
        return (int) DB::table('post_views_daily')->where('post_id', $post->id)->sum('views');
    }

    public function test_a_view_is_counted_once_per_visitor(): void
    {
        $post = $this->makePost();

        $response = $this->countView($post->slug)->assertNoContent();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(1, $this->dailyViews($post));
        $this->assertSame(11, $post->fresh()->view_count);

        $this->countView($post->slug)->assertNoContent();

        $this->assertSame(1, $this->dailyViews($post));
        $this->assertSame(11, $post->fresh()->view_count);
    }

    public function test_different_visitors_are_counted_separately(): void
    {
        $post = $this->makePost();

        $this->countView($post->slug, self::BROWSER)->assertNoContent();
        $this->countView($post->slug, self::BROWSER.' Edg/130')->assertNoContent();

        $this->assertSame(2, $this->dailyViews($post));
        $this->assertSame(12, $post->fresh()->view_count);
    }

    public function test_bots_are_not_counted(): void
    {
        $post = $this->makePost();

        foreach ([
            'Googlebot/2.1 (+http://www.google.com/bot.html)',
            'Mozilla/5.0 (compatible; bingbot/2.0)',
            'curl/8.4.0',
            'Mozilla/5.0 Chrome-Lighthouse',
        ] as $agent) {
            $this->countView($post->slug, $agent)->assertNoContent();
        }

        $this->assertSame(0, DB::table('post_views_daily')->count());
        $this->assertSame(10, $post->fresh()->view_count);

        $this->assertTrue(BotDetector::isBot(null));
        $this->assertTrue(BotDetector::isBot(''));
        $this->assertFalse(BotDetector::isBot(self::BROWSER));
    }

    public function test_hidden_and_unknown_posts_are_not_counted_and_look_the_same(): void
    {
        $this->makePost(['slug' => 'a-draft', 'status' => 'draft', 'published_at' => null, 'view_count' => 0]);
        $this->makePost(['slug' => 'later', 'status' => 'scheduled', 'published_at' => now()->addDay(), 'view_count' => 0]);
        $gone = $this->makePost(['slug' => 'gone']);
        $gone->delete();

        foreach (['a-draft', 'later', 'gone', 'no-such-post'] as $slug) {
            $this->countView($slug)->assertNoContent();
        }

        $this->assertSame(0, DB::table('post_views_daily')->count());
        $this->assertSame(10, Post::withTrashed()->find($gone->id)->view_count);
        $this->assertSame(0, (int) Post::where('slug', 'a-draft')->value('view_count'));
    }

    public function test_counting_does_not_change_the_updated_date(): void
    {
        $post = $this->makePost();
        $before = $post->fresh()->updated_at;

        $this->travel(10)->minutes();
        $this->countView($post->slug)->assertNoContent();

        $this->assertTrue($post->fresh()->updated_at->equalTo($before));
    }

    public function test_a_visitor_is_rate_limited(): void
    {
        $post = $this->makePost();

        for ($i = 1; $i <= 30; $i++) {
            $this->countView($post->slug, self::BROWSER." v{$i}")->assertNoContent();
        }

        $this->countView($post->slug, self::BROWSER.' v31')->assertStatus(429);
        $this->assertSame(30, $this->dailyViews($post));
    }

    public function test_only_lowercase_slugs_have_a_route(): void
    {
        $this->postJson('/api/v1/posts/A-Post/view')->assertNotFound();
    }
}