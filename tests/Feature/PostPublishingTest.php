<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use App\Support\Images\ImageProcessor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PostPublishingTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create(['name' => 'Anime', 'slug' => 'anime']);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'provider_name' => null, 'provider_id' => null]);
    }

    private function attributes(User $author, array $extra = []): array
    {
        return array_merge(['author_id' => $author->id, 'category_id' => $this->category->id], $extra);
    }

    private function livePost(string $slug): Post
    {
        return Post::factory()->create($this->attributes($this->user('admin'), ['slug' => $slug]));
    }

    public function test_editors_cannot_publish_schedule_or_unpublish(): void
    {
        $editor = $this->user('editor');
        $this->actingAs($editor);

        $draft = Post::create($this->attributes($editor, ['title' => 'T', 'slug' => 't']));

        foreach ([['status' => 'published'], ['status' => 'scheduled', 'published_at' => now()->addDay()]] as $changes) {
            try {
                $draft->update($changes);
                $this->fail('An editor was able to change publishing state.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }

            $draft->refresh();
        }

        // Ordinary edits are still fine.
        $draft->update(['title' => 'Renamed']);
        $this->assertSame('Renamed', $draft->fresh()->title);
    }

    public function test_editors_cannot_create_a_post_that_is_already_published(): void
    {
        $editor = $this->user('editor');
        $this->actingAs($editor);

        $this->expectException(AuthorizationException::class);

        Post::create($this->attributes($editor, ['title' => 'T', 'slug' => 't', 'status' => 'published']));
    }

    public function test_admins_can_publish_and_get_a_publish_time(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin);

        $post = Post::create($this->attributes($admin, ['title' => 'T', 'slug' => 't', 'status' => 'published']));

        $this->assertSame(PostStatus::Published, $post->fresh()->status);
        $this->assertNotNull($post->fresh()->published_at);
    }

    public function test_changing_a_live_slug_creates_redirects_without_chains_or_loops(): void
    {
        $post = $this->livePost('old');

        $post->update(['slug' => 'new']);
        $this->assertDatabaseHas('redirects', ['from_path' => '/blog/old', 'to_path' => '/blog/new', 'status_code' => 301]);

        $post->update(['slug' => 'newer']);
        $this->assertDatabaseHas('redirects', ['from_path' => '/blog/old', 'to_path' => '/blog/newer']);
        $this->assertDatabaseHas('redirects', ['from_path' => '/blog/new', 'to_path' => '/blog/newer']);
        $this->assertDatabaseCount('redirects', 2);

        // Going back to an earlier slug must not leave a redirect away from a live URL.
        $post->update(['slug' => 'old']);
        $this->assertDatabaseMissing('redirects', ['from_path' => '/blog/old']);
        $this->assertDatabaseHas('redirects', ['from_path' => '/blog/new', 'to_path' => '/blog/old']);
        $this->assertDatabaseHas('redirects', ['from_path' => '/blog/newer', 'to_path' => '/blog/old']);
    }

    public function test_changing_a_draft_slug_creates_no_redirect(): void
    {
        $draft = Post::factory()->draft()->create($this->attributes($this->user('admin'), ['slug' => 'd1']));

        $draft->update(['slug' => 'd2']);

        $this->assertDatabaseCount('redirects', 0);
    }

    public function test_the_command_publishes_only_scheduled_posts_that_are_due(): void
    {
        $admin = $this->user('admin');
        $due = Post::factory()->scheduled()->create($this->attributes($admin, ['published_at' => now()->subMinute()]));
        $later = Post::factory()->scheduled()->create($this->attributes($admin, ['published_at' => now()->addHour()]));
        $draft = Post::factory()->draft()->create($this->attributes($admin));

        $this->artisan('posts:publish-due')->assertSuccessful();

        $this->assertSame(PostStatus::Published, $due->fresh()->status);
        $this->assertSame(PostStatus::Scheduled, $later->fresh()->status);
        $this->assertSame(PostStatus::Draft, $draft->fresh()->status);
    }

    public function test_the_featured_image_dimensions_are_recorded(): void
    {
        Storage::fake('uploads');

        $image = imagecreatetruecolor(1000, 500);
        ob_start();
        imagejpeg($image);
        $path = app(ImageProcessor::class)->store((string) ob_get_clean());

        $post = Post::factory()->draft()->create($this->attributes($this->user('admin'), ['featured_image' => $path]));
        $post->refresh();

        $this->assertSame(1000, $post->featured_image_width);
        $this->assertSame(500, $post->featured_image_height);
        $this->assertSame(dirname($path).'/480.webp', $post->featured_thumb);
    }
}