<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Post;
use App\Models\Redirect;
use App\Models\Series;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use App\Support\Cache\ContentVersion;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContentVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cached_values_live_until_the_version_changes(): void
    {
        $calls = 0;
        $compute = function () use (&$calls): int {
            return ++$calls;
        };

        $this->assertSame(1, ContentVersion::remember('example', 60, $compute));
        $this->assertSame(1, ContentVersion::remember('example', 60, $compute));

        ContentVersion::bump();

        $this->assertSame(2, ContentVersion::remember('example', 60, $compute));
    }

    public function test_changing_public_content_bumps_the_version(): void
    {
        $author = User::factory()->create(['role' => 'admin', 'provider_name' => null, 'provider_id' => null]);

        $assertBumps = function (string $what, Closure $change): void {
            $before = ContentVersion::current();
            $change();

            $this->assertGreaterThan($before, ContentVersion::current(), $what);
        };

        $category = null;
        $post = null;

        $assertBumps('category created', function () use (&$category): void {
            $category = Category::create(['name' => 'Anime', 'slug' => 'anime']);
        });
        $assertBumps('category renamed', fn () => $category->update(['name' => 'Anime & More']));
        $assertBumps('tag created', fn () => Tag::create(['name' => 'MAPPA', 'slug' => 'mappa', 'type' => 'studio']));
        $assertBumps('series created', fn () => Series::create(['title' => 'S', 'slug' => 's']));
        $assertBumps('redirect created', fn () => Redirect::create(['from_path' => '/a', 'to_path' => '/b', 'status_code' => 301]));
        $assertBumps('setting saved', fn () => Setting::put('site', ['name' => 'X']));
        $assertBumps('post created', function () use (&$post, $author, $category): void {
            $post = Post::factory()->create(['author_id' => $author->id, 'category_id' => $category->id]);
        });
        $assertBumps('post deleted', fn () => $post->delete());
        $assertBumps('post restored', fn () => $post->restore());
    }

    public function test_the_prune_command_removes_only_expired_rows(): void
    {
        DB::table('cache')->insert([
            ['key' => 'old-one', 'value' => 's:1:"x";', 'expiration' => now()->subMinutes(5)->getTimestamp()],
            ['key' => 'old-two', 'value' => 's:1:"x";', 'expiration' => now()->subDay()->getTimestamp()],
            ['key' => 'still-valid', 'value' => 's:1:"x";', 'expiration' => now()->addHour()->getTimestamp()],
        ]);

        $this->artisan('cache:prune-expired')->expectsOutputToContain('Removed 2')->assertSuccessful();

        $this->assertSame(['still-valid'], DB::table('cache')->pluck('key')->all());
    }
}