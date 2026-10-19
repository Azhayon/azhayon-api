<?php

namespace Tests\Feature\Seo;

use App\Filament\Resources\ContentHealth\Pages\ListContentHealth;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContentHealthTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        /** @var User $admin */
        $admin = User::factory()->create([
            'role' => 'admin',
            'provider_name' => null,
            'provider_id' => null,
            'banned_at' => null,
            'deleted_at' => null,
        ]);

        $this->admin = $admin;
        $this->category = Category::create(['name' => 'Anime', 'slug' => 'anime']);
    }

    private function createPost(array $extra = []): Post
    {
        /** @var Post $post */
        $post = Post::factory()->create(array_merge([
            'author_id' => $this->admin->id,
            'category_id' => $this->category->id,
        ], $extra));

        return $post;
    }

    public function test_the_page_lists_published_posts_and_filters_them(): void
    {
        $tag = Tag::create(['name' => 'A', 'slug' => 'a', 'type' => 'general']);
        $tagged = $this->createPost();
        $tagged->tags()->attach($tag->id);
        $untagged = $this->createPost();
        $draft = $this->createPost(['status' => 'draft', 'published_at' => null]);

        $this->actingAs($this->admin);

        Livewire::test(ListContentHealth::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$tagged, $untagged])
            ->assertCanNotSeeTableRecords([$draft])
            ->filterTable('no_tags', true)
            ->assertCanSeeTableRecords([$untagged])
            ->assertCanNotSeeTableRecords([$tagged]);
    }

    public function test_the_link_finder_opens_on_the_post_form(): void
    {
        $this->createPost(['title' => 'Something to link']);
        $this->actingAs($this->admin);

        Livewire::test(CreatePost::class)
            ->assertSuccessful()
            ->mountAction('linkFinder')
            ->assertSuccessful();
    }
}