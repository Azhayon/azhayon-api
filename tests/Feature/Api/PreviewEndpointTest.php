<?php

namespace Tests\Feature\Api;

use App\Filament\Resources\Posts\Pages\EditPost;
use App\Models\Category;
use App\Models\Post;
use App\Models\Series;
use App\Models\User;
use App\Support\Preview\PreviewLink;
use DateTimeInterface;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\AssertsApiShape;
use Tests\TestCase;

class PreviewEndpointTest extends TestCase
{
    use AssertsApiShape;
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

    private function makePost(array $extra = []): Post
    {
        return Post::factory()->create(array_merge([
            'author_id' => $this->author->id,
            'category_id' => $this->category->id,
            'subject_title' => 'Subject '.Str::random(8),
            'post_type' => 'news',
            'review_score' => null,
            'view_count' => 0,
            'noindex' => false,
        ], $extra));
    }

    private function makeDraft(array $extra = []): Post
    {
        return $this->makePost(array_merge(['status' => 'draft', 'published_at' => null], $extra));
    }

    /** The API address of a post's preview, signed for one hour unless told otherwise. */
    private function signedUrl(int $id, ?DateTimeInterface $expires = null): string
    {
        return URL::temporarySignedRoute('preview.post', $expires ?? now()->addHour(), ['id' => $id], absolute: false);
    }

    public function test_a_draft_can_be_previewed_with_a_valid_link(): void
    {
        $draft = $this->makeDraft([
            'slug' => 'secret-draft',
            'title' => 'A draft title',
            'content' => ['type' => 'doc', 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Draft body.']]],
            ]],
        ]);

        $response = $this->getJson($this->signedUrl($draft->id))->assertOk();

        $this->assertApiShape('PreviewResponse', $response->json(), 'preview');

        $response
            ->assertJsonPath('data.title', 'A draft title')
            ->assertJsonPath('data.content.content.0.content.0.text', 'Draft body.')
            ->assertJsonPath('data.seo.noindex', true)
            ->assertJsonPath('meta.preview.status', 'draft');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $response->json('data.published_at'));
        $this->assertEqualsWithDelta(
            now()->addHour()->timestamp,
            \Carbon\Carbon::parse($response->json('meta.preview.expires_at'))->timestamp,
            5,
        );

        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
        $this->assertStringNotContainsString('public', $cache);
        $this->assertNull($response->headers->get('ETag'));
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
    }

    public function test_every_status_can_be_previewed(): void
    {
        $posts = [
            'draft' => $this->makeDraft(),
            'scheduled' => $this->makePost(['status' => 'scheduled', 'published_at' => now()->addDay()]),
            'published' => $this->makePost(),
        ];

        foreach ($posts as $status => $post) {
            $response = $this->getJson($this->signedUrl($post->id))->assertOk();

            $response->assertJsonPath('meta.preview.status', $status);
            $this->assertApiShape('PreviewResponse', $response->json(), $status);
        }
    }

    public function test_a_preview_never_exposes_the_draft_through_the_public_api(): void
    {
        $draft = $this->makeDraft(['slug' => 'still-hidden']);

        $this->getJson($this->signedUrl($draft->id))->assertOk();

        $this->getJson('/api/v1/posts/still-hidden')->assertStatus(404);
        $this->assertNotContains('still-hidden', $this->getJson('/api/v1/posts')->json('data.*.slug'));
        $this->assertSame(0, $this->getJson('/api/v1/sitemap')->json('data.posts.total'));
    }

    public function test_bad_links_are_refused_without_revealing_anything(): void
    {
        $draft = $this->makeDraft();
        $other = $this->makeDraft();
        $url = $this->signedUrl($draft->id);
        $forbidden = ['message' => 'Invalid signature.'];

        // Tampered signature.
        $tampered = substr($url, 0, -1).(str_ends_with($url, 'a') ? 'b' : 'a');
        $this->getJson($tampered)->assertStatus(403)->assertExactJson($forbidden);

        // A valid signature moved to another post.
        $moved = str_replace("/posts/{$draft->id}?", "/posts/{$other->id}?", $url);
        $this->assertNotSame($url, $moved);
        $this->getJson($moved)->assertStatus(403);

        // Missing or incomplete parameters, and an extra parameter.
        $this->getJson("/api/v1/preview/posts/{$draft->id}")->assertStatus(403);
        $this->getJson("/api/v1/preview/posts/{$draft->id}?expires=".(time() + 3600))->assertStatus(403);
        $this->getJson($url.'&foo=1')->assertStatus(403);

        // Existing and non-existing posts look the same without a valid signature.
        $this->getJson('/api/v1/preview/posts/999999')->assertStatus(403)->assertExactJson($forbidden);

        // A correctly signed link to a post that does not exist is a plain 404.
        $this->getJson($this->signedUrl(999999))->assertStatus(404)->assertExactJson(['message' => 'Not found.']);

        // Deleted posts cannot be previewed either.
        $draft->delete();
        $this->getJson($url)->assertStatus(404);
    }

    public function test_links_expire_after_an_hour(): void
    {
        $draft = $this->makeDraft();
        $url = $this->signedUrl($draft->id);

        $this->travel(59)->minutes();
        $this->getJson($url)->assertOk();

        $this->travel(2)->minutes();
        $this->getJson($url)->assertStatus(403)->assertExactJson(['message' => 'Invalid signature.']);
    }

    public function test_edits_show_immediately_because_nothing_is_cached(): void
    {
        $draft = $this->makeDraft(['title' => 'Before']);
        $url = $this->signedUrl($draft->id);

        $this->getJson($url)->assertJsonPath('data.title', 'Before');

        $draft->update(['title' => 'After']);

        $this->getJson($url)->assertJsonPath('data.title', 'After');
    }

    public function test_series_navigation_treats_a_draft_as_published_now(): void
    {
        $series = Series::create(['title' => 'S', 'slug' => 's']);

        $this->makePost(['slug' => 'older', 'series_id' => $series->id, 'published_at' => now()->subDays(3)]);
        $this->makePost(['slug' => 'newest', 'series_id' => $series->id, 'published_at' => now()->subDay()]);
        $draft = $this->makeDraft(['series_id' => $series->id]);

        $this->getJson($this->signedUrl($draft->id))
            ->assertOk()
            ->assertJsonPath('data.series.slug', 's')
            ->assertJsonPath('data.series.total', 2)
            ->assertJsonPath('data.series.previous.slug', 'newest')
            ->assertJsonPath('data.series.next', null);
    }

    public function test_the_link_the_admin_hands_out_leads_to_a_working_preview(): void
    {
        $draft = $this->makeDraft();

        $link = PreviewLink::for($draft);

        $this->assertStringStartsWith("http://localhost:3000/preview/{$draft->id}?", $link);

        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        $this->assertSame(['expires', 'signature'], array_keys($query));

        $this->getJson("/api/v1/preview/posts/{$draft->id}?".http_build_query($query))->assertOk();
    }

    public function test_staff_who_can_edit_a_post_get_a_preview_link(): void
    {
        $editor = User::factory()->editor()->create();
        $adminsPost = $this->makeDraft();
        $editorsPost = $this->makeDraft(['author_id' => $editor->id]);

        $this->actingAs($this->author);
        Livewire::test(EditPost::class, ['record' => $adminsPost->getKey()])
            ->mountAction('preview')
            ->assertSuccessful();

        $this->actingAs($editor);
        Livewire::test(EditPost::class, ['record' => $editorsPost->getKey()])
            ->mountAction('preview')
            ->assertSuccessful();
    }
}