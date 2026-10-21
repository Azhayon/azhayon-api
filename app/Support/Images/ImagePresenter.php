<?php

namespace App\Support\Images;

/** Builds the image object the API returns: dimensions, alt text and every variant with its URL. */
final class ImagePresenter
{
    /** URL of the largest variant, or null when the reference is missing or invalid. */
    public static function url(?string $path): ?string
    {
        return app(ImageProcessor::class)->previewUrl($path) === null
            ? null
            : app(ImageProcessor::class)->url($path);
    }

    /**
     * Width and height are the stored dimensions of the largest variant; the
     * other variants' heights follow the same aspect ratio.
     *
     * @return array{url: string, width: int, height: int, alt: ?string, variants: list<array{width: int, height: int, url: string}>}|null
     */
    public static function present(?string $path, ?int $width, ?int $height, ?string $alt = null): ?array
    {
        if (! $path || ! $width || ! $height || $width < 1 || $height < 1) {
            return null;
        }

        $processor = app(ImageProcessor::class);

        try {
            $variants = $processor->variants($path);
        } catch (InvalidImageException) {
            return null;
        }

        return [
            'url' => $processor->url($path),
            'width' => $width,
            'height' => $height,
            'alt' => $alt,
            'variants' => array_map(fn (array $variant): array => [
                'width' => $variant['width'],
                'height' => max(1, (int) round($height * $variant['width'] / $width)),
                'url' => $processor->url($variant['path']),
            ], $variants),
        ];
    }
}