<?php

namespace Tests\Feature\Links;

use App\Models\Category;
use App\Models\LinkCheck;
use App\Models\Post;
use App\Models\User;
use App\Support\Links\LinkRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkRegistryTest extends TestCase
{
    use RefreshDatabase;

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

    private function postWith(array $hrefs, array $extra = []): Post
    {
        $author = User::factory()->create(['role' => 'admin', 'provider_name' => null, 'provider_id' => null]);
        $category = Category::firstOrCreate(['slug' => 'anime'], ['name' => 'Anime']);

        return Post::factory()->create(array_merge([
            'author_id' => $author->id,
            'category_id' => $category->id,
            'content' => $this->doc($hrefs),
        ], $extra));
    }

    public function test_urls_are_normalised_and_non_web_links_are_ignored(): void
    {
        $this->assertSame('https://example.com/Path?q=1', LinkRegistry::normalize('https://Example.com/Path?q=1#frag'));
        $this->assertSame('http://example.com', LinkRegistry::normalize(' http://example.com '));
        $this->assertSame('https://example.com:8443/a', LinkRegistry::normalize('https://example.com:8443/a'));

        $ignored = [
            '',
            '/blog/x',
            '#top',
            'mailto:a@b.test',
            'javascript:alert(1)',
            'ftp://example.com/file',
            'https://user:pw@example.com/',
            'http://localhost:3000/blog/x', // our own site
            'https://localhost/blog',
            'https://example.com/'.str_repeat('a', 2100),
        ];

        foreach ($ignored as $href) {
            $this->assertNull(LinkRegistry::normalize($href), $href);
        }
    }

    public function test_registering_and_reindexing(): void
    {
        $post = $this->postWith(['https://example.com/a', 'https://example.com/b#x', '/blog/local', 'https://example.com/a']);

        // Publishing registered the two distinct outside links.
        $this->assertSame(
            ['https://example.com/a', 'https://example.com/b'],
            LinkCheck::orderBy('url')->pluck('url')->all(),
        );

        // Pretend the link results already exist, then remove one link behind the observer's back.
        LinkCheck::where('url', 'https://example.com/a')->update(['status' => 'ok']);
        $this->travel(1)->minute();
        Post::query()->whereKey($post->id)->update(['content' => json_encode($this->doc(['https://example.com/a']))]);

        $result = app(LinkRegistry::class)->reindexAll();

        $this->assertSame(['links' => 1, 'pruned' => 1], $result);
        $this->assertSame(['https://example.com/a'], LinkCheck::pluck('url')->all());
        $this->assertSame('ok', LinkCheck::first()->status); // existing results are kept
    }

    public function test_only_live_posts_register_their_links(): void
    {
        $draft = $this->postWith(['https://example.com/draft-link'], ['status' => 'draft', 'published_at' => null]);

        $this->assertSame(0, LinkCheck::count());

        $draft->update(['status' => 'published']);

        $this->assertSame(['https://example.com/draft-link'], LinkCheck::pluck('url')->all());
    }
}