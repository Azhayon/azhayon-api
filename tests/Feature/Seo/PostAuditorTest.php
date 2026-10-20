<?php

namespace Tests\Feature\Seo;

use App\Models\Category;
use App\Models\Post;
use App\Models\Redirect;
use App\Models\User;
use App\Models\LinkCheck;
use App\Support\Seo\PostAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostAuditorTest extends TestCase
{
    use RefreshDatabase;

    private PostAuditor $auditor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditor = app(PostAuditor::class);
    }

    /** A post with nothing wrong, built in memory (not saved). */
    private function made(array $attributes = [], int $tags = 1): Post
    {
        $post = Post::make(array_merge([
            'title' => 'A perfectly sized title',
            'excerpt' => str_repeat('a', 100),
            'featured_image' => '2026/10/abcdefghijkl/1200.webp',
        ], $attributes));

        $post->setAttribute('tags_count', $tags);

        return $post;
    }

    /** @param  list<string>  $hrefs */
    private function doc(array $hrefs): array
    {
        return [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => array_map(fn (string $href) => [
                    'type' => 'text',
                    'text' => 'link',
                    'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]],
                ], $hrefs),
            ]],
        ];
    }

    private function publishedPost(string $slug, array $extra = []): Post
    {
        $author = User::factory()->create(['role' => 'admin', 'provider_name' => null, 'provider_id' => null]);
        $category = Category::firstOrCreate(['slug' => 'anime'], ['name' => 'Anime']);

        return Post::factory()->create(array_merge([
            'author_id' => $author->id,
            'category_id' => $category->id,
            'slug' => $slug,
        ], $extra));
    }

    public function test_a_complete_post_has_no_issues(): void
    {
        $this->assertSame([], $this->auditor->messages($this->made(tags: 2)));
    }

    public function test_missing_basics_are_reported(): void
    {
        $messages = $this->auditor->messages($this->made(['excerpt' => null, 'featured_image' => null], tags: 0));

        $this->assertContains('No SEO description or excerpt.', $messages);
        $this->assertContains('No featured image.', $messages);
        $this->assertContains('No tags.', $messages);
    }

    public function test_the_excerpt_counts_as_a_description(): void
    {
        $messages = $this->auditor->messages($this->made(['meta_description' => '', 'excerpt' => str_repeat('b', 120)]));

        $this->assertSame([], $messages);
    }

    public function test_lengths_are_flagged(): void
    {
        $title = implode(' ', $this->auditor->messages($this->made(['meta_title' => str_repeat('t', 70)])));
        $long = implode(' ', $this->auditor->messages($this->made(['meta_description' => str_repeat('d', 200)])));
        $short = implode(' ', $this->auditor->messages($this->made(['meta_description' => str_repeat('d', 30)])));

        $this->assertStringContainsString('Title is 70 characters', $title);
        $this->assertStringContainsString('Description is 200 characters', $long);
        $this->assertStringContainsString('Description is short (30 characters)', $short);
    }

    public function test_noindex_is_reported(): void
    {
        $this->assertContains('Hidden from search engines (noindex).', $this->auditor->messages($this->made(['noindex' => true])));
    }

    public function test_links_are_found_in_nested_content(): void
    {
        $document = [
            'type' => 'doc',
            'content' => [
                ['type' => 'blockquote', 'content' => [[
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'a', 'marks' => [['type' => 'link', 'attrs' => ['href' => '/blog/one']]]]],
                ]]],
                ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [[
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'b', 'marks' => [['type' => 'bold'], ['type' => 'link', 'attrs' => ['href' => 'https://example.com']]]],
                        ['type' => 'text', 'text' => 'c', 'marks' => [['type' => 'link', 'attrs' => ['href' => '/blog/one']]]],
                    ],
                ]]]]],
            ],
        ];

        $this->assertSame(['/blog/one', 'https://example.com'], $this->auditor->links($document));
        $this->assertSame([], $this->auditor->links(null));
    }

    public function test_internal_links_are_checked(): void
    {
        $this->publishedPost('live-one');
        $this->publishedPost('draft-one', ['status' => 'draft', 'published_at' => null]);
        Redirect::create(['from_path' => '/blog/old-one', 'to_path' => '/blog/live-one', 'status_code' => 301]);

        $post = $this->made(['content' => $this->doc([
            '/blog/live-one',
            '/blog/live-one?utm=x#top',
            '/blog/draft-one',
            '/blog/missing',
            '/blog/old-one/',
            'http://localhost:3000/blog/missing-two',
            'https://example.com/blog/ignored',
            'mailto:hi@example.com',
            '#top',
            '/category/anime',
        ])]);

        $messages = $this->auditor->messages($post);

        $this->assertSame([
            'Broken link: /blog/draft-one does not match a published post.',
            'Broken link: /blog/missing does not match a published post.',
            'Link /blog/old-one redirects to /blog/live-one; update it to the new address.',
            'Broken link: /blog/missing-two does not match a published post.',
        ], $messages);
    }

    private function linkCheck(string $url, array $attributes): void
    {
        (new LinkCheck)->forceFill(array_merge([
            'url_hash' => LinkCheck::hash($url),
            'url' => $url,
        ], $attributes))->save();
    }

    public function test_outside_link_problems_come_from_the_link_checks(): void
    {
        $this->linkCheck('https://example.com/dead', ['status' => 'broken', 'http_status' => 404, 'failures' => 2]);
        $this->linkCheck('https://example.com/new-home', ['status' => 'moved', 'final_url' => 'https://example.com/home']);
        $this->linkCheck('https://example.com/flaky', ['status' => 'failing', 'failures' => 1]);
        $this->linkCheck('https://example.com/fine', ['status' => 'ok']);

        $post = $this->made(['content' => $this->doc([
            'https://example.com/dead',
            'https://example.com/new-home#section',
            'https://example.com/flaky',
            'https://example.com/fine',
            'https://example.com/never-checked',
        ])]);

        $this->assertSame([
            'External link is broken: https://example.com/dead (HTTP 404).',
            'External link moved: https://example.com/new-home now redirects to https://example.com/home; consider updating it.',
        ], $this->auditor->messages($post));
    }
}