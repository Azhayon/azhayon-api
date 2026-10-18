<?php

namespace Tests\Feature\Admin;

use App\Enums\CommentStatus;
use App\Filament\Resources\Comments\CommentResource;
use App\Filament\Resources\Comments\Pages\ManageComments;
use App\Filament\Resources\Redirects\Pages\ManageRedirects;
use App\Filament\Resources\Subscribers\Pages\ManageSubscribers;
use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Redirect;
use App\Models\Subscriber;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function admin(): User
    {
        /** @var User $user */
        $user = User::factory()->create([
            'role' => 'admin',
            'provider_name' => null,
            'provider_id' => null,
            'banned_at' => null,
            'deleted_at' => null,
        ]);

        return $user;
    }

    private function createPost(array $extra = []): Post
    {
        $category = Category::firstOrCreate(['slug' => 'anime'], ['name' => 'Anime']);

        /** @var Post $post */
        $post = Post::factory()->create(array_merge([
            'author_id' => $this->admin()->id,
            'category_id' => $category->id,
        ], $extra));

        return $post;
    }

    private function comment(Post $post, CommentStatus $status): Comment
    {
        /** @var User $commenter */
        $commenter = User::factory()->create([
            'banned_at' => null,
            'deleted_at' => null,
        ]);

        /** @var Comment $comment */
        $comment = Comment::factory()->create([
            'post_id' => $post->id,
            'user_id' => $commenter->id,
            'status' => $status,
        ]);

        return $comment;
    }

    private function createRedirect(array $data)
    {
        return Livewire::test(ManageRedirects::class)->callAction(CreateAction::class, data: $data);
    }

    // Comments

    public function test_moderation_requires_the_moderate_permission(): void
    {
        $comment = $this->comment($this->createPost(), CommentStatus::Pending);

        /** @var User $editor */
        $editor = User::factory()->editor()->create(['banned_at' => null, 'deleted_at' => null]);
        $this->actingAs($editor);
        CommentResource::setStatus($comment, CommentStatus::Approved);
        $this->assertSame(CommentStatus::Approved, $comment->fresh()->status);

        /** @var User $reader */
        $reader = User::factory()->create(['banned_at' => null, 'deleted_at' => null]); // a reader
        $this->actingAs($reader);
        $this->expectException(AuthorizationException::class);
        CommentResource::setStatus($comment, CommentStatus::Spam);
    }

    public function test_the_comments_page_filters_by_status(): void
    {
        $post = $this->createPost();
        $pending = $this->comment($post, CommentStatus::Pending);
        $approved = $this->comment($post, CommentStatus::Approved);

        $this->actingAs($this->admin());

        Livewire::test(ManageComments::class)
            ->assertSuccessful()
            ->filterTable('status', 'approved')
            ->assertCanSeeTableRecords([$approved])
            ->assertCanNotSeeTableRecords([$pending]);
    }

    public function test_nobody_can_create_comments_from_the_admin(): void
    {
        $this->actingAs($this->admin());

        $this->assertFalse(CommentResource::canCreate());
    }

    public function test_the_navigation_badge_counts_pending_comments(): void
    {
        $this->assertNull(CommentResource::getNavigationBadge());

        $post = $this->createPost();
        $this->comment($post, CommentStatus::Pending);
        $this->comment($post, CommentStatus::Pending);
        $this->comment($post, CommentStatus::Approved);

        $this->assertSame('2', CommentResource::getNavigationBadge());
    }

    // Redirects

    public function test_redirect_paths_are_normalised(): void
    {
        $redirect = Redirect::create(['from_path' => '/old/', 'to_path' => '/new/', 'status_code' => 301]);

        $this->assertSame('/old', $redirect->from_path);
        $this->assertSame('/new', $redirect->to_path);

        $external = Redirect::create(['from_path' => '/x', 'to_path' => ' https://example.com/a/ ', 'status_code' => 302]);
        $this->assertSame('https://example.com/a/', $external->to_path);
    }

    public function test_an_admin_can_create_a_redirect(): void
    {
        $this->actingAs($this->admin());

        $this->createRedirect(['from_path' => '/old-page', 'to_path' => '/new-page', 'status_code' => 301])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('redirects', ['from_path' => '/old-page', 'to_path' => '/new-page', 'status_code' => 301]);
    }

    public function test_a_redirect_cannot_hide_a_published_post(): void
    {
        $this->createPost(['slug' => 'live-post']);
        $this->actingAs($this->admin());

        $this->createRedirect(['from_path' => '/blog/live-post', 'to_path' => '/elsewhere', 'status_code' => 301])
            ->assertHasActionErrors(['from_path']);

        $this->createRedirect(['from_path' => '/blog/not-a-post', 'to_path' => '/elsewhere', 'status_code' => 301])
            ->assertHasNoActionErrors();
    }

    public function test_duplicates_are_rejected_even_with_a_trailing_slash(): void
    {
        Redirect::create(['from_path' => '/dup', 'to_path' => '/a', 'status_code' => 301]);
        $this->actingAs($this->admin());

        $this->createRedirect(['from_path' => '/dup/', 'to_path' => '/b', 'status_code' => 301])
            ->assertHasActionErrors(['from_path']);
    }

    public function test_chains_and_loops_are_rejected(): void
    {
        Redirect::create(['from_path' => '/a', 'to_path' => '/b', 'status_code' => 301]);
        $this->actingAs($this->admin());

        // /b would be both a destination and a source.
        $this->createRedirect(['from_path' => '/b', 'to_path' => '/c', 'status_code' => 301])
            ->assertHasActionErrors(['from_path']);

        // /a is already redirected, so pointing at it creates a chain.
        $this->createRedirect(['from_path' => '/z', 'to_path' => '/a', 'status_code' => 301])
            ->assertHasActionErrors(['to_path']);

        $this->createRedirect(['from_path' => '/self', 'to_path' => '/self', 'status_code' => 301])
            ->assertHasActionErrors(['to_path']);
    }

    public function test_unsafe_destinations_and_the_home_page_are_rejected(): void
    {
        $this->actingAs($this->admin());

        foreach (['javascript:alert(1)', '//evil.com/x', 'data:text/html,hi', 'not a url', 'ftp://example.com/a'] as $destination) {
            $this->createRedirect(['from_path' => '/q', 'to_path' => $destination, 'status_code' => 301])
                ->assertHasActionErrors(['to_path']);
        }

        $this->createRedirect(['from_path' => '/', 'to_path' => '/home', 'status_code' => 301])
            ->assertHasActionErrors(['from_path']);

        $this->createRedirect(['from_path' => '/ok', 'to_path' => 'https://example.com/page', 'status_code' => 301])
            ->assertHasNoActionErrors();
    }

    // Permissions and subscribers

    public function test_editors_moderate_comments_but_cannot_see_redirects_or_subscribers(): void
    {
        /** @var User $editor */
        $editor = User::factory()->editor()->create(['banned_at' => null, 'deleted_at' => null]);
        $comment = $this->comment($this->createPost(), CommentStatus::Pending);

        $this->assertTrue(Gate::forUser($editor)->allows('moderate', $comment));
        $this->assertTrue(Gate::forUser($editor)->denies('viewAny', Redirect::class));
        $this->assertTrue(Gate::forUser($editor)->denies('viewAny', Subscriber::class));
    }

    public function test_admins_can_list_subscribers_but_not_add_them(): void
    {
        $subscriber = (new Subscriber(['email' => 'reader@example.test']))->forceFill(['token' => Str::random(64)]);
        $subscriber->save();

        $this->actingAs($this->admin());

        Livewire::test(ManageSubscribers::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$subscriber]);

        $this->assertFalse(SubscriberResource::canCreate());
    }
}