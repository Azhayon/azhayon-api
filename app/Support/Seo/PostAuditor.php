<?php

namespace App\Support\Seo;

use App\Models\Post;
use App\Models\Redirect;
use App\Models\LinkCheck;
use App\Support\Links\LinkRegistry;
use App\Support\Links\OwnSite;

class PostAuditor
{
    private const MAX_LINK_MESSAGES = 5;

    /** @return list<string> */
    public function messages(Post $post): array
    {
        $messages = [];

        $description = trim((string) ($post->meta_description ?: $post->excerpt));
        $length = mb_strlen($description);

        if ($description === '') {
            $messages[] = 'No SEO description or excerpt.';
        } elseif ($length > 160) {
            $messages[] = "Description is {$length} characters; search results show about 160.";
        } elseif ($length < 70) {
            $messages[] = "Description is short ({$length} characters); aim for 70 to 160.";
        }

        $title = trim((string) ($post->meta_title ?: $post->title));
        $titleLength = mb_strlen($title);

        if ($titleLength > 60) {
            $messages[] = "Title is {$titleLength} characters; around 60 fits in search results.";
        }

        if (blank($post->featured_image)) {
            $messages[] = 'No featured image.';
        }

        if ($this->tagCount($post) === 0) {
            $messages[] = 'No tags.';
        }

        if ($post->noindex) {
            $messages[] = 'Hidden from search engines (noindex).';
        }

        return [...$messages, ...$this->linkMessages($post), ...$this->externalLinkMessages($post)];
    }

    /**
     * Every link target in a document, in order, without duplicates.
     *
     * @param  array<string, mixed>|null  $document
     * @return list<string>
     */
    public function links(?array $document): array
    {
        $found = [];

        $walk = function (array $node) use (&$walk, &$found): void {
            foreach ($node['marks'] ?? [] as $mark) {
                if (($mark['type'] ?? null) === 'link' && is_string($mark['attrs']['href'] ?? null)) {
                    $found[] = $mark['attrs']['href'];
                }
            }

            foreach ($node['content'] ?? [] as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };

        if (is_array($document)) {
            $walk($document);
        }

        return array_values(array_unique($found));
    }

    private function tagCount(Post $post): int
    {
        // withCount() is used by the admin list; fall back to a query elsewhere.
        $attributes = $post->getAttributes();

        return array_key_exists('tags_count', $attributes)
            ? (int) $attributes['tags_count']
            : $post->tags()->count();
    }

    /** @return list<string> */
    private function linkMessages(Post $post): array
    {
        $prefix = rtrim((string) config('azhayon.paths.post'), '/').'/';
        $paths = [];

        foreach ($this->links(is_array($post->content) ? $post->content : null) as $href) {
            $relative = OwnSite::relative($href);

            if ($relative === null) {
                continue; // external, mailto and anchors are not checked here
            }

            $path = Redirect::normalizePath((string) strtok($relative, '?#'));
            $slug = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : '';

            if ($slug !== '' && ! str_contains($slug, '/')) {
                $paths[$path] = $slug;
            }
        }

        if ($paths === []) {
            return [];
        }

        $live = Post::published()->whereIn('slug', array_values($paths))->pluck('slug')->all();
        $messages = [];

        foreach ($paths as $path => $slug) {
            if (in_array($slug, $live, true)) {
                continue;
            }

            $redirect = Redirect::where('from_path', $path)->first();

            $messages[] = $redirect
                ? "Link {$path} redirects to {$redirect->to_path}; update it to the new address."
                : "Broken link: {$path} does not match a published post.";
        }

        if (count($messages) > self::MAX_LINK_MESSAGES) {
            $extra = count($messages) - self::MAX_LINK_MESSAGES;
            $messages = array_slice($messages, 0, self::MAX_LINK_MESSAGES);
            $messages[] = "…and {$extra} more link problems.";
        }

        return $messages;
    }

    /** @return list<string> */
    private function externalLinkMessages(Post $post): array
    {
        $hashes = [];

        foreach ($this->links(is_array($post->content) ? $post->content : null) as $href) {
            $url = LinkRegistry::normalize($href);

            if ($url !== null) {
                $hashes[LinkCheck::hash($url)] = true;
            }
        }

        if ($hashes === []) {
            return [];
        }

        $messages = [];

        $checks = LinkCheck::whereIn('url_hash', array_keys($hashes))
            ->whereIn('status', [LinkCheck::BROKEN, LinkCheck::MOVED])
            ->orderBy('url')
            ->get();

        foreach ($checks as $check) {
            $messages[] = $check->status === LinkCheck::BROKEN
                ? 'External link is broken: '.$check->url.' ('
                    .($check->http_status ? 'HTTP '.$check->http_status : ($check->note ?? 'no response')).').'
                : 'External link moved: '.$check->url.' now redirects to '.$check->final_url.'; consider updating it.';
        }

        if (count($messages) > self::MAX_LINK_MESSAGES) {
            $extra = count($messages) - self::MAX_LINK_MESSAGES;
            $messages = array_slice($messages, 0, self::MAX_LINK_MESSAGES);
            $messages[] = "…and {$extra} more outside link problems.";
        }

        return $messages;
    }
}