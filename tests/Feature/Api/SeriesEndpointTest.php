<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Post;
use App\Models\Series;
use App\Models\User;
use App\Support\Images\ImageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SeriesEndpointTest extends TestCase
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

    /** @param  list<\Carbon\CarbonInterface>  $postDates  Publish dates of live posts in the series. */
    private function makeSeries(string $title, array $postDates, array $extra = []): Series
    {
        $series = Series::create(array_merge(['title' => $title, 'slug' => Str::slug($title)], $extra));

        foreach ($postDates as $date) {
            Post::factory()->create([
                'author_id' => $this->author->id,
                'category_id' => $this->category->id,
                'series_id' => $series->id,
                'subject_title' => 'Subject '.Str::random(8),
                'post_type' => 'news',
                'published_at' => $date,
            ]);
        }

        return $series;
    }

    private function storeImage(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);

        return app(ImageProcessor::class)->store((string) ob_get_clean());
    }

    public function test_only_series_with_live_posts_are_listed_most_recent_first(): void
    {
        $this->makeSeries('Alpha', [now()->subDays(10), now()->subDays(8)]);
        $this->makeSeries('Beta', [now()->subDays(2)]);

        $gamma = $this->makeSeries('Gamma', []);
        Post::factory()->create([
            'author_id' => $this->author->id,
            'category_id' => $this->category->id,
            'series_id' => $gamma->id,
            'status' => 'draft',
            'published_at' => null,
        ]);

        $latest = $this->getJson('/api/v1/series')->assertOk();

        $this->assertSame(['beta', 'alpha'], $latest->json('data.*.slug'));
        $this->assertSame([1, 2], $latest->json('data.*.posts_count'));
        $latest
            ->assertJsonPath('data.0.path', '/series/beta')
            ->assertJsonPath('data.0.image', null)
            ->assertJsonPath('meta.total', 2);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $latest->json('data.0.latest_post_at'));

        $this->assertSame(['alpha', 'beta'], $this->getJson('/api/v1/series?sort=title')->json('data.*.slug'));
    }

    public function test_the_detail_has_the_cover_and_seo_fields(): void
    {
        Storage::fake('uploads');

        $cover = $this->storeImage(1000, 500);

        $this->makeSeries('With Cover', [now()->subDay()], [
            'description' => 'Hub description.',
            'meta_title' => 'Custom hub title',
            'cover_image' => $cover,
        ]);
        $this->makeSeries('Plain', [now()->subDay()]);

        $this->getJson('/api/v1/series/with-cover')
            ->assertOk()
            ->assertJsonPath('data.title', 'With Cover')
            ->assertJsonPath('data.description', 'Hub description.')
            ->assertJsonPath('data.image.width', 1000)
            ->assertJsonPath('data.image.height', 500)
            ->assertJsonPath('data.image.alt', 'With Cover')
            ->assertJsonPath('data.seo.title', 'Custom hub title')
            ->assertJsonPath('data.seo.description', 'Hub description.')
            ->assertJsonPath('data.seo.canonical_url', 'http://localhost:3000/series/with-cover')
            ->assertJsonPath('data.seo.noindex', false);

        $seoImage = $this->getJson('/api/v1/series/with-cover')->json('data.seo.image');
        $this->assertStringEndsWith(dirname($cover).'/1000.webp', $seoImage);

        $this->getJson('/api/v1/series/plain')
            ->assertOk()
            ->assertJsonPath('data.image', null)
            ->assertJsonPath('data.seo.title', 'Plain')
            ->assertJsonPath('data.seo.description', null)
            ->assertJsonPath('data.seo.image', null);
    }

    public function test_unused_and_unknown_series_are_a_generic_404(): void
    {
        $this->makeSeries('Empty', []);

        foreach (['empty', 'missing', 'Bad_Slug'] as $slug) {
            $this->getJson('/api/v1/series/'.$slug)->assertStatus(404)->assertExactJson(['message' => 'Not found.']);
        }

        // An empty site still answers the first page, but not a later one.
        $this->getJson('/api/v1/series')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/series?page=2')->assertStatus(404);
    }

    public function test_bad_input_is_refused(): void
    {
        foreach (['sort=random', 'per_page=0', 'per_page=101', 'page=0', 'foo=1'] as $query) {
            $this->getJson('/api/v1/series?'.$query)->assertStatus(422);
        }

        $this->getJson('/api/v1/series?foo=1')->assertJsonValidationErrors(['foo']);
        $this->getJson('/api/v1/series?sort=&per_page=100')->assertOk();
    }
}