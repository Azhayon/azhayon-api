<?php

namespace Tests\Feature\Content;

use App\Rules\ValidPostContent;
use App\Support\Content\ContentNormalizer;
use App\Support\Content\InvalidContentException;
use App\Support\Images\ImageProcessor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ContentNormalizerTest extends TestCase
{
    private ContentNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        $this->normalizer = app(ContentNormalizer::class);
    }

    private function doc(array ...$nodes): array
    {
        return ['type' => 'doc', 'content' => $nodes];
    }

    private function text(string $text, array $marks = []): array
    {
        $node = ['type' => 'text', 'text' => $text];

        if ($marks) {
            $node['marks'] = $marks;
        }

        return $node;
    }

    private function paragraph(array ...$inline): array
    {
        return ['type' => 'paragraph', 'attrs' => ['textAlign' => 'start'], 'content' => $inline];
    }

    private function block(string $id, array $config = []): array
    {
        return ['type' => 'customBlock', 'attrs' => ['id' => $id, 'config' => $config]];
    }

    private function storedImage(): string
    {
        $image = imagecreatetruecolor(1000, 500);
        ob_start();
        imagejpeg($image);

        return app(ImageProcessor::class)->store((string) ob_get_clean());
    }

    /** @return list<array<string, mixed>> */
    private function clean(array $doc): array
    {
        return $this->normalizer->normalize($doc)->document['content'];
    }

    private function errors(array $doc): string
    {
        try {
            $this->normalizer->normalize($doc);
        } catch (InvalidContentException $e) {
            return implode(' | ', $e->messages());
        }

        return '';
    }

    public function test_a_real_editor_document_is_cleaned(): void
    {
        $clean = $this->clean($this->doc(
            ['type' => 'heading', 'attrs' => ['level' => 2, 'textAlign' => 'center'], 'content' => [$this->text('Title')]],
            $this->paragraph(
                $this->text('Read '),
                $this->text('this', [
                    ['type' => 'link', 'attrs' => ['rel' => null, 'href' => 'https://example.com/a', 'class' => null, 'title' => null, 'target' => null]],
                    ['type' => 'bold'],
                ]),
            ),
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [$this->paragraph($this->text('one'))]]]],
            ['type' => 'horizontalRule'],
        ));

        $this->assertSame(
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Title']]],
            $clean[0],
        );
        $this->assertSame('paragraph', $clean[1]['type']);
        $this->assertArrayNotHasKey('attrs', $clean[1]);
        $this->assertSame([
            ['type' => 'text', 'text' => 'Read '],
            ['type' => 'text', 'text' => 'this', 'marks' => [['type' => 'bold'], ['type' => 'link', 'attrs' => ['href' => 'https://example.com/a']]]],
        ], $clean[1]['content']);
        $this->assertSame('bulletList', $clean[2]['type']);
        $this->assertSame(['type' => 'horizontalRule'], $clean[3]);
    }

    public function test_unsupported_marks_are_stripped(): void
    {
        $clean = $this->clean($this->doc($this->paragraph($this->text('x', [
            ['type' => 'underline'],
            ['type' => 'strike'],
            ['type' => 'textStyle', 'attrs' => ['color' => 'red']],
            ['type' => 'highlight'],
            ['type' => 'bold'],
        ]))));

        $this->assertSame([['type' => 'bold']], $clean[0]['content'][0]['marks']);
    }

    public function test_unsafe_links_keep_their_text_but_lose_the_link(): void
    {
        $unsafe = ['javascript:alert(1)', 'JaVaScRiPt:alert(1)', '//evil.com/x', 'data:text/html,hi', 'https://exa mple.com', 'ftp://example.com', '  ', 'https:///nohost'];

        foreach ($unsafe as $href) {
            $clean = $this->clean($this->doc($this->paragraph($this->text('x', [['type' => 'link', 'attrs' => ['href' => $href]]]))));

            $this->assertSame(['type' => 'text', 'text' => 'x'], $clean[0]['content'][0], $href);
        }
    }

    public function test_safe_links_are_kept(): void
    {
        foreach (['https://example.com/a?b=c#d', 'http://example.com', 'mailto:hi@example.com', '/blog/some-post', '#section'] as $href) {
            $clean = $this->clean($this->doc($this->paragraph($this->text('x', [['type' => 'link', 'attrs' => ['href' => $href]]]))));

            $this->assertSame([['type' => 'link', 'attrs' => ['href' => $href]]], $clean[0]['content'][0]['marks'], $href);
        }
    }

    public function test_headings_are_clamped_to_h2_through_h4(): void
    {
        $nodes = array_map(
            fn (int $level) => ['type' => 'heading', 'attrs' => ['level' => $level], 'content' => [$this->text('h')]],
            [1, 2, 3, 4, 5, 6],
        );

        $levels = array_map(fn ($node) => $node['attrs']['level'], $this->clean($this->doc(...$nodes)));

        $this->assertSame([2, 2, 3, 4, 4, 4], $levels);
    }

    public function test_pasted_images_are_rejected(): void
    {
        $errors = $this->errors($this->doc($this->paragraph([
            'type' => 'image',
            'attrs' => ['src' => 'https://example.com/a.jpg', 'alt' => '', 'width' => '300', 'height' => '169'],
        ])));

        $this->assertStringContainsString('Image block', $errors);
    }

    public function test_unsupported_nodes_are_rejected(): void
    {
        $errors = $this->errors($this->doc(
            ['type' => 'table', 'content' => []],
            ['type' => 'codeBlock', 'content' => [$this->text('x')]],
            ['type' => 'blockquote', 'content' => [['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [$this->text('x')]]]],
        ));

        $this->assertStringContainsString('table', $errors);
        $this->assertStringContainsString('codeBlock', $errors);
        $this->assertStringContainsString('heading', $errors);
    }

    public function test_empty_top_level_paragraphs_are_dropped(): void
    {
        $result = $this->normalizer->normalize($this->doc(
            ['type' => 'paragraph'],
            $this->paragraph($this->text('')),
            $this->paragraph($this->text('Hi')),
        ));

        $this->assertCount(1, $result->document['content']);
        $this->assertFalse($result->isEmpty);

        $this->assertTrue($this->normalizer->normalize($this->doc(['type' => 'paragraph']))->isEmpty);
        $this->assertTrue($this->normalizer->normalize(null)->isEmpty);
    }

    public function test_image_blocks_get_their_real_dimensions(): void
    {
        $path = $this->storedImage();

        $clean = $this->clean($this->doc($this->block('image', [
            'image' => $path,
            'alt' => '  A cover ',
            'caption' => '',
            'junk' => 'dropped',
        ])));

        $this->assertSame([
            'type' => 'customBlock',
            'attrs' => ['id' => 'image', 'config' => [
                'image' => $path,
                'width' => 1000,
                'height' => 500,
                'alt' => 'A cover',
                'caption' => null,
            ]],
        ], $clean[0]);
    }

    public function test_image_blocks_need_alt_text_and_a_real_image(): void
    {
        $this->assertStringContainsString('alt text', $this->errors($this->doc($this->block('image', ['image' => $this->storedImage()]))));
        $this->assertStringContainsString('invalid image', $this->errors($this->doc($this->block('image', ['image' => '../../.env', 'alt' => 'x']))));
    }

    public function test_youtube_blocks_store_only_the_id(): void
    {
        $clean = $this->clean($this->doc($this->block('youtube', [
            'video' => 'https://youtu.be/dQw4w9WgXcQ?si=x',
            'title' => '  ',
        ])));

        $this->assertSame(['video' => 'dQw4w9WgXcQ', 'title' => null], $clean[0]['attrs']['config']);
        $this->assertStringContainsString('YouTube', $this->errors($this->doc($this->block('youtube', ['video' => 'nope']))));
    }

    public function test_x_post_blocks_are_normalised(): void
    {
        $clean = $this->clean($this->doc($this->block('x-post', [
            'url' => 'https://twitter.com/jack/status/20?s=20',
            'text' => 'hi',
        ])));

        $this->assertSame(
            ['url' => 'https://x.com/jack/status/20', 'handle' => 'jack', 'id' => '20', 'text' => 'hi'],
            $clean[0]['attrs']['config'],
        );
        $this->assertStringContainsString('X post', $this->errors($this->doc($this->block('x-post', ['url' => 'https://example.com']))));
    }

    public function test_spoiler_blocks(): void
    {
        $clean = $this->clean($this->doc($this->block('spoiler', ['text' => 'secret'])));

        $this->assertSame(
            ['label' => 'Spoiler', 'text' => 'secret', 'image' => null, 'width' => null, 'height' => null, 'image_alt' => null],
            $clean[0]['attrs']['config'],
        );

        $this->assertStringContainsString('hidden text', $this->errors($this->doc($this->block('spoiler', []))));

        $path = $this->storedImage();
        $this->assertStringContainsString('alt text', $this->errors($this->doc($this->block('spoiler', ['text' => 's', 'image' => $path]))));

        $withImage = $this->clean($this->doc($this->block('spoiler', ['text' => 's', 'image' => $path, 'image_alt' => 'x'])));
        $this->assertSame(1000, $withImage[0]['attrs']['config']['width']);
    }

    public function test_unknown_blocks_are_rejected_and_the_ad_break_keeps_no_config(): void
    {
        $this->assertStringContainsString('Unknown block', $this->errors($this->doc($this->block('evil', ['x' => 1]))));

        $clean = $this->clean($this->doc($this->block('ad-break', ['x' => 1])));

        $this->assertSame([], $clean[0]['attrs']['config']);
    }

    public function test_size_limits(): void
    {
        $node = $this->paragraph($this->text('x'));

        for ($i = 0; $i < 8; $i++) {
            $node = ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [$node]]]];
        }

        $this->assertStringContainsString('too deeply', $this->errors($this->doc($node)));

        $many = array_fill(0, 3000, $this->paragraph($this->text('x')));
        $this->assertStringContainsString('too large', $this->errors($this->doc(...$many)));
    }

    public function test_reading_time_counts_words(): void
    {
        $words = fn (string $text) => $this->normalizer->normalize($this->doc($this->paragraph($this->text($text))));

        $this->assertSame(400, $words(implode(' ', array_fill(0, 400, 'word')))->wordCount);
        $this->assertSame(2, $words(implode(' ', array_fill(0, 400, 'word')))->readingTimeMinutes());
        $this->assertSame(3, $words(implode(' ', array_fill(0, 401, 'word')))->readingTimeMinutes());
        $this->assertSame(1, $words('one')->readingTimeMinutes());
        $this->assertSame(3, $words('আমি বাংলা লিখি')->wordCount);
        $this->assertSame(1, $this->normalizer->normalize(null)->readingTimeMinutes());
    }

    public function test_the_validation_rule(): void
    {
        $good = Validator::make(['content' => $this->doc($this->paragraph($this->text('ok')))], ['content' => [new ValidPostContent]]);
        $bad = Validator::make(['content' => $this->doc(['type' => 'table'])], ['content' => [new ValidPostContent]]);

        $this->assertTrue($good->passes());
        $this->assertTrue($bad->fails());
    }
}