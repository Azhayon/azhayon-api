<?php

namespace Tests\Feature\Api;

use App\Enums\CommentStatus;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AssertsApiShape;
use Tests\TestCase;

class CommentsEndpointTest extends TestCase
{
    use AssertsApiShape;
    use RefreshDatabase;

    private User $author;

    private Category $category;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->create(['role' => 'admin', 'provider_name' => null, 'provider_id' => null]);
        $this->category = Category::create(['name' => 'Anime', 'slug' => 'anime']);
        $this->post = $this->makePost(['slug' => 'a-post']);
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

    private function makeUser(string $name = 'Ayon', array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'name' => $name,
            'banned_at' => null,
            'deleted_at' => null,
        ], $extra));
    }

    private function makeComment(string $body, array $extra = []): Comment
    {
        $userId = $extra['user_id'] ?? $this->makeUser()->id;

        return Comment::factory()->create(array_merge([
            'post_id' => $this->post->id,
            'user_id' => $userId,
            'body' => $body,
            'is_spoiler' => false,
            'status' => CommentStatus::Approved,
            'created_at' => now()->subHour(),
        ], $extra));
    }

    private function makeCommentsWithReplies(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $parent = $this->makeComment("c{$i}");
            $this->makeComment("r{$i}", ['parent_id' => $parent->id]);
        }
    }

    private function queriesFor(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson($url)->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_only_approved_comments_are_listed_newest_first_with_their_replies(): void
    {
        $older = $this->makeComment('older', ['created_at' => now()->subHours(3)]);
        $this->makeComment('newer', ['created_at' => now()->subHours(2)]);
        $this->makeComment('waiting', ['status' => CommentStatus::Pending]);
        $this->makeComment('junk', ['status' => CommentStatus::Spam]);
        $this->makeComment('removed')->delete();

        $this->makeComment('second reply', ['parent_id' => $older->id, 'created_at' => now()->subMinutes(30)]);
        $this->makeComment('first reply', ['parent_id' => $older->id, 'created_at' => now()->subMinutes(90)]);
        $this->makeComment('hidden reply', ['parent_id' => $older->id, 'status' => CommentStatus::Pending]);
        $this->makeComment('spam reply', ['parent_id' => $older->id, 'status' => CommentStatus::Spam]);
        $this->makeComment('deleted reply', ['parent_id' => $older->id])->delete();

        $response = $this->getJson('/api/v1/posts/a-post/comments')->assertOk();

        $this->assertApiShape('CommentsResponse', $response->json(), 'comments');

        $this->assertSame(['newer', 'older'], $response->json('data.*.body'));
        $this->assertSame([], $response->json('data.0.replies'));
        $this->assertSame(['first reply', 'second reply'], $response->json('data.1.replies.*.body'));

        $response->assertJsonPath('meta', ['page' => 1, 'per_page' => 20, 'total' => 2, 'last_page' => 1, 'comments_total' => 4]);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $response->json('data.0.created_at'));
    }

    public function test_replies_under_a_comment_that_is_not_public_stay_hidden_and_uncounted(): void
    {
        $waiting = $this->makeComment('waiting', ['status' => CommentStatus::Pending]);
        $this->makeComment('reply to waiting', ['parent_id' => $waiting->id]);

        $removed = $this->makeComment('removed parent');
        $this->makeComment('reply to removed', ['parent_id' => $removed->id]);
        $removed->delete();

        $response = $this->getJson('/api/v1/posts/a-post/comments')->assertOk();

        $this->assertSame([], $response->json('data'));
        $response->assertJsonPath('meta.comments_total', 0);
    }

    public function test_deleted_and_banned_authors_show_as_deleted_users(): void
    {
        $banned = $this->makeUser('Troll', ['banned_at' => now()]);
        $left = $this->makeUser('Leaver');
        $member = $this->makeUser('Ayon');

        $this->makeComment('from banned', ['user_id' => $banned->id, 'created_at' => now()->subHours(3)]);
        $this->makeComment('from leaver', ['user_id' => $left->id, 'created_at' => now()->subHours(2)]);
        $this->makeComment('from member', ['user_id' => $member->id, 'created_at' => now()->subHour()]);

        $left->delete();

        $response = $this->getJson('/api/v1/posts/a-post/comments')->assertOk();

        $this->assertSame(['Ayon', '[Deleted User]', '[Deleted User]'], $response->json('data.*.author'));
        $this->assertStringNotContainsString('Troll', (string) $response->getContent());
        $this->assertStringNotContainsString('Leaver', (string) $response->getContent());
    }

    public function test_comments_are_paged_by_top_level_comment(): void
    {
        $oldest = $this->makeComment('c3', ['created_at' => now()->subHours(3)]);
        $this->makeComment('c2', ['created_at' => now()->subHours(2)]);
        $this->makeComment('c1', ['created_at' => now()->subHour()]);
        $this->makeComment('reply', ['parent_id' => $oldest->id]);

        $first = $this->getJson('/api/v1/posts/a-post/comments?per_page=2')->assertOk();
        $this->assertSame(['c1', 'c2'], $first->json('data.*.body'));
        $first->assertJsonPath('meta', ['page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2, 'comments_total' => 4]);

        $second = $this->getJson('/api/v1/posts/a-post/comments?per_page=2&page=2')->assertOk();
        $this->assertSame(['c3'], $second->json('data.*.body'));
        $this->assertSame(['reply'], $second->json('data.0.replies.*.body'));

        $this->getJson('/api/v1/posts/a-post/comments?per_page=2&page=3')->assertStatus(404)->assertExactJson(['message' => 'Not found.']);
    }

    public function test_a_post_without_comments_has_an_empty_first_page(): void
    {
        $response = $this->getJson('/api/v1/posts/a-post/comments')->assertOk();

        $this->assertApiShape('CommentsResponse', $response->json(), 'empty');
        $this->assertSame([], $response->json('data'));
        $response->assertJsonPath('meta', ['page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1, 'comments_total' => 0]);

        $this->getJson('/api/v1/posts/a-post/comments?page=2')->assertStatus(404);
    }

    public function test_posts_that_are_not_live_have_no_comments_endpoint(): void
    {
        $this->makeComment('hello');
        $this->makePost(['slug' => 'a-draft', 'status' => 'draft', 'published_at' => null]);
        $this->makePost(['slug' => 'gone'])->delete();

        foreach (['a-draft', 'gone', 'missing', 'Bad_Slug'] as $slug) {
            $this->getJson("/api/v1/posts/{$slug}/comments")->assertStatus(404)->assertExactJson(['message' => 'Not found.']);
        }
    }

    public function test_bad_input_is_refused(): void
    {
        foreach (['page=0', 'page=501', 'per_page=0', 'per_page=51', 'foo=1'] as $query) {
            $this->getJson('/api/v1/posts/a-post/comments?'.$query)->assertStatus(422);
        }

        $this->getJson('/api/v1/posts/a-post/comments?per_page=50')->assertOk();
    }

    public function test_the_response_is_publicly_cacheable(): void
    {
        $response = $this->getJson('/api/v1/posts/a-post/comments')->assertOk();

        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertNotNull($response->headers->get('ETag'));
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_comments(): void
    {
        $this->makeCommentsWithReplies(2);
        $few = $this->queriesFor('/api/v1/posts/a-post/comments');

        $this->makeCommentsWithReplies(8);
        $many = $this->queriesFor('/api/v1/posts/a-post/comments');

        $this->assertSame($few, $many);
    }
}