<?php

namespace Tests\Feature\Admin;

use App\Enums\PostStatus;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Category;
use App\Models\Post;
use App\Models\Redirect;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PostResourceTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->category = Category::create(['name' => 'Anime', 'slug' => 'anime']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'provider_name' => null, 'provider_id' => null]);
    }

    private function editor(): User
    {
        return User::factory()->editor()->create();
    }

    public function test_admin_can_open_the_post_pages(): void
    {
        $admin = $this->admin();
        $post = Post::factory()->create(['author_id' => $admin->id, 'category_id' => $this->category->id]);

        $this->actingAs($admin);

        Livewire::test(ListPosts::class)->assertSuccessful();
        Livewire::test(CreatePost::class)->assertSuccessful();
        Livewire::test(EditPost::class, ['record' => $post->getKey()])->assertSuccessful();
    }

    public function test_an_editor_creates_a_draft_that_they_own(): void
    {
        $editor = $this->editor();
        $this->actingAs($editor);

        Livewire::test(CreatePost::class)
            ->fillForm([
                'title' => 'My First Post',
                'slug' => 'my-first-post',
                'post_type' => 'news',
                'category_id' => $this->category->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $post = Post::where('slug', 'my-first-post')->firstOrFail();

        $this->assertSame($editor->id, $post->author_id);
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertNull($post->published_at);
    }

    public function test_a_slug_reserved_by_a_redirect_is_rejected(): void
    {
        Redirect::create(['from_path' => '/blog/taken', 'to_path' => '/blog/elsewhere', 'status_code' => 301]);
        $this->actingAs($this->admin());

        Livewire::test(CreatePost::class)
            ->fillForm([
                'title' => 'Taken',
                'slug' => 'taken',
                'post_type' => 'news',
                'category_id' => $this->category->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_editors_see_published_posts_and_their_own_drafts_only(): void
    {
        $editor = $this->editor();
        $other = $this->editor();
        $attributes = fn (User $author) => ['author_id' => $author->id, 'category_id' => $this->category->id];

        // Fixtures first: once someone is signed in, the publish guard applies to factories too.
        $mine = Post::factory()->draft()->create($attributes($editor));
        $published = Post::factory()->create($attributes($other));
        $othersDraft = Post::factory()->draft()->create($attributes($other));

        $this->actingAs($editor);

        Livewire::test(ListPosts::class)
            ->assertCanSeeTableRecords([$mine, $published])
            ->assertCanNotSeeTableRecords([$othersDraft]);
    }

    public function test_admin_urls_use_the_id_and_the_edit_page_loads_over_http(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'provider_name' => null,
            'provider_id' => null,
            'app_authentication_secret' => 'JBSWY3DPEHPK3PXP', // two-factor already set up
        ]);

        $post = Post::factory()->create([
            'author_id' => $admin->id,
            'category_id' => $this->category->id,
            'slug' => 'some-readable-slug',
        ]);

        $this->actingAs($admin);

        $url = PostResource::getUrl('edit', ['record' => $post]);

        $this->assertStringEndsWith("/admin/posts/{$post->id}/edit", $url);
        $this->get($url)->assertOk();
    }
}