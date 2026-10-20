<?php

namespace App\Support\Links;

use App\Models\LinkCheck;
use App\Models\Post;
use App\Support\Seo\PostAuditor;

class LinkRegistry
{
    public function __construct(private readonly PostAuditor $auditor) {}

    /**
     * The address we would actually check for a link, or null when it is not an
     * outside web link (our own site, mailto:, anchors, credentials, too long).
     */
    public static function normalize(string $href): ?string
    {
        $href = trim($href);

        if ($href === '' || strlen($href) > 2048) {
            return null;
        }

        $parts = parse_url($href);

        if (! is_array($parts)
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass'])
            || OwnSite::isOwnHost($parts['host'])) {
            return null;
        }

        // The fragment (#section) never changes what the server returns.
        return strtolower($parts['scheme']).'://'.strtolower($parts['host'])
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '')
            .(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /**
     * @param  array<string, mixed>|null  $document
     * @return list<string>
     */
    public function externalLinks(?array $document): array
    {
        $urls = [];

        foreach ($this->auditor->links($document) as $href) {
            $url = self::normalize($href);

            if ($url !== null) {
                $urls[$url] = true;
            }
        }

        return array_keys($urls);
    }

    /** Makes sure every outside link in the post is on the checking list. */
    public function register(Post $post): int
    {
        $urls = $this->externalLinks(is_array($post->content) ? $post->content : null);

        if ($urls === []) {
            return 0;
        }

        $now = now();

        LinkCheck::upsert(
            array_map(fn (string $url): array => [
                'url_hash' => LinkCheck::hash($url),
                'url' => $url,
                'status' => LinkCheck::PENDING,
                'next_check_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $urls),
            ['url_hash'],
            ['last_seen_at', 'updated_at'], // an existing row keeps its results
        );

        return count($urls);
    }

    /**
     * Rescans every published post and drops links that no longer appear anywhere.
     *
     * @return array{links: int, pruned: int}
     */
    public function reindexAll(): array
    {
        $started = now();
        $links = 0;

        Post::published()
            ->select(['id', 'content'])
            ->chunkById(100, function ($posts) use (&$links): void {
                foreach ($posts as $post) {
                    $links += $this->register($post);
                }
            });

        $pruned = LinkCheck::where('last_seen_at', '<', $started)->delete();

        return ['links' => $links, 'pruned' => $pruned];
    }
}