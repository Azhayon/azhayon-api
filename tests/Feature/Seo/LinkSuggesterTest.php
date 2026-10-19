<?php

namespace Tests\Feature\Seo;

use App\Models\Category;
use App\Models\Post;
use App\Models\Series;
use App\Models\Tag;
use App\Models\User;
use App\Support\Links\LinkSuggester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LinkSuggesterTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var User $author */
        $author = User::factory()->create([
            'role' => 'admin',
            'provider_name' => null,
            'provider_id' => null,
            'banned_at' => null,
            'deleted_at' => null,
        ]);

        $this->author = $author;
    }

    private function createPost(string $title, Category $category, array $extra = []): Post
    {
        /** @var Post $post */
        $post = Post::factory()->create(array_merge([
            'author_id' => $this->author->id,
            'category_id' => $category->id,
            'title' => $title,
            'slug' => Str::slug($title),
            'subject_title' => 'Some other subject',
        ], $extra));

        return $post;
    }

    public function test_suggestions_are_ranked_by_what_they_share(): void
    {
        $one = Category::create(['name' => 'One', 'slug' => 'one']);
        $two = Category::create(['name' => 'Two', 'slug' => 'two']);
        $series = Series::create(['title' => 'S', 'slug' => 's']);
        $tagA = Tag::create(['name' => 'A', 'slug' => 'a', 'type' => 'general']);
        $tagB = Tag::create(['name' => 'B', 'slug' => 'b', 'type' => 'general']);

        $bothTags = $this->createPost('Both tags', $two);
        $bothTags->tags()->attach([$tagA->id, $tagB->id]);
        $this->createPost('In series', $two, ['series_id' => $series->id]);
        $this->createPost('Same subject', $two, ['subject_title' => 'Jujutsu Kaisen']);
        $this->createPost('Same category', $one);
        $this->createPost('Unrelated', $two);
        $this->createPost('Draft in series', $two, ['series_id' => $series->id, 'status' => 'draft', 'published_at' => null]);
        $self = $this->createPost('Itself', $two, ['series_id' => $series->id]);

        $result = app(LinkSuggester::class)->suggest([
            'category_id' => $one->id,
            'series_id' => $series->id,
            'subject_title' => 'Jujutsu Kaisen',
            'tags' => [$tagA->id, $tagB->id],
        ], $self->id);

        $this->assertSame(['Both tags', 'In series', 'Same subject', 'Same category'], array_column($result, 'title'));
        $this->assertSame('/blog/in-series', $result[1]['path']);
    }

    public function test_without_any_signal_the_newest_published_posts_are_suggested(): void
    {
        $category = Category::create(['name' => 'One', 'slug' => 'one']);

        $this->createPost('Oldest', $category, ['published_at' => now()->subDays(3)]);
        $this->createPost('Middle', $category, ['published_at' => now()->subDays(2)]);
        $this->createPost('Newest', $category, ['published_at' => now()->subDay()]);
        $this->createPost('A draft', $category, ['status' => 'draft', 'published_at' => null]);

        $result = app(LinkSuggester::class)->suggest([], null, 2);

        $this->assertSame(['Newest', 'Middle'], array_column($result, 'title'));
    }

    public function test_junk_input_is_ignored(): void
    {
        $category = Category::create(['name' => 'One', 'slug' => 'one']);
        $tag = Tag::create(['name' => 'A', 'slug' => 'a', 'type' => 'general']);

        $tagged = $this->createPost('Tagged', $category);
        $tagged->tags()->attach($tag->id);

        $result = app(LinkSuggester::class)->suggest([
            'category_id' => '',
            'series_id' => 'abc',
            'subject_title' => ['not', 'a', 'string'],
            'tags' => ['x', null, (string) $tag->id, -4],
        ]);

        $this->assertSame(['Tagged'], array_column($result, 'title'));
    }
}