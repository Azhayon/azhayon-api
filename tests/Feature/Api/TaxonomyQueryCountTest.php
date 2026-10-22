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

class TaxonomyQueryCountTest extends TestCase
{
    use RefreshDatabase;

    /** Adds one tag and one series, each with a live post. */
    private function addPair(User $author, Category $category, int $number): void
    {
        $tag = Tag::create(['name' => "Tag {$number}", 'slug' => "tag-{$number}", 'type' => 'genre']);
        $series = Series::create(['title' => "Series {$number}", 'slug' => "series-{$number}"]);

        Post::factory()->create([
            'author_id' => $author->id,
            'category_id' => $category->id,
            'series_id' => $series->id,
            'subject_title' => 'Subject '.Str::random(8),
        ])->tags()->attach($tag->id);
    }

    public function test_taxonomy_lists_use_a_constant_number_of_queries(): void
    {
        $author = User::factory()->create(['role' => 'admin', 'provider_name' => null, 'provider_id' => null]);
        $category = Category::create(['name' => 'Anime', 'slug' => 'anime']);

        $countQueries = function (string $url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($url)->assertOk();

            return count(DB::getQueryLog());
        };

        foreach (range(1, 3) as $number) {
            $this->addPair($author, $category, $number);
        }

        $tagsFew = $countQueries('/api/v1/tags');
        $seriesFew = $countQueries('/api/v1/series');

        foreach (range(4, 12) as $number) {
            $this->addPair($author, $category, $number);
        }

        $this->assertSame($tagsFew, $countQueries('/api/v1/tags'));
        $this->assertSame($seriesFew, $countQueries('/api/v1/series'));
    }
}