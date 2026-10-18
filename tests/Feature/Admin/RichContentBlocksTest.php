<?php

namespace Tests\Feature\Admin;

use App\Filament\RichContent\Blocks\AdBreakBlock;
use App\Filament\RichContent\Blocks\ImageBlock;
use App\Filament\RichContent\Blocks\SpoilerBlock;
use App\Filament\RichContent\Blocks\XPostBlock;
use App\Filament\RichContent\Blocks\YouTubeBlock;
use App\Support\Images\ImageProcessor;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RichContentBlocksTest extends TestCase
{
    public function test_block_ids_are_unique_and_stable(): void
    {
        // These ids are stored inside saved posts, so they must never change.
        $this->assertSame(
            ['image', 'youtube', 'x-post', 'spoiler', 'ad-break'],
            [
                ImageBlock::getId(),
                YouTubeBlock::getId(),
                XPostBlock::getId(),
                SpoilerBlock::getId(),
                AdBreakBlock::getId(),
            ],
        );
    }

    public function test_previews_escape_user_input(): void
    {
        $evil = '<script>alert(1)</script>';

        $this->assertStringNotContainsString('<script>', SpoilerBlock::toPreviewHtml(['label' => $evil, 'text' => $evil]));
        $this->assertStringNotContainsString('<script>', XPostBlock::toPreviewHtml([
            'url' => 'https://x.com/jack/status/20',
            'text' => $evil,
        ]));
        $this->assertStringNotContainsString('<script>', YouTubeBlock::toPreviewHtml([
            'video' => 'dQw4w9WgXcQ',
            'title' => $evil,
        ]));
    }

    public function test_invalid_embeds_render_a_notice_instead_of_failing(): void
    {
        $this->assertStringContainsString('Invalid', YouTubeBlock::toPreviewHtml(['video' => 'nope']));
        $this->assertStringContainsString('Invalid', XPostBlock::toPreviewHtml(['url' => 'https://example.com']));
        $this->assertStringContainsString('unavailable', ImageBlock::toPreviewHtml(['image' => '../../.env']));
        $this->assertStringContainsString('unavailable', ImageBlock::toPreviewHtml([]));
    }

    public function test_the_image_preview_uses_the_smallest_variant_and_escapes_text(): void
    {
        Storage::fake('uploads');

        $image = imagecreatetruecolor(1000, 500);
        ob_start();
        imagejpeg($image);
        $path = app(ImageProcessor::class)->store((string) ob_get_clean());

        $html = ImageBlock::toPreviewHtml([
            'image' => $path,
            'alt' => 'A "quoted" <alt>',
            'caption' => '<i>c</i>',
        ]);

        $this->assertStringContainsString('480.webp', $html);
        $this->assertStringContainsString('alt="A &quot;quoted&quot; &lt;alt&gt;"', $html);
        $this->assertStringNotContainsString('<i>', $html);
    }

    public function test_the_ad_break_preview_renders(): void
    {
        $this->assertStringContainsString('Ad break', AdBreakBlock::toPreviewHtml([]));
    }
}