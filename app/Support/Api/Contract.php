<?php

namespace App\Support\Api;

use InvalidArgumentException;

/**
 * The public API's response shapes, written once. Contract tests check live
 * responses against them, and `php artisan api:types` turns them into the
 * TypeScript types the frontend uses, so the two cannot drift apart.
 *
 * Node forms:
 *   'string' | 'int' | 'number' | 'bool' | 'null' | 'any'   a scalar ('number' accepts int or float)
 *   'enum:a,b,c'                                            one of these strings
 *   'literal:true' | 'literal:false'                        exactly that boolean
 *   '@Name'                                                 another definition
 *   '?X' and 'X|Y'                                          nullable, and a union of alternatives
 *   ['*' => node]                                           a list of node
 *   ['**' => node]                                          a map from string keys to node
 *   ['key' => node, ...]                                    an object with EXACTLY these keys
 */
final class Contract
{
    private const POST_TYPES = 'enum:news,review,explainer,list';

    private const TAG_TYPES = 'enum:general,genre,studio';

    /** @var array<string, mixed>|null */
    private static ?array $definitions = null;

    /** @return array<string, mixed> */
    public static function definitions(): array
    {
        return self::$definitions ??= self::build();
    }

    /** @return list<string> Human-readable problems; empty means the data matches. */
    public static function validate(string $definition, mixed $data): array
    {
        $errors = [];

        self::check('@'.$definition, $data, '$', $errors);

        return $errors;
    }

