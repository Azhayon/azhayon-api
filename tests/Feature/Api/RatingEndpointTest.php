<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Post;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AssertsApiShape;
use Tests\TestCase;

class RatingEndpointTest extends TestCase
{
    use AssertsApiShape;
    use RefreshDatabase;

    private User $author;

    private Category $category;

    private Post $post;

    private User $first;

    private User $second;

    private User $third;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->create(['role' => 'admin', 'provider_name' => null, 'provider_id' => null]);
        $this->category = Category::create(['name' => 'Anime', 'slug' => 'anime']);
        $this->post = $this->makePost(['slug' => 'a-post']);
        $this->first = $this->makeUser('First');
        $this->second = $this->makeUser('Second');
        $this->third = $this->makeUser('Third');
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
        ], $extra));
    }

    private function makeUser(string $name, array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'name' => $name,
            'banned_at' => null,
            'deleted_at' => null,
        ], $extra));
    }

    private function signInAs(User $user): void
    {
        Sanctum::actingAs($user, ['*']);
    }

    private function rate(string $slug, mixed $score, string $query = ''): TestResponse
    {
        return $this->putJson("/api/v1/posts/{$slug}/rating{$query}", ['score' => $score]);
    }

    private function myRating(string $slug): TestResponse
    {
        return $this->getJson("/api/v1/posts/{$slug}/rating");
    }

    private function removeRating(string $slug): TestResponse
    {
        return $this->deleteJson("/api/v1/posts/{$slug}/rating");
    }

    public function test_ratings_are_saved_changed_and_the_counters_follow(): void
    {
        $this->signInAs($this->first);
        $response = $this->rate('a-post', 4)->assertOk();

        $this->assertApiShape('MyRatingResponse', $response->json(), 'rate');
        $response->assertJsonPath('data.score', 4)->assertJsonPath('data.count', 1);
        $this->assertEquals(4.0, $response->json('data.average'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->signInAs($this->second);
        $response = $this->rate('a-post', 5)->assertOk();
        $response->assertJsonPath('data.score', 5)->assertJsonPath('data.count', 2);
        $this->assertEquals(4.5, $response->json('data.average'));

        $this->signInAs($this->first);
        $response = $this->rate('a-post', 2)->assertOk();
        $response->assertJsonPath('data.score', 2)->assertJsonPath('data.count', 2);
        $this->assertEquals(3.5, $response->json('data.average'));

        $this->assertSame(2, Rating::count());

        $post = Post::findOrFail($this->post->id);
        $this->assertSame(2, $post->rating_count);
        $this->assertEquals(3.5, (float) $post->rating_avg);
    }

    public function test_a_user_can_read_their_own_rating(): void
    {
        $this->signInAs($this->first);

        $none = $this->myRating('a-post')->assertOk();
        $this->assertApiShape('MyRatingResponse', $none->json(), 'before');
        $none->assertJsonPath('data.score', null)->assertJsonPath('data.count', 0);
        $this->assertStringContainsString('no-store', (string) $none->headers->get('Cache-Control'));

        $this->rate('a-post', 4)->assertOk();
        $this->signInAs($this->second);
        $this->rate('a-post', 2)->assertOk();

        $this->signInAs($this->first);
        $this->myRating('a-post')->assertOk()->assertJsonPath('data.score', 4)->assertJsonPath('data.count', 2);

        $this->signInAs($this->third);
        $this->myRating('a-post')->assertOk()->assertJsonPath('data.score', null)->assertJsonPath('data.count', 2);
    }

    public function test_a_rating_can_be_removed_and_removing_twice_is_fine(): void
    {
        $this->signInAs($this->first);
        $this->rate('a-post', 4)->assertOk();
        $this->signInAs($this->second);
        $this->rate('a-post', 5)->assertOk();

        $this->signInAs($this->first);
        $response = $this->removeRating('a-post')->assertOk();

        $this->assertApiShape('MyRatingResponse', $response->json(), 'removed');
        $response->assertJsonPath('data.score', null)->assertJsonPath('data.count', 1);
        $this->assertEquals(5.0, $response->json('data.average'));

        $this->removeRating('a-post')->assertOk()->assertJsonPath('data.count', 1);

        $this->assertSame(1, Rating::count());
        $this->assertSame($this->second->id, Rating::firstOrFail()->user_id);

        $this->signInAs($this->second);
        $this->removeRating('a-post')->assertOk()->assertJsonPath('data.count', 0);
        $this->assertEquals(0.0, Post::findOrFail($this->post->id)->rating_avg);
    }

    public function test_guests_cannot_use_ratings(): void
    {
        $this->myRating('a-post')->assertUnauthorized();
        $this->rate('a-post', 4)->assertUnauthorized();
        $this->removeRating('a-post')->assertUnauthorized();

        $this->assertSame(0, Rating::count());
    }

    public function test_banned_users_cannot_rate(): void
    {
        $this->signInAs($this->makeUser('Troll', ['banned_at' => now()]));

        $this->assertContains($this->rate('a-post', 5)->getStatusCode(), [401, 403]);
        $this->assertSame(0, Rating::count());
    }

    public function test_bad_scores_are_refused(): void
    {
        $this->signInAs($this->first);

        $attempts = [
            [null, ''], [0, ''], [6, ''], [-1, ''], ['x', ''], ['4', ''], [4.5, ''], [true, ''], [[4], ''], [3, '?foo=1'],
        ];

        foreach ($attempts as [$score, $query]) {
            $this->rate('a-post', $score, $query)->assertStatus(422);

            // Refused attempts count toward the limit too, so let the minute pass.
            $this->travel(2)->minutes();
        }

        $this->putJson('/api/v1/posts/a-post/rating', [])->assertStatus(422);

        $this->assertSame(0, Rating::count());

        $this->rate('a-post', 1)->assertOk();
        $this->rate('a-post', 5)->assertOk();
    }

    public function test_posts_that_are_not_live_cannot_be_rated(): void
    {
        $this->makePost(['slug' => 'a-draft', 'status' => 'draft', 'published_at' => null]);
        $this->makePost(['slug' => 'gone'])->delete();

        $this->signInAs($this->first);

        foreach (['a-draft', 'gone', 'missing', 'Bad_Slug'] as $slug) {
            $this->myRating($slug)->assertStatus(404);
            $this->rate($slug, 4)->assertStatus(404);
            $this->removeRating($slug)->assertStatus(404);
        }

        $this->assertSame(0, Rating::count());
    }

    public function test_rating_does_not_change_the_updated_date(): void
    {
        $before = Post::findOrFail($this->post->id)->updated_at;

        $this->travel(10)->minutes();
        $this->signInAs($this->first);
        $this->rate('a-post', 4)->assertOk();

        $this->assertTrue(Post::findOrFail($this->post->id)->updated_at->equalTo($before));
    }

    public function test_the_public_numbers_and_the_top_rated_sort_follow(): void
    {
        foreach ([[$this->first, 5], [$this->second, 4], [$this->third, 3]] as [$user, $score]) {
            $this->signInAs($user);
            $this->rate('a-post', $score)->assertOk();
        }

        $detail = $this->getJson('/api/v1/posts/a-post')->assertOk();
        $detail->assertJsonPath('data.rating.count', 3);
        $this->assertEquals(4.0, $detail->json('data.rating.average'));

        $this->assertContains('a-post', $this->getJson('/api/v1/posts?sort=rating')->assertOk()->json('data.*.slug'));
    }

    public function test_a_user_is_rate_limited(): void
    {
        $this->signInAs($this->first);

        foreach ([1, 2, 3, 4, 5, 1, 2, 3, 4, 5] as $score) {
            $this->rate('a-post', $score)->assertOk();
        }

        $this->rate('a-post', 3)->assertStatus(429);

        $this->assertSame(1, Rating::count());
    }

    public function test_the_request_cannot_rate_for_someone_else(): void
    {
        $this->signInAs($this->first);

        $this->putJson('/api/v1/posts/a-post/rating', ['score' => 5, 'user_id' => $this->second->id, 'post_id' => 999])->assertOk();

        $this->assertSame(1, Rating::count());
        $this->assertSame($this->first->id, Rating::firstOrFail()->user_id);
        $this->assertSame($this->post->id, Rating::firstOrFail()->post_id);
    }

    public function test_only_lowercase_slugs_have_a_route(): void
    {
        $this->signInAs($this->first);

        $this->rate('A-Post', 4)->assertNotFound();
    }
}