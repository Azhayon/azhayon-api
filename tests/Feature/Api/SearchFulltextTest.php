<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Str;
use Tests\TestCase;

class SearchFulltextTest extends TestCase
{
    use DatabaseTruncation;

    private User $author;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->create(['role' => 'admin', 'provider_name' => null, 'provider_id' => null]);
        $this->category = Category::create(['name' => 'Anime', 'slug' => 'anime']);
    }

    protected function tearDown(): void
    {
        // DatabaseTruncation only empties the tables BEFORE each test. Clean up after this one too,
        // or its committed rows leak into the next test class.
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    private function makePost(string $title, array $extra = []): Post
    {
        return Post::factory()->create(array_merge([
            'author_id' => $this->author->id,
            'category_id' => $this->category->id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'excerpt' => 'Plain placeholder text.',
            'subtitle' => null,
            'subject_title' => 'Subject '.Str::random(8),
            'post_type' => 'news',
            'review_score' => null,
            'view_count' => 0,
        ], $extra));
    }

    /** @return list<string> */
    private function titles(string $query): array
    {
        return $this->getJson('/api/v1/search?q='.urlencode($query))->assertOk()->json('data.*.title') ?? [];
    }

    public function test_words_inside_the_excerpt_are_found_as_prefixes(): void
    {
        $this->makePost('Unrelated Title', ['excerpt' => 'A story about vengeful spirits and old curses.']);
        $this->makePost('Other Post', ['excerpt' => 'Nothing relevant here.']);

        $this->assertSame(['Unrelated Title'], $this->titles('vengeful'));
        $this->assertSame(['Unrelated Title'], $this->titles('vengef'));
        $this->assertSame(['Unrelated Title'], $this->titles('spirits curses'));
        $this->assertSame([], $this->titles('spirits dragons'));
    }

    public function test_subtitles_and_subjects_are_searched(): void
    {
        $this->makePost('First', ['subtitle' => 'The cursed technique explained']);
        $this->makePost('Second', ['subject_title' => 'Sorcerer Fight']);

        $this->assertSame(['First'], $this->titles('cursed'));
        $this->assertSame(['Second'], $this->titles('sorcerer'));
    }

    public function test_queries_made_of_short_or_common_words_do_not_fail(): void
    {
        $this->makePost('86 Eighty Six');

        $this->assertSame(['86 Eighty Six'], $this->titles('86'));

        foreach (['the', 'of', 'with the', 'a b'] as $query) {
            $this->getJson('/api/v1/search?q='.urlencode($query))->assertOk();
        }
    }

    public function test_search_operators_are_treated_as_plain_words(): void
    {
        $this->makePost('Attack on Titan');

        foreach (['+attack -titan', '"attack titan"', 'attack* titan*', '(attack', '@attack ~titan'] as $query) {
            $this->assertSame(['Attack on Titan'], $this->titles($query), $query);
        }
    }
}