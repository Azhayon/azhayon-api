<?php

namespace App\Support\Images;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Turns an uploaded image into WebP variants on the "uploads" disk.
 *
 * The stored reference is the path of the LARGEST variant, e.g.
 * "2026/10/k3m9x2qa7d1c/1600.webp". The other variants follow a fixed rule
 * (see widthsFor), so the whole srcset can be rebuilt from that one string.
 */
class ImageProcessor
{
    /** Directory is YYYY/MM/<12 random chars>; the file is "<width>.webp". */
    private const PATH_PATTERN = '#^\d{4}/\d{2}/[a-z0-9]{12}/(\d{2,5})\.webp$#';

    /** WebP cannot encode an image wider or taller than this. */
    private const WEBP_MAX_SIDE = 16383;

    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver());
    }

    /**
     * Validates, converts and stores an uploaded image (raw bytes).
     * Returns the path of the largest variant.
     *
     * @throws InvalidImageException
     */
    public function store(string $binary): string
    {
        $config = config('azhayon.images');

        if (strlen($binary) > $config['max_bytes']) {
            throw new InvalidImageException(
                'The image is too large (maximum '.round($config['max_bytes'] / 1048576, 1).' MB).'
            );
        }

        $info = @getimagesizefromstring($binary);

        if ($info === false) {
            throw new InvalidImageException('That file is not a valid image.');
        }

        [$width, $height, $type] = $info;

        if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new InvalidImageException('Only JPEG, PNG or WebP images are allowed.');
        }

        if ($width < $config['min_width']) {
            throw new InvalidImageException("The image must be at least {$config['min_width']} pixels wide.");
        }

        if (max($width, $height) > self::WEBP_MAX_SIDE) {
            throw new InvalidImageException('The image is too tall or wide to convert (maximum '.self::WEBP_MAX_SIDE.' pixels per side).');
        }

        if ($width * $height > $config['max_megapixels'] * 1_000_000) {
            throw new InvalidImageException("The image has too many pixels (maximum {$config['max_megapixels']} megapixels).");
        }

        try {
            $image = $this->manager->read($binary); // applies EXIF orientation
        } catch (Throwable $e) {
            throw new InvalidImageException('That file could not be read as an image.', 0, $e);
        }

        $largest = min($image->width(), max($config['widths']));
        $directory = now()->format('Y/m').'/'.Str::lower(Str::random(12));
        $disk = Storage::disk('uploads');

        try {
            // Work from the largest size down, resizing the same instance each time.
            foreach ($this->widthsFor($largest) as $variantWidth) {
                if ($image->width() > $variantWidth) {
                    $image->scaleDown(width: $variantWidth);
                }

                $disk->put("{$directory}/{$variantWidth}.webp", (string) $image->toWebp(quality: $config['quality']));
            }
        } catch (Throwable $e) {
            $disk->deleteDirectory($directory);

            throw new InvalidImageException('The image could not be processed.', 0, $e);
        }

        return "{$directory}/{$largest}.webp";
    }

    /**
     * Widths that exist for an image whose largest variant is $largest, widest first.
     *
     * @return list<int>
     */
    public function widthsFor(int $largest): array
    {
        $narrower = array_filter(
            config('azhayon.images.widths'),
            fn (int $width) => $width < $largest,
        );

        $widths = array_unique([$largest, ...$narrower]);
        rsort($widths);

        return array_values($widths);
    }

    /**
     * Rebuilds the variant list from the stored (largest) path.
     *
     * @return list<array{width: int, path: string}>
     *
     * @throws InvalidImageException
     */
    public function variants(string $path): array
    {
        $largest = $this->parse($path);
        $directory = dirname($path);

        return array_map(
            fn (int $width) => ['width' => $width, 'path' => "{$directory}/{$width}.webp"],
            $this->widthsFor($largest),
        );
    }

    public function smallestVariant(string $path): string
    {
        $variants = $this->variants($path);

        return $variants[array_key_last($variants)]['path'];
    }

    /**
     * Confirms a stored reference is genuine and returns its real dimensions.
     *
     * @return array{width: int, height: int}
     *
     * @throws InvalidImageException
     */
    public function describe(string $path): array
    {
        $width = $this->parse($path);
        $disk = Storage::disk('uploads');

        if (! $disk->exists($path)) {
            throw new InvalidImageException('That image file does not exist.');
        }

        $size = @getimagesize($disk->path($path));

        if ($size === false || $size[0] !== $width) {
            throw new InvalidImageException('That image file is not valid.');
        }

        return ['width' => $size[0], 'height' => $size[1]];
    }

    /** Deletes every variant of an image. Not used automatically yet. */
    public function delete(string $path): void
    {
        $this->parse($path);

        Storage::disk('uploads')->deleteDirectory(dirname($path));
    }

    /** @throws InvalidImageException */
    private function parse(string $path): int
    {
        if (! preg_match(self::PATH_PATTERN, $path, $matches)) {
            throw new InvalidImageException('That is not a valid image reference.');
        }

        return (int) $matches[1];
    }
}