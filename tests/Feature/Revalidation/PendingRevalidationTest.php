<?php

namespace Tests\Feature\Revalidation;

use App\Models\Category;
use App\Models\Post;
use App\Models\Redirect;
use App\Models\Series;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use App\Support\Revalidation\PendingRevalidation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PendingRevalidationTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        config(['azhayon.revalidate.secret' => str_repeat('s', 40)]);

        $this->author = User::factory()->create(['role' => 'admin', 'provider_name' => null, 'provider_id' => null]);
        $this->category = Category::create(['name' => 'Anime', 'slug' => 'anime']);
        $this->clearPending();
    }

    private function clearPending(): void
    {
        DB::table('pending_revalidations')->delete();
    }

    /** @return list<string> */
    private function pendingTags(): array
    {
        $tags = DB::table('pending_revalidations')->pluck('tag')->all();
        sort($tags);

        return $tags;
    }

    private function makeLivePost(array $extra = []): Post
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

    public function test_nothing_is_recorded_until_revalidation_is_configured(): void
    {
        foreach ([null, '', 'too-short'] as $secret) {
            config(['azhayon.revalidate.secret' => $secret]);

            Category::create(['name' => 'Cat '.Str::random(5), 'slug' => 'cat-'.Str::lower(Str::random(5))]);
            $this->makeLivePost();

            $this->assertSame([], $this->pendingTags(), (string) $secret);
        }
    }

    public function test_a_live_post_notifies_about_itself_and_the_pages_that_list_it(): void
    {
        $this->makeLivePost(['slug' => 'live-one']);

        $this->assertSame(['home', 'post:live-one', 'posts', 'sitemap', 'taxonomy'], $this->pendingTags());
    }

    public function test_drafts_and_future_posts_stay_quiet(): void
    {
        $draft = $this->makeLivePost(['slug' => 'quiet', 'status' => 'draft', 'published_at' => null]);
        $draft->update(['title' => 'Edited']);
        $draft->delete();

        $this->makeLivePost(['slug' => 'later', 'status' => 'scheduled', 'published_at' => now()->addDay()]);
        $this->makeLivePost(['slug' => 'future', 'published_at' => now()->addHour()]);

        $this->assertSame([], $this->pendingTags());
    }

    public function test_publishing_and_unpublishing_both_notify(): void
    {
        $post = $this->makeLivePost(['slug' => 'toggle', 'status' => 'draft', 'published_at' => null]);
        $this->assertSame([], $this->pendingTags());

        $post->update(['status' => 'published']);
        $this->assertContains('post:toggle', $this->pendingTags());

        $this->clearPending();

        $post->update(['status' => 'draft']);
        $this->assertContains('post:toggle', $this->pendingTags());
    }

    public function test_a_slug_change_on_a_live_post_notifies_both_slugs_and_the_redirects(): void
    {
        $post = $this->makeLivePost(['slug' => 'before']);
        $this->clearPending();

        $post->update(['slug' => 'after']);

        $tags = $this->pendingTags();

        foreach (['post:before', 'post:after', 'redirects', 'posts', 'home', 'sitemap', 'taxonomy'] as $expected) {
            $this->assertContains($expected, $tags, $expected);
        }
    }

    public function test_deleting_and_restoring_a_live_post_notify(): void
    {
        $post = $this->makeLivePost(['slug' => 'gone-and-back']);
        $this->clearPending();

        $post->delete();
        $this->assertContains('post:gone-and-back', $this->pendingTags());

        $this->clearPending();

        $post->restore();
        $this->assertContains('post:gone-and-back', $this->pendingTags());
    }

    public function test_taxonomy_redirect_and_setting_changes_notify(): void
    {
        $taxonomy = ['home', 'posts', 'site', 'sitemap', 'taxonomy'];

        Category::create(['name' => 'Manga', 'slug' => 'manga']);
        $this->assertSame($taxonomy, $this->pendingTags());

        $this->clearPending();
        Tag::create(['name' => 'MAPPA', 'slug' => 'mappa', 'type' => 'studio']);
        $this->assertSame($taxonomy, $this->pendingTags());

        $this->clearPending();
        Series::create(['title' => 'S', 'slug' => 's']);
        $this->assertSame($taxonomy, $this->pendingTags());

        $this->clearPending();
        Redirect::create(['from_path' => '/a', 'to_path' => '/b', 'status_code' => 301]);
        $this->assertSame(['redirects'], $this->pendingTags());

        $this->clearPending();
        Setting::put('adsense', ['enabled' => false]);
        $this->assertSame(['site'], $this->pendingTags());
    }

    public function test_view_counts_never_notify(): void
    {
        $post = $this->makeLivePost();
        $this->clearPending();

        Post::query()->whereKey($post->id)->increment('view_count', 5);
        DB::table('post_views_daily')->insert(['post_id' => $post->id, 'date' => now()->toDateString(), 'views' => 5]);

        $this->assertSame([], $this->pendingTags());
    }

    public function test_tags_are_validated_and_deduplicated(): void
    {
        PendingRevalidation::add(['posts', 'posts', 'Bad Tag', '../x', '', 'UPPER', 'post:ok-1', 'a b']);

        $this->assertSame(['post:ok-1', 'posts'], $this->pendingTags());
    }

    public function test_scheduled_posts_notify_when_they_go_live(): void
    {
        $this->makeLivePost(['slug' => 'timed', 'status' => 'scheduled', 'published_at' => now()->subMinute()]);
        $this->assertSame([], $this->pendingTags());

        $this->artisan('posts:publish-due')->assertSuccessful();

        $this->assertContains('post:timed', $this->pendingTags());
    }
}