    /** @return array<string, mixed> */
    private static function build(): array
    {
        $pageMeta = ['page' => 'int', 'per_page' => 'int', 'total' => 'int', 'last_page' => 'int'];

        $postCard = [
            'slug' => 'string',
            'path' => 'string',
            'title' => 'string',
            'subtitle' => '?string',
            'excerpt' => '?string',
            'type' => self::POST_TYPES,
            'subject' => '?string',
            'category' => '@CategoryRef',
            'image' => '?@ApiImage',
            'published_at' => 'string',
            'updated_at' => 'string',
            'reading_time_minutes' => 'int',
            'rating' => '@PostRating',
            'review_score' => '?number',
        ];

        $category = [
            'name' => 'string',
            'slug' => 'string',
            'path' => 'string',
            'description' => '?string',
            'posts_count' => 'int',
        ];

        $tag = [
            'name' => 'string',
            'slug' => 'string',
            'type' => self::TAG_TYPES,
            'path' => 'string',
            'posts_count' => 'int',
        ];

        $series = [
            'title' => 'string',
            'slug' => 'string',
            'path' => 'string',
            'description' => '?string',
            'image' => '?@ApiImage',
            'posts_count' => 'int',
            'latest_post_at' => '?string',
        ];

        $seoBasics = ['title' => 'string', 'description' => '?string', 'canonical_url' => 'string', 'noindex' => 'bool'];

        return [
            // Building blocks
            'ApiImageVariant' => ['width' => 'int', 'height' => 'int', 'url' => 'string'],
            'ApiImage' => [
                'url' => 'string',
                'width' => 'int',
                'height' => 'int',
                'alt' => '?string',
                'variants' => ['*' => '@ApiImageVariant'],
            ],
            'PageMeta' => $pageMeta,
            'SearchMeta' => $pageMeta + ['query' => 'string'],
            'CategoryRef' => ['name' => 'string', 'slug' => 'string'],
            'PostRating' => ['average' => 'number', 'count' => 'int'],

            // Posts
            'PostCard' => $postCard,
            'PostTag' => ['name' => 'string', 'slug' => 'string', 'type' => self::TAG_TYPES],
            'PostLink' => ['title' => 'string', 'slug' => 'string', 'path' => 'string'],
            'PostSeries' => [
                'title' => 'string',
                'slug' => 'string',
                'path' => 'string',
                'total' => 'int',
                'previous' => '?@PostLink',
                'next' => '?@PostLink',
            ],
            'PostSeo' => $seoBasics + ['image' => '?string'],
            'PostDetail' => $postCard + [
                'content' => '@Content',
                'author' => ['name' => 'string'],
                'tags' => ['*' => '@PostTag'],
                'series' => '?@PostSeries',
                'related' => ['*' => '@PostCard'],
                'seo' => '@PostSeo',
            ],

            // Post content (the node types themselves are described in the TypeScript preamble)
            'ContentNode' => 'any',
            'Content' => ['type' => 'enum:doc', 'content' => ['*' => '@ContentNode']],
            'ImageBlockConfig' => [
                'image' => 'string',
                'width' => 'int',
                'height' => 'int',
                'alt' => 'string',
                'caption' => '?string',
                'media' => '?@ApiImage',
            ],
            'YouTubeBlockConfig' => ['video' => 'string', 'title' => '?string'],
            'XPostBlockConfig' => ['url' => 'string', 'handle' => 'string', 'id' => 'string', 'text' => '?string'],
            'SpoilerBlockConfig' => [
                'label' => 'string',
                'text' => 'string',
                'image' => '?string',
                'width' => '?int',
                'height' => '?int',
                'image_alt' => '?string',
                'media' => '?@ApiImage',
            ],

            // Taxonomy
            'Category' => $category,
            'CategoryDetail' => $category + ['seo' => $seoBasics],
            'Tag' => $tag,
            'TagDetail' => $tag + ['seo' => $seoBasics],
            'Series' => $series,
            'SeriesDetail' => $series + ['seo' => $seoBasics + ['image' => '?string']],

            // Search, site, sitemap, redirects
            'Suggestion' => [
                'type' => 'enum:series,tag,subject,post',
                'label' => 'string',
                'path' => 'string',
                'tag_type' => '?'.self::TAG_TYPES,
            ],
            'AdPlacement' => ['enabled' => 'bool', 'slot' => '?string'],
            'AdsOff' => ['enabled' => 'literal:false'],
            'AdsOn' => [
                'enabled' => 'literal:true',
                'client' => 'string',
                'placements' => [
                    'sidebar' => '@AdPlacement',
                    'in_article' => '@AdPlacement',
                    'anchor' => '@AdPlacement',
                ],
                'rules' => ['in_article_every' => 'int', 'in_article_max' => 'int'],
            ],
            'Site' => [
                'name' => 'string',
                'tagline' => 'string',
                'url' => 'string',
                'ads' => '@AdsOff|@AdsOn',
                'categories' => ['*' => '@CategoryRef'],
            ],
            'SitemapSummary' => [
                'posts' => ['total' => 'int', 'per_page' => 'int', 'pages' => 'int'],
                'last_modified' => '?string',
            ],
            'SitemapPostEntry' => ['path' => 'string', 'lastmod' => '?string', 'image' => '?string'],
            'SitemapEntry' => ['path' => 'string', 'lastmod' => '?string'],
            'SitemapTaxonomy' => [
                'categories' => ['*' => '@SitemapEntry'],
                'tags' => ['*' => '@SitemapEntry'],
                'series' => ['*' => '@SitemapPostEntry'],
            ],
            'RedirectEntry' => ['from' => 'string', 'to' => 'string', 'status' => 'int'],

            // Whole responses
            'SiteResponse' => ['data' => '@Site'],
            'HomeResponse' => ['data' => [
                'trending' => ['*' => '@PostCard'],
                'latest' => ['*' => '@PostCard'],
                'popular' => ['*' => '@PostCard'],
            ]],
            'PostsResponse' => ['data' => ['*' => '@PostCard'], 'meta' => '@PageMeta'],
            'PostResponse' => ['data' => '@PostDetail'],
            'CategoriesResponse' => ['data' => ['*' => '@Category']],
            'CategoryResponse' => ['data' => '@CategoryDetail'],
            'TagsResponse' => ['data' => ['*' => '@Tag'], 'meta' => '@PageMeta'],
            'TagResponse' => ['data' => '@TagDetail'],
            'SeriesListResponse' => ['data' => ['*' => '@Series'], 'meta' => '@PageMeta'],
            'SeriesResponse' => ['data' => '@SeriesDetail'],
            'SearchResponse' => ['data' => ['*' => '@PostCard'], 'meta' => '@SearchMeta'],
            'SuggestResponse' => ['data' => ['*' => '@Suggestion']],
            'SitemapSummaryResponse' => ['data' => '@SitemapSummary'],
            'SitemapPostsResponse' => ['data' => ['*' => '@SitemapPostEntry'], 'meta' => ['page' => 'int', 'pages' => 'int']],
            'SitemapTaxonomyResponse' => ['data' => '@SitemapTaxonomy'],
            'RedirectsResponse' => ['data' => ['*' => '@RedirectEntry'], 'meta' => ['total' => 'int', 'truncated' => 'bool']],
            'HealthResponse' => ['status' => 'enum:ok,degraded', 'database' => 'enum:ok,error'],
            'ErrorResponse' => ['message' => 'string'],
            'ValidationErrorResponse' => ['message' => 'string', 'errors' => ['**' => ['*' => 'string']]],
            'PreviewResponse' => [
                'data' => '@PostDetail',
                'meta' => ['preview' => [
                    'status' => 'enum:draft,scheduled,published',
                    'expires_at' => 'string',
                ]],
            ],
        ];
    }

    /** @param  list<string>  $errors */
    private static function check(mixed $node, mixed $value, string $path, array &$errors): void
    {
        if (! is_array($node)) {
            self::checkString((string) $node, $value, $path, $errors);

            return;
        }

        $first = array_key_first($node);

        if ($first === '*') {
            if (! is_array($value) || ! array_is_list($value)) {
                $errors[] = "{$path}: expected a list, got ".self::describe($value);

                return;
            }

            foreach ($value as $index => $item) {
                self::check($node['*'], $item, "{$path}[{$index}]", $errors);
            }

            return;
        }

        if ($first === '**') {
            if (! is_array($value) || ($value !== [] && array_is_list($value))) {
                $errors[] = "{$path}: expected an object, got ".self::describe($value);

                return;
            }

            foreach ($value as $key => $item) {
                self::check($node['**'], $item, "{$path}.{$key}", $errors);
            }

            return;
        }

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $errors[] = "{$path}: expected an object, got ".self::describe($value);

            return;
        }

        foreach ($node as $key => $child) {
            if (! array_key_exists($key, $value)) {
                $errors[] = "{$path}.{$key}: missing";

                continue;
            }

            self::check($child, $value[$key], "{$path}.{$key}", $errors);
        }

        foreach (array_diff(array_keys($value), array_keys($node)) as $extra) {
            $errors[] = "{$path}.{$extra}: not in the contract";
        }
    }

    /** @param  list<string>  $errors */
    private static function checkString(string $node, mixed $value, string $path, array &$errors): void
    {
        $alternatives = array_map('trim', explode('|', ltrim($node, '?')));

        if (str_starts_with($node, '?')) {
            $alternatives[] = 'null';
        }

        $refErrors = [];

        foreach ($alternatives as $alternative) {
            if (str_starts_with($alternative, '@')) {
                $problems = [];
                self::check(self::definition(substr($alternative, 1)), $value, $path, $problems);

                if ($problems === []) {
                    return;
                }

                $refErrors[] = $problems;

                continue;
            }

            if (self::scalarMatches($alternative, $value)) {
                return;
            }
        }

        // An object failed to match: explain why, using the closest alternative.
        if (is_array($value) && $refErrors !== []) {
            usort($refErrors, fn (array $a, array $b): int => count($a) <=> count($b));
            array_push($errors, ...$refErrors[0]);

            return;
        }

        $errors[] = "{$path}: expected {$node}, got ".self::describe($value);
    }

    private static function scalarMatches(string $type, mixed $value): bool
    {
        return match (true) {
            $type === 'string' => is_string($value),
            $type === 'int' => is_int($value),
            $type === 'number' => is_int($value) || is_float($value),
            $type === 'bool' => is_bool($value),
            $type === 'null' => $value === null,
            $type === 'any' => true,
            $type === 'literal:true' => $value === true,
            $type === 'literal:false' => $value === false,
            str_starts_with($type, 'enum:') => is_string($value) && in_array($value, explode(',', substr($type, 5)), true),
            default => throw new InvalidArgumentException("Unknown contract type: {$type}"),
        };
    }

    private static function definition(string $name): mixed
    {
        return self::definitions()[$name] ?? throw new InvalidArgumentException("Unknown contract definition: {$name}");
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            is_array($value) => array_is_list($value) ? 'a list' : 'an object',
            default => get_debug_type($value),
        };
    }
}