<?php

namespace Tests\Feature\Images;

use App\Models\Series;
use App\Support\Images\ImageProcessor;
use App\Support\Images\InvalidImageException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ImageProcessorTest extends TestCase
{
    use RefreshDatabase;

    private ImageProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        $this->processor = app(ImageProcessor::class);
    }

    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 40, 90));

        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    private function gif(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);

        ob_start();
        imagegif($image);

        return (string) ob_get_clean();
    }

    /** @return list<string> */
    private function filesIn(string $path): array
    {
        return collect(Storage::disk('uploads')->allFiles(dirname($path)))
            ->map(fn ($file) => basename($file))
            ->sort()
            ->values()
            ->all();
    }

    public function test_large_images_get_four_webp_variants(): void
    {
        $path = $this->processor->store($this->jpeg(2400, 1200));

        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/[a-z0-9]{12}/1600\.webp$#', $path);
        $this->assertSame(['1200.webp', '1600.webp', '480.webp', '768.webp'], $this->filesIn($path));

        $this->assertSame(['width' => 1600, 'height' => 800], $this->processor->describe($path));

        $largest = getimagesizefromstring(Storage::disk('uploads')->get($path));
        $smallest = getimagesizefromstring(Storage::disk('uploads')->get(dirname($path).'/480.webp'));

        $this->assertSame(IMAGETYPE_WEBP, $largest[2]);
        $this->assertSame([480, 240], [$smallest[0], $smallest[1]]);
    }

    public function test_medium_images_only_get_widths_that_exist(): void
    {
        $path = $this->processor->store($this->jpeg(1000, 500));

        $this->assertStringEndsWith('/1000.webp', $path);
        $this->assertSame(['1000.webp', '480.webp', '768.webp'], $this->filesIn($path));
    }

    public function test_small_images_keep_a_single_variant(): void
    {
        $path = $this->processor->store($this->jpeg(400, 300));

        $this->assertStringEndsWith('/400.webp', $path);
        $this->assertSame(['400.webp'], $this->filesIn($path));
    }

    public function test_images_that_are_too_narrow_are_rejected(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('at least 320 pixels');

        $this->processor->store($this->jpeg(200, 200));
    }

    public function test_gif_files_are_rejected(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('JPEG, PNG or WebP');

        $this->processor->store($this->gif(600, 400));
    }

    public function test_non_images_are_rejected(): void
    {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('not a valid image');

        $this->processor->store('<?php echo "hello"; ?>');
    }

    public function test_oversized_files_are_rejected(): void
    {
        config(['azhayon.images.max_bytes' => 500]);

        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessage('too large');

        $this->processor->store($this->jpeg(600, 400));
    }

    public function test_huge_pixel_counts_are_rejected_before_any_file_is_written(): void
    {
        config(['azhayon.images.max_megapixels' => 1]);

        try {
            $this->processor->store($this->jpeg(1200, 1200));
            $this->fail('Expected the image to be rejected.');
        } catch (InvalidImageException $e) {
            $this->assertStringContainsString('too many pixels', $e->getMessage());
        }

        $this->assertSame([], Storage::disk('uploads')->allFiles());
    }

    public function test_variants_are_derived_from_the_stored_path(): void
    {
        $widths = fn (string $path) => array_column($this->processor->variants($path), 'width');

        $this->assertSame([1600, 1200, 768, 480], $widths('2026/10/abcdefghijkl/1600.webp'));
        $this->assertSame([1000, 768, 480], $widths('2026/10/abcdefghijkl/1000.webp'));
        $this->assertSame([400], $widths('2026/10/abcdefghijkl/400.webp'));

        $this->assertSame(
            '2026/10/abcdefghijkl/480.webp',
            $this->processor->smallestVariant('2026/10/abcdefghijkl/1600.webp'),
        );
    }

    public function test_describe_rejects_unsafe_or_lying_paths(): void
    {
        $bad = [
            '../../.env',
            'raw/abc.jpg',
            '2026/10/abcdefghijkl/../../x/1600.webp',
            '2026/10/ABCDEFGHIJKL/1600.webp',
            '2026/10/abcdefghijkl/1600.webp', // well-formed, but no such file
        ];

        foreach ($bad as $path) {
            try {
                $this->processor->describe($path);
                $this->fail("Accepted a bad path: {$path}");
            } catch (InvalidImageException) {
                $this->addToAssertionCount(1);
            }
        }

        // A real 1000px file stored under a name that claims 1600px.
        $real = $this->processor->store($this->jpeg(1000, 500));
        Storage::disk('uploads')->put('2026/10/abcdefghijkl/1600.webp', Storage::disk('uploads')->get($real));

        $this->expectException(InvalidImageException::class);
        $this->processor->describe('2026/10/abcdefghijkl/1600.webp');
    }

    public function test_series_saves_cover_dimensions_and_a_thumbnail(): void
    {
        $path = $this->processor->store($this->jpeg(2400, 1200));

        $series = Series::create(['title' => 'Solo Leveling', 'slug' => 'solo-leveling', 'cover_image' => $path]);
        $series->refresh();

        $this->assertSame(1600, $series->cover_width);
        $this->assertSame(800, $series->cover_height);
        $this->assertSame(dirname($path).'/480.webp', $series->cover_thumb);
    }

    public function test_clearing_the_cover_clears_the_dimensions(): void
    {
        $path = $this->processor->store($this->jpeg(1000, 500));
        $series = Series::create(['title' => 'A', 'slug' => 'a', 'cover_image' => $path]);

        $series->update(['cover_image' => null]);
        $series->refresh();

        $this->assertNull($series->cover_image);
        $this->assertNull($series->cover_width);
        $this->assertNull($series->cover_height);
        $this->assertNull($series->cover_thumb);
    }

    public function test_series_rejects_arbitrary_cover_paths(): void
    {
        $this->expectException(ValidationException::class);

        Series::create(['title' => 'B', 'slug' => 'b', 'cover_image' => '../../.env']);
    }
}