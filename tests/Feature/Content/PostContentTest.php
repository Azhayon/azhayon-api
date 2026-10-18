<?php

namespace Tests\Feature\Content;

use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PostContentTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->author = User::factory()->create(['role' => 'admin', 'provider_name' => null, 'provider_id' => null]);
        $this->category = Category::create(['name' => 'Anime', 'slug' => 'anime']);
    }

    private function make(array $extra = []): Post
    {
        return Post::create(array_merge([
            'author_id' => $this->author->id,
            'category_id' => $this->category->id,
            'title' => 'T',
            'slug' => 't',
        ], $extra));
    }

    private function doc(string $text): array
    {
        return [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'attrs' => ['textAlign' => 'start'], 'content' => [['type' => 'text', 'text' => $text]]]],
        ];
    }

    public function test_saving_cleans_the_content_and_records_the_reading_time(): void
    {
        $post = $this->make(['content' => $this->doc(implode(' ', array_fill(0, 450, 'word')))]);
        $post->refresh();

        $this->assertArrayNotHasKey('attrs', $post->content['content'][0]);
        $this->assertSame(3, $post->reading_time_minutes);
    }

    public function test_invalid_content_cannot_be_saved(): void
    {
        try {
            $this->make(['content' => ['type' => 'doc', 'content' => [['type' => 'table']]]]);
            $this->fail('Invalid content was saved.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('content', $e->errors());
            $this->assertArrayHasKey('data.content', $e->errors());
        }

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_published_and_scheduled_posts_need_content(): void
    {
        $this->make(['slug' => 'draft']); // drafts may be empty

        foreach (['published', 'scheduled'] as $status) {
            try {
                $this->make(['slug' => $status, 'status' => $status, 'published_at' => now()->addDay()]);
                $this->fail("An empty {$status} post was saved.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->make(['slug' => 'ok', 'status' => 'published', 'content' => $this->doc('Hello')]);
        $this->assertDatabaseHas('posts', ['slug' => 'ok']);
    }

    public function test_the_post_form_rejects_pasted_images(): void
    {
        $this->actingAs($this->author);

        Livewire::test(CreatePost::class)
            ->fillForm([
                'title' => 'Pasted',
                'slug' => 'pasted',
                'post_type' => 'news',
                'category_id' => $this->category->id,
                'content' => ['type' => 'doc', 'content' => [[
                    'type' => 'paragraph',
                    'content' => [['type' => 'image', 'attrs' => ['src' => 'https://example.com/a.jpg']]],
                ]]],
            ])
            ->call('create')
            ->assertHasFormErrors(['content']);

        $this->assertDatabaseMissing('posts', ['slug' => 'pasted']);
    }
}