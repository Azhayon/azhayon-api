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

class SearchEndpointTest extends TestCase
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

    public function test_the_query_is_validated(): void
    {
        $this->makePost('Anything');

        $rejected = ['', 'q=', 'q=a', 'q=%25%25', 'q=--', 'q='.str_repeat('a', 81), 'q=ok&foo=1', 'q=ok&per_page=25', 'q=ok&page=0', 'q%5B%5D=ok'];

        foreach ($rejected as $query) {
            $this->getJson('/api/v1/search?'.$query)->assertStatus(422);
        }

        $this->getJson('/api/v1/search')->assertJsonValidationErrors(['q']);
        $this->getJson('/api/v1/search?q=ok&foo=1')->assertJsonValidationErrors(['foo']);

        $this->getJson('/api/v1/search?q=ab')->assertOk();
        $this->getJson('/api/v1/search?q='.str_repeat('a', 80))->assertOk();
    }

    public function test_titles_rank_exact_then_prefix_then_contains(): void
    {
        $this->makePost('Boruto: Naruto Next Generations', ['published_at' => now()->subHours(1)]);
        $this->makePost('Naruto Shippuden', ['published_at' => now()->subHours(2)]);
        $this->makePost('Naruto', ['published_at' => now()->subHours(3)]);
        $this->makePost('Unrelated Show');

        $this->assertSame(
            ['Naruto', 'Naruto Shippuden', 'Boruto: Naruto Next Generations'],
            $this->titles('NARUTO'),
        );
    }

    public function test_the_subject_is_searched_and_ranked(): void
    {
        $this->makePost('Season review', ['subject_title' => 'Jujutsu Kaisen']);
        $this->makePost('Fan theories', ['subject_title' => 'Jujutsu Kaisen Zero']);
        $this->makePost('Something else', ['subject_title' => 'Different Show']);

        $this->assertSame(['Season review', 'Fan theories'], $this->titles('jujutsu kaisen'));
        $this->assertSame(['Season review', 'Fan theories'], $this->titles('jujutsu'));
    }

    public function test_tags_and_series_find_their_posts(): void
    {
        $tag = Tag::create(['name' => 'MAPPA', 'slug' => 'mappa', 'type' => 'studio']);
        $series = Series::create(['title' => 'Solo Leveling', 'slug' => 'solo-leveling']);

        $this->makePost('Tagged without the word')->tags()->attach($tag->id);
        $this->makePost('Episode guide', ['series_id' => $series->id]);
        $this->makePost('Plain post');

        $this->assertSame(['Tagged without the word'], $this->titles('mappa'));
        $this->assertSame(['Tagged without the word'], $this->titles('map'));
        $this->assertSame(['Episode guide'], $this->titles('solo'));
        $this->assertSame(['Episode guide'], $this->titles('solo leveling'));
        $this->assertSame([], $this->titles('levelling'));
    }

    public function test_only_live_posts_are_searched_but_noindex_posts_are_included(): void
    {
        $this->makePost('Zebra live');
        $this->makePost('Zebra hidden from search engines', ['noindex' => true]);
        $this->makePost('Zebra draft', ['status' => 'draft', 'published_at' => null]);
        $this->makePost('Zebra scheduled', ['status' => 'scheduled', 'published_at' => now()->addDay()]);
        $this->makePost('Zebra future', ['published_at' => now()->addHour()]);
        $this->makePost('Zebra trashed')->delete();

        $this->assertEqualsCanonicalizing(
            ['Zebra live', 'Zebra hidden from search engines'],
            $this->titles('zebra'),
        );
    }

    public function test_wildcard_characters_are_matched_literally(): void
    {
        $this->makePost('100% Orange');
        $this->makePost('1000 Orange');
        $this->makePost('A_C Club');
        $this->makePost('ABC Club');

        $this->assertSame(['100% Orange'], $this->titles('100%'));
        $this->assertSame(['A_C Club'], $this->titles('a_c'));
    }

    public function test_pagination_and_the_echoed_query(): void
    {
        foreach (range(1, 30) as $number) {
            $this->makePost("Matching post {$number}", ['published_at' => now()->subMinutes($number)]);
        }

        $first = $this->getJson('/api/v1/search?q=Matching%20post')->assertOk();

        $first->assertJsonPath('meta', ['page' => 1, 'per_page' => 12, 'total' => 30, 'last_page' => 3, 'query' => 'Matching post']);
        $this->assertCount(12, $first->json('data'));

        $this->assertCount(6, $this->getJson('/api/v1/search?q=matching&page=3')->assertOk()->json('data'));
        $this->assertCount(24, $this->getJson('/api/v1/search?q=matching&per_page=24')->assertOk()->json('data'));
        $this->getJson('/api/v1/search?q=matching&page=4')->assertStatus(404)->assertExactJson(['message' => 'Not found.']);

        $this->getJson('/api/v1/search?q=zzzzzz')->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
        $this->getJson('/api/v1/search?q=zzzzzz&page=2')->assertStatus(404);
    }

    public function test_non_latin_titles_are_found(): void
    {
        $this->makePost('আমি বাংলা গান');
        $this->makePost('Unrelated');

        $this->assertSame(['আমি বাংলা গান'], $this->titles('বাংলা'));
    }

    public function test_search_uses_a_constant_number_of_queries(): void
    {
        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/search?q=match')->assertOk();

            return count(DB::getQueryLog());
        };

        foreach (range(1, 3) as $number) {
            $this->makePost("Match {$number}");
        }
        $few = $countQueries();

        foreach (range(4, 18) as $number) {
            $this->makePost("Match {$number}");
        }

        $this->assertSame($few, $countQueries());
    }

    public function test_responses_carry_the_approved_cache_headers(): void
    {
        $response = $this->getJson('/api/v1/search?q=anything')->assertOk();
        $cache = (string) $response->headers->get('Cache-Control');

        foreach (['public', 'max-age=60', 's-maxage=300', 'stale-while-revalidate=600'] as $part) {
            $this->assertStringContainsString($part, $cache);
        }

        $this->assertNotNull($response->headers->get('ETag'));
    }
}