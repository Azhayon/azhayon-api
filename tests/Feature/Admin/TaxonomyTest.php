<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Filament\Resources\Series\Pages\ManageSeries;
use App\Filament\Resources\Tags\Pages\ManageTags;
use App\Models\Category;
use App\Models\Post;
use App\Models\Series;
use App\Models\Tag;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class TaxonomyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'provider_name' => null, 'provider_id' => null]);
    }

    private function editor(): User
    {
        return User::factory()->editor()->create();
    }

    private function postIn(Category $category, ?Series $series = null): Post
    {
        return Post::factory()->create([
            'author_id' => $this->editor()->id,
            'category_id' => $category->id,
            'series_id' => $series?->id,
        ]);
    }

    public function test_admin_can_open_the_taxonomy_pages(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(ManageCategories::class)->assertSuccessful();
        Livewire::test(ManageTags::class)->assertSuccessful();
        Livewire::test(ManageSeries::class)->assertSuccessful();
    }

    public function test_admin_can_create_a_category(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(ManageCategories::class)
            ->callAction(CreateAction::class, data: ['name' => 'Light Novels', 'slug' => 'light-novels'])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('categories', ['slug' => 'light-novels']);
    }

    public function test_slugs_must_be_unique_and_well_formed(): void
    {
        $this->actingAs($this->admin());
        Category::create(['name' => 'Anime', 'slug' => 'anime']);

        Livewire::test(ManageCategories::class)
            ->callAction(CreateAction::class, data: ['name' => 'Other', 'slug' => 'anime'])
            ->assertHasActionErrors(['slug' => 'unique']);

        Livewire::test(ManageCategories::class)
            ->callAction(CreateAction::class, data: ['name' => 'Bad', 'slug' => 'Bad Slug!'])
            ->assertHasActionErrors(['slug' => 'regex']);
    }

    public function test_editors_can_manage_but_not_delete_taxonomy(): void
    {
        $editor = $this->editor();
        $category = Category::create(['name' => 'Anime', 'slug' => 'anime']);
        $tag = Tag::create(['name' => 'MAPPA', 'slug' => 'mappa', 'type' => 'studio']);
        $series = Series::create(['title' => 'Jujutsu Kaisen', 'slug' => 'jujutsu-kaisen']);

        foreach ([$category, $tag, $series] as $record) {
            $this->assertTrue(Gate::forUser($editor)->allows('update', $record));
            $this->assertTrue(Gate::forUser($editor)->denies('delete', $record));
        }
    }

    public function test_readers_cannot_see_taxonomy(): void
    {
        $reader = User::factory()->create();

        foreach ([Category::class, Tag::class, Series::class] as $model) {
            $this->assertTrue(Gate::forUser($reader)->denies('viewAny', $model));
        }
    }

    public function test_a_category_with_posts_cannot_be_deleted_even_by_an_admin(): void
    {
        $admin = $this->admin();
        $used = Category::create(['name' => 'Anime', 'slug' => 'anime']);
        $empty = Category::create(['name' => 'Empty', 'slug' => 'empty']);

        $this->assertTrue(Gate::forUser($admin)->allows('delete', $empty));

        $post = $this->postIn($used);
        $this->assertTrue(Gate::forUser($admin)->denies('delete', $used));

        // A soft-deleted post still holds the foreign key, so it still blocks deletion.
        $post->delete();
        $this->assertTrue(Gate::forUser($admin)->denies('delete', $used));
    }

    public function test_deleting_a_series_keeps_its_posts(): void
    {
        $admin = $this->admin();
        $category = Category::create(['name' => 'Anime', 'slug' => 'anime']);
        $series = Series::create(['title' => 'Solo Leveling', 'slug' => 'solo-leveling']);
        $post = $this->postIn($category, $series);

        $this->assertTrue(Gate::forUser($admin)->allows('delete', $series));

        $series->delete();

        $this->assertNull($post->fresh()->series_id);
    }
}