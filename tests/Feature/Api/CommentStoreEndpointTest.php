<?php

namespace Tests\Feature\Api;

use App\Enums\CommentStatus;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AssertsApiShape;
use Tests\TestCase;

class CommentStoreEndpointTest extends TestCase
{
    use AssertsApiShape;
    use RefreshDatabase;

    private User $author;

    private Category $category;

    private Post $post;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->create(['role' => 'admin', 'provider_name' => null, 'provider_id' => null]);
        $this->category = Category::create(['name' => 'Anime', 'slug' => 'anime']);
        $this->post = $this->makePost(['slug' => 'a-post']);
        $this->member = $this->makeUser('Ayon');
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

    private function makeComment(Post $post, string $body, array $extra = []): Comment
    {
        return Comment::factory()->create(array_merge([
            'post_id' => $post->id,
            'user_id' => $this->makeUser('Other')->id,
            'body' => $body,
            'is_spoiler' => false,
            'status' => CommentStatus::Approved,
        ], $extra));
    }

    private function signInAs(User $user): void
    {
        Sanctum::actingAs($user, ['*']);
    }

    private function submitComment(string $slug, array $payload, string $query = ''): TestResponse
    {
        return $this->postJson("/api/v1/posts/{$slug}/comments{$query}", $payload);
    }

    public function test_a_signed_in_user_can_comment_and_it_waits_for_approval(): void
    {
        $this->signInAs($this->member);

        $response = $this->submitComment('a-post', ['body' => 'Great episode!', 'is_spoiler' => true])->assertCreated();

        $this->assertApiShape('CommentCreatedResponse', $response->json(), 'created');
        $response
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.body', 'Great episode!')
            ->assertJsonPath('data.is_spoiler', true)
            ->assertJsonPath('data.parent_id', null);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $row = Comment::findOrFail($response->json('data.id'));
        $this->assertSame($this->member->id, $row->user_id);
        $this->assertSame($this->post->id, $row->post_id);
        $this->assertSame(CommentStatus::Pending, $row->status);

        $this->getJson('/api/v1/posts/a-post/comments')->assertOk()->assertJsonPath('data', []);
    }

    public function test_the_request_cannot_choose_the_author_or_the_status(): void
    {
        $other = $this->makeUser('Someone');
        $this->signInAs($this->member);

        $id = $this->submitComment('a-post', [
            'body' => 'Sneaky',
            'user_id' => $other->id,
            'status' => 'approved',
            'post_id' => 999,
        ])->assertCreated()->json('data.id');

        $row = Comment::findOrFail($id);
        $this->assertSame($this->member->id, $row->user_id);
        $this->assertSame($this->post->id, $row->post_id);
        $this->assertSame(CommentStatus::Pending, $row->status);
    }

    public function test_guests_cannot_comment(): void
    {
        $this->submitComment('a-post', ['body' => 'Hello there'])->assertUnauthorized();

        $this->assertSame(0, Comment::count());
    }

    public function test_banned_users_cannot_comment(): void
    {
        $this->signInAs($this->makeUser('Troll', ['banned_at' => now()]));

        $status = $this->submitComment('a-post', ['body' => 'Hello there'])->getStatusCode();

        $this->assertContains($status, [401, 403]);
        $this->assertSame(0, Comment::count());
    }

    public function test_the_text_is_cleaned_before_it_is_stored(): void
    {
        $this->signInAs($this->member);

        $id = $this->submitComment('a-post', ['body' => "  Hello\r\n\r\n\r\n\r\nworld \x07\u{200B}  "])->assertCreated()->json('data.id');

        $this->assertSame("Hello\n\nworld", Comment::findOrFail($id)->body);
    }

    public function test_bad_input_is_refused(): void
    {
        $this->signInAs($this->member);

        $bad = [
            [[], ''],
            [['body' => ''], ''],
            [['body' => 'a'], ''],
            [['body' => str_repeat('a', 2001)], ''],
            [['body' => ['x']], ''],
            [['body' => "\x07\u{200B}  "], ''],
            [['body' => 'fine text', 'parent_id' => 'abc'], ''],
            [['body' => 'fine text', 'parent_id' => 0], ''],
            [['body' => 'fine text', 'is_spoiler' => 'maybe'], ''],
            [['body' => 'see https://a.com https://b.com https://c.com'], ''],
            [['body' => 'fine text'], '?foo=1'],
        ];

        foreach ($bad as [$payload, $query]) {
            $this->submitComment('a-post', $payload, $query)->assertStatus(422);

            // Every attempt counts toward the limit, even a refused one, so let the minute pass.
            $this->travel(2)->minutes();
        }

        $this->assertSame(0, Comment::count());

        $this->submitComment('a-post', ['body' => str_repeat('a', 2000)])->assertCreated();
        $this->submitComment('a-post', ['body' => 'two links https://a.com and https://b.com'])->assertCreated();
    }

    public function test_replies_need_a_visible_top_level_parent_on_the_same_post(): void
    {
        
        $parent = $this->makeComment($this->post, 'parent');
        $reply = $this->makeComment($this->post, 'reply', ['parent_id' => $parent->id]);
        $waiting = $this->makeComment($this->post, 'waiting', ['status' => CommentStatus::Pending]);
        $removed = $this->makeComment($this->post, 'removed');
        $removed->delete();
        $elsewhere = $this->makeComment($this->makePost(['slug' => 'other-post']), 'elsewhere');
        
        $this->signInAs($this->member);

        $ok = $this->submitComment('a-post', ['body' => 'My reply', 'parent_id' => $parent->id])->assertCreated();
        $ok->assertJsonPath('data.parent_id', $parent->id);

        foreach ([$reply->id, $waiting->id, $removed->id, $elsewhere->id, 999999] as $parentId) {
            $this->submitComment('a-post', ['body' => "To {$parentId}", 'parent_id' => $parentId])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['parent_id']);

            // Refused attempts count toward the limit too, so let the minute pass.
            $this->travel(2)->minutes();
        }
    }

    public function test_the_same_text_twice_in_ten_minutes_is_refused(): void
    {
        $this->signInAs($this->member);

        $this->submitComment('a-post', ['body' => 'Same words'])->assertCreated();
        $this->submitComment('a-post', ['body' => 'Same words'])->assertStatus(422)->assertJsonValidationErrors(['body']);

        $this->travel(11)->minutes();

        $this->submitComment('a-post', ['body' => 'Same words'])->assertCreated();
    }

    public function test_posts_that_are_not_live_cannot_be_commented_on(): void
    {
        $this->makePost(['slug' => 'a-draft', 'status' => 'draft', 'published_at' => null]);
        $this->makePost(['slug' => 'gone'])->delete();
        
        $this->signInAs($this->member);


        foreach (['a-draft', 'gone', 'missing', 'Bad_Slug'] as $slug) {
            $this->submitComment($slug, ['body' => 'Hello there'])->assertStatus(404);
        }

        $this->assertSame(0, Comment::count());
    }

    public function test_a_user_is_rate_limited(): void
    {
        $this->signInAs($this->member);

        foreach (['one', 'two', 'three'] as $word) {
            $this->submitComment('a-post', ['body' => "Comment {$word}"])->assertCreated();
        }

        $this->submitComment('a-post', ['body' => 'Comment four'])->assertStatus(429);

        $this->assertSame(3, Comment::count());
    }

    public function test_only_lowercase_slugs_have_a_route(): void
    {
        $this->signInAs($this->member);

        $this->submitComment('A-Post', ['body' => 'Hello there'])->assertNotFound();
    }
}