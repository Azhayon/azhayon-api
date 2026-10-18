<?php

namespace App\Support\Content;

use App\Support\Embeds\XPost;
use App\Support\Embeds\YouTube;
use App\Support\Images\ImageProcessor;
use App\Support\Images\InvalidImageException;

/**
 * Checks an editor document against the allowlist and rebuilds it clean.
 * Unsupported marks are stripped; unsupported structure is an error.
 *
 * Not re-entrant: it keeps per-call state, so resolve a fresh instance per use.
 */
class ContentNormalizer
{
    private const MAX_DEPTH = 12;

    private const MAX_NODES = 5000;

    private const MAX_BYTES = 1_000_000;

    /** Which node types may appear directly inside each parent type. */
    private const CHILDREN = [
        'doc' => ['paragraph', 'heading', 'bulletList', 'orderedList', 'blockquote', 'horizontalRule', 'customBlock'],
        'blockquote' => ['paragraph', 'bulletList', 'orderedList'],
        'bulletList' => ['listItem'],
        'orderedList' => ['listItem'],
        'listItem' => ['paragraph', 'bulletList', 'orderedList'],
        'paragraph' => ['text', 'hardBreak'],
        'heading' => ['text', 'hardBreak'],
    ];

    private const MARK_ORDER = ['bold', 'italic', 'link'];

    /** @var list<string> */
    private array $errors = [];

    private int $nodes = 0;

    private int $words = 0;

    private bool $hasContent = false;

    public function __construct(private readonly ImageProcessor $images) {}

    /** @throws InvalidContentException */
    public function normalize(mixed $document): NormalizedContent
    {
        $this->errors = [];
        $this->nodes = 0;
        $this->words = 0;
        $this->hasContent = false;

        if ($document === null || $document === []) {
            return new NormalizedContent(['type' => 'doc', 'content' => []], 0, true);
        }

        if (! is_array($document) || ($document['type'] ?? null) !== 'doc') {
            throw new InvalidContentException(['The content is not a valid document.']);
        }

        if (strlen((string) json_encode($document)) > self::MAX_BYTES) {
            throw new InvalidContentException(['The content is too large.']);
        }

        $content = $this->children($document['content'] ?? [], 'doc', 1);

        if ($this->errors !== []) {
            throw new InvalidContentException(array_values(array_unique($this->errors)));
        }

        return new NormalizedContent(['type' => 'doc', 'content' => $content], $this->words, ! $this->hasContent);
    }

