<?php

namespace Tests\Feature\Api;

use App\Support\Images\ImagePresenter;
use App\Support\Images\ImageProcessor;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImagePresenterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
    }

    private function storeImage(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);

        return app(ImageProcessor::class)->store((string) ob_get_clean());
    }

    public function test_variants_get_urls_and_heights_from_the_aspect_ratio(): void
    {
        $path = $this->storeImage(2400, 1200);
        $directory = dirname($path);

        $image = ImagePresenter::present($path, 1600, 800, 'A cover');

        $this->assertSame(1600, $image['width']);
        $this->assertSame(800, $image['height']);
        $this->assertSame('A cover', $image['alt']);
        $this->assertSame([1600, 1200, 768, 480], array_column($image['variants'], 'width'));
        $this->assertSame([800, 600, 384, 240], array_column($image['variants'], 'height'));
        $this->assertSame($image['url'], $image['variants'][0]['url']);

        foreach ($image['variants'] as $variant) {
            $this->assertStringEndsWith("/{$directory}/{$variant['width']}.webp", $variant['url']);
        }

        $this->assertSame($image['url'], ImagePresenter::url($path));
    }

    public function test_missing_or_invalid_images_present_as_null(): void
    {
        $path = $this->storeImage(800, 400);

        $this->assertNull(ImagePresenter::present(null, 800, 400));
        $this->assertNull(ImagePresenter::present('', 800, 400));
        $this->assertNull(ImagePresenter::present($path, null, null));
        $this->assertNull(ImagePresenter::present($path, 800, 0));
        $this->assertNull(ImagePresenter::present('../../.env', 800, 400));
        $this->assertNull(ImagePresenter::url('../../.env'));
        $this->assertNull(ImagePresenter::url(null));
    }
}