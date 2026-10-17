<?php

namespace App\Models\Concerns;

use App\Support\Images\ImageProcessor;
use App\Support\Images\InvalidImageException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Keeps image dimension columns in step with the stored image reference, and
 * refuses any reference that is not a genuine processed image.
 *
 * The using model defines processedImageFields(): field => [widthColumn|null, heightColumn|null].
 */
trait HasProcessedImages
{
    /** @return array<string, array{0: ?string, 1: ?string}> */
    abstract protected function processedImageFields(): array;

    protected static function bootHasProcessedImages(): void
    {
        static::saving(function (Model $model): void {
            $processor = app(ImageProcessor::class);

            foreach ($model->processedImageFields() as $field => [$widthColumn, $heightColumn]) {
                if (! $model->isDirty($field)) {
                    continue;
                }

                $path = $model->getAttribute($field);

                if ($path === null || $path === '') {
                    $model->setAttribute($field, null);
                    $widthColumn && $model->setAttribute($widthColumn, null);
                    $heightColumn && $model->setAttribute($heightColumn, null);

                    continue;
                }

                try {
                    $size = $processor->describe((string) $path);
                } catch (InvalidImageException $e) {
                    throw ValidationException::withMessages([$field => $e->getMessage()]);
                }

                $widthColumn && $model->setAttribute($widthColumn, $size['width']);
                $heightColumn && $model->setAttribute($heightColumn, $size['height']);
            }
        });
    }
}