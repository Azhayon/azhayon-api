<?php

namespace App\Filament\Support;

use App\Support\Images\ImageProcessor;
use App\Support\Images\InvalidImageException;
use Filament\Forms\Components\FileUpload;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ImageUpload
{
    /**
     * An image field whose upload is validated, converted to WebP and resized
     * by ImageProcessor. The field's value is the largest variant's path.
     */
    public static function make(string $name): FileUpload
    {
        return FileUpload::make($name)
            ->disk('uploads')
            ->visibility('public')
            ->image()
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->maxSize((int) (config('azhayon.images.max_bytes') / 1024))
            ->imagePreviewHeight('160')
            ->openable()
            ->saveUploadedFileUsing(function (FileUpload $component, TemporaryUploadedFile $file): string {
                try {
                    return app(ImageProcessor::class)->store($file->get());
                } catch (InvalidImageException $e) {
                    throw ValidationException::withMessages([$component->getStatePath() => $e->getMessage()]);
                }
            });
    }
}