    /** @return list<array<string, mixed>> */
    private function children(mixed $nodes, string $parent, int $depth): array
    {
        if (! is_array($nodes)) {
            $this->errors[] = 'The content structure is invalid.';

            return [];
        }

        if ($depth > self::MAX_DEPTH) {
            $this->errors[] = 'The content is nested too deeply.';

            return [];
        }

        $clean = [];

        foreach ($nodes as $node) {
            if (++$this->nodes > self::MAX_NODES) {
                $this->errors[] = 'The content is too large.';
                break;
            }

            if (! is_array($node) || ! is_string($node['type'] ?? null)) {
                $this->errors[] = 'The content structure is invalid.';

                continue;
            }

            $type = $node['type'];

            if ($type === 'image') {
                $this->errors[] = 'Images pasted into the text are not allowed. Remove them and use the Image block instead.';

                continue;
            }

            if (! in_array($type, self::CHILDREN[$parent] ?? [], true)) {
                $this->errors[] = "Content of type “{$type}” is not supported here.";

                continue;
            }

            $result = $this->node($type, $node, $parent, $depth);

            if ($result !== null) {
                $clean[] = $result;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function node(string $type, array $node, string $parent, int $depth): ?array
    {
        switch ($type) {
            case 'text':
                return $this->textNode($node);

            case 'hardBreak':
                return ['type' => 'hardBreak'];

            case 'horizontalRule':
                return ['type' => 'horizontalRule'];

            case 'paragraph':
                $content = $this->children($node['content'] ?? [], 'paragraph', $depth + 1);

                // Empty paragraphs are only meaningful inside lists and quotes.
                if ($content === [] && $parent === 'doc') {
                    return null;
                }

                return $content === [] ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => $content];

            case 'heading':
                $level = max(2, min(4, (int) ($node['attrs']['level'] ?? 2)));
                $content = $this->children($node['content'] ?? [], 'heading', $depth + 1);

                if ($content === []) {
                    return null;
                }

                return ['type' => 'heading', 'attrs' => ['level' => $level], 'content' => $content];

            case 'bulletList':
            case 'orderedList':
                $items = $this->children($node['content'] ?? [], $type, $depth + 1);

                if ($items === []) {
                    return null;
                }

                $list = ['type' => $type];
                $start = (int) ($node['attrs']['start'] ?? 1);

                if ($type === 'orderedList' && $start > 1 && $start <= 9999) {
                    $list['attrs'] = ['start' => $start];
                }

                $list['content'] = $items;

                return $list;

            case 'listItem':
                $content = $this->children($node['content'] ?? [], 'listItem', $depth + 1);

                return ['type' => 'listItem', 'content' => $content === [] ? [['type' => 'paragraph']] : $content];

            case 'blockquote':
                $content = $this->children($node['content'] ?? [], 'blockquote', $depth + 1);

                return $content === [] ? null : ['type' => 'blockquote', 'content' => $content];

            case 'customBlock':
                return $this->customBlock($node);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function textNode(array $node): ?array
    {
        $text = $this->cleanText($node['text'] ?? '');

        if ($text === null) {
            $this->errors[] = 'The content contains text that is not valid UTF-8.';

            return null;
        }

        if ($text === '') {
            return null;
        }

        $this->words += $this->countWords($text);
        $this->hasContent = true;

        $clean = ['type' => 'text', 'text' => $text];
        $marks = $this->marks($node['marks'] ?? []);

        if ($marks !== []) {
            $clean['marks'] = $marks;
        }

        return $clean;
    }

    /** @return list<array<string, mixed>> */
    private function marks(mixed $marks): array
    {
        if (! is_array($marks)) {
            return [];
        }

        $kept = [];

        foreach ($marks as $mark) {
            $type = is_array($mark) ? ($mark['type'] ?? null) : null;

            if ($type === 'bold' || $type === 'italic') {
                $kept[$type] = ['type' => $type];
            } elseif ($type === 'link') {
                $href = $this->safeHref($mark['attrs']['href'] ?? null);

                if ($href !== null) {
                    $kept['link'] = ['type' => 'link', 'attrs' => ['href' => $href]];
                }
            }
        }

        $ordered = [];

        foreach (self::MARK_ORDER as $type) {
            if (isset($kept[$type])) {
                $ordered[] = $kept[$type];
            }
        }

        return $ordered;
    }

    private function safeHref(mixed $href): ?string
    {
        if (! is_string($href)) {
            return null;
        }

        $href = trim($href);

        if ($href === '' || strlen($href) > 2048 || preg_match('/[\x00-\x20\x7F]/', $href)) {
            return null;
        }

        if (str_starts_with($href, '//')) {
            return null;
        }

        if (str_starts_with($href, '/') || str_starts_with($href, '#')) {
            return $href;
        }

        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https', 'mailto'], true)) {
            return null;
        }

        if ($scheme !== 'mailto' && ! parse_url($href, PHP_URL_HOST)) {
            return null;
        }

        return $href;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function customBlock(array $node): ?array
    {
        $id = $node['attrs']['id'] ?? null;
        $config = is_array($node['attrs']['config'] ?? null) ? $node['attrs']['config'] : [];

        $clean = match ($id) {
            'image' => $this->imageBlock($config),
            'youtube' => $this->youtubeBlock($config),
            'x-post' => $this->xPostBlock($config),
            'spoiler' => $this->spoilerBlock($config),
            'ad-break' => [],
            default => $this->unknownBlock($id),
        };

        if ($clean === null) {
            return null;
        }

        if ($id !== 'ad-break') {
            $this->hasContent = true;
        }

        return ['type' => 'customBlock', 'attrs' => ['id' => $id, 'config' => $clean]];
    }

    /** @return null */
    private function unknownBlock(mixed $id): mixed
    {
        $this->errors[] = 'Unknown block “'.(is_string($id) ? $id : '?').'”.';

        return null;
    }

    /** @param  array<string, mixed>  $config */
    private function imageBlock(array $config): ?array
    {
        $alt = $this->text($config['alt'] ?? null, 255, 'Image alt text');
        $caption = $this->text($config['caption'] ?? null, 300, 'Image caption');
        $size = $this->imageSize($config['image'] ?? null, 'An image block');

        if ($alt === null) {
            $this->errors[] = 'Every image needs alt text.';
        }

        if ($alt === null || $size === null) {
            return null;
        }

        return [
            'image' => $config['image'],
            'width' => $size['width'],
            'height' => $size['height'],
            'alt' => $alt,
            'caption' => $caption,
        ];
    }

    /** @param  array<string, mixed>  $config */
    private function youtubeBlock(array $config): ?array
    {
        $video = YouTube::extractId((string) ($config['video'] ?? ''));
        $title = $this->text($config['title'] ?? null, 150, 'YouTube video title');

        if ($video === null) {
            $this->errors[] = 'A YouTube block has an invalid video link.';

            return null;
        }

        return ['video' => $video, 'title' => $title];
    }

    /** @param  array<string, mixed>  $config */
    private function xPostBlock(array $config): ?array
    {
        $post = XPost::parse((string) ($config['url'] ?? ''));
        $text = $this->text($config['text'] ?? null, 500, 'X post quote');

        if ($post === null) {
            $this->errors[] = 'An X post block has an invalid link.';

            return null;
        }

        return ['url' => $post['url'], 'handle' => $post['handle'], 'id' => $post['id'], 'text' => $text];
    }

    /** @param  array<string, mixed>  $config */
    private function spoilerBlock(array $config): ?array
    {
        $label = $this->text($config['label'] ?? null, 60, 'Spoiler label') ?? 'Spoiler';
        $text = $this->text($config['text'] ?? null, 2000, 'Spoiler text');
        $alt = $this->text($config['image_alt'] ?? null, 255, 'Spoiler image alt text');
        $hasImage = filled($config['image'] ?? null);
        $size = $hasImage ? $this->imageSize($config['image'], 'A spoiler block') : null;

        if ($text === null) {
            $this->errors[] = 'A spoiler block needs hidden text.';
        }

        if ($hasImage && $alt === null) {
            $this->errors[] = 'A spoiler image needs alt text.';
        }

        if ($text === null || ($hasImage && ($size === null || $alt === null))) {
            return null;
        }

        $this->words += $this->countWords($text);

        return [
            'label' => $label,
            'text' => $text,
            'image' => $hasImage ? $config['image'] : null,
            'width' => $size['width'] ?? null,
            'height' => $size['height'] ?? null,
            'image_alt' => $hasImage ? $alt : null,
        ];
    }

    /** @return array{width: int, height: int}|null */
    private function imageSize(mixed $path, string $who): ?array
    {
        try {
            return $this->images->describe(is_string($path) ? $path : '');
        } catch (InvalidImageException) {
            $this->errors[] = "{$who} has a missing or invalid image.";

            return null;
        }
    }

    /** Trims; blank becomes null; over-long text is an error. */
    private function text(mixed $value, int $max, string $label): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $max) {
            $this->errors[] = "{$label} is too long (maximum {$max} characters).";

            return null;
        }

        return $value;
    }

    /** Removes control characters. Returns null when the text is not valid UTF-8. */
    private function cleanText(mixed $text): ?string
    {
        if (! is_string($text)) {
            return '';
        }

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    }

    private function countWords(string $text): int
    {
        return (int) preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\p{M}\'’\-]*/u', $text);
    }
}