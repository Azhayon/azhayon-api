<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Post;
use App\Models\Series;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SearchSuggestEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->create(['role' => 'admin', 'provider_name' => null, 'provider_id' => null]);
        $this->category = Category::create(['name' => 'Anime', 'slug' => 'anime']);
    }

    private function makePost(string $title, array $extra = []): Post
    {
        return Post::factory()->create(array_merge([
            'author_id' => $this->author->id,
            'category_id' => $this->category->id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'subject_title' => 'Subject '.Str::random(8),
            'post_type' => 'news',
            'review_score' => null,
            'view_count' => 0,
        ], $extra));
    }

    /** @return array<string, mixed> */
    private function suggest(string $query): array
    {
        return $this->getJson('/api/v1/search/suggest?q='.urlencode($query))->assertOk()->json('data');
    }

    public function test_suggestions_mix_types_and_rank_by_match_quality(): void
    {
        $series = Series::create(['title' => 'Jujutsu Kaisen', 'slug' => 'jujutsu-kaisen']);
        $tag = Tag::create(['name' => 'Jujutsu Fans', 'slug' => 'jujutsu-fans', 'type' => 'general']);

        $first = $this->makePost('Jujutsu Kaisen Season 3 Review', [
            'slug' => 'season-3-review', 'subject_title' => 'Jujutsu Kaisen', 'series_id' => $series->id,
        ]);
        $this->makePost('Why Jujutsu Kaisen Works', ['slug' => 'why-it-works', 'subject_title' => 'Jujutsu Kaisen']);
        $first->tags()->attach($tag->id);

        $items = $this->suggest('jujutsu');

        $this->assertSame(['series', 'tag', 'subject', 'post', 'post'], array_column($items, 'type'));
        $this->assertSame(
            ['Jujutsu Kaisen', 'Jujutsu Fans', 'Jujutsu Kaisen', 'Jujutsu Kaisen Season 3 Review', 'Why Jujutsu Kaisen Works'],
            array_column($items, 'label'),
        );
        $this->assertSame(
            ['/series/jujutsu-kaisen', '/tag/jujutsu-fans', '/search?q=Jujutsu%20Kaisen', '/blog/season-3-review', '/blog/why-it-works'],
            array_column($items, 'path'),
        );
        $this->assertSame([null, 'general', null, null, null], array_column($items, 'tag_type'));
    }

    public function test_at_most_eight_suggestions_are_returned(): void
    {
        foreach (range(1, 12) as $number) {
            $this->makePost("Match number {$number}");
        }

        $this->assertCount(8, $this->suggest('match'));
    }

    public function test_hidden_content_is_never_suggested(): void
    {
        $this->makePost('Hidden visible');
        $this->makePost('Hidden gem', ['noindex' => true, 'subject_title' => 'Hidden Subject']);
        $this->makePost('Hidden draft', ['status' => 'draft', 'published_at' => null]);
        $this->makePost('Hidden future', ['published_at' => now()->addHour()]);
        $this->makePost('Hidden trashed')->delete();

        $draft = $this->makePost('Only a draft', ['status' => 'draft', 'published_at' => null]);
        $draft->tags()->attach(Tag::create(['name' => 'Hidden Tag', 'slug' => 'hidden-tag', 'type' => 'general'])->id);
        Series::create(['title' => 'Hidden Series', 'slug' => 'hidden-series']);

        $items = $this->suggest('hidden');

        $this->assertSame(['Hidden visible'], array_column($items, 'label'));
    }

    public function test_an_exact_match_outranks_a_prefix_match(): void
    {
        $tag = Tag::create(['name' => 'Mappa', 'slug' => 'mappa', 'type' => 'studio']);
        $series = Series::create(['title' => 'Mappa Stories', 'slug' => 'mappa-stories']);

        $this->makePost('A post about the studio', ['series_id' => $series->id])->tags()->attach($tag->id);

        $items = $this->suggest('mappa');

        $this->assertSame(['tag', 'series'], array_column($items, 'type'));
        $this->assertSame(['studio', null], array_column($items, 'tag_type'));
    }

    public function test_the_query_is_validated(): void
    {
        foreach (['', 'q=a', 'q=%25', 'q=ok&page=2', 'q=ok&foo=1', 'q='.str_repeat('a', 81)] as $query) {
            $this->getJson('/api/v1/search/suggest?'.$query)->assertStatus(422);
        }

        $this->getJson('/api/v1/search/suggest?q=100%25')->assertOk();
        $this->getJson('/api/v1/search/suggest?q=zzzzzzzz')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_suggestions_carry_the_approved_cache_headers(): void
    {
        $response = $this->getJson('/api/v1/search/suggest?q=ab')->assertOk();
        $cache = (string) $response->headers->get('Cache-Control');

        foreach (['public', 'max-age=60', 's-maxage=300', 'stale-while-revalidate=600'] as $part) {
            $this->assertStringContainsString($part, $cache);
        }
    }

    public function test_suggestions_use_a_constant_number_of_queries(): void
    {
        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/search/suggest?q=match')->assertOk();

            return count(DB::getQueryLog());
        };

        foreach (range(1, 3) as $number) {
            $this->makePost("Match {$number}");
        }
        $few = $countQueries();

        foreach (range(4, 25) as $number) {
            $this->makePost("Match {$number}");
        }

        $this->assertSame($few, $countQueries());
    }
}