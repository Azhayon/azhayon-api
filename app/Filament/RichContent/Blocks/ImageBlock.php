<?php

namespace App\Filament\RichContent\Blocks;

use App\Filament\Support\ImageUpload;
use App\Support\Images\ImageProcessor;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

class ImageBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'image';
    }

    public static function getLabel(): string
    {
        return 'Image';
    }

    public static function getIcon(): Heroicon
    {
        return Heroicon::Photo;
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalHeading('Image')
            ->modalSubmitActionLabel('Insert image')
            ->schema([
                ImageUpload::make('image')
                    ->label('Image')
                    ->required()
                    ->helperText('JPEG, PNG or WebP, up to 5 MB. Converted to WebP automatically.'),
                TextInput::make('alt')
                    ->label('Alt text')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Describe the image for screen readers and search engines.'),
                TextInput::make('caption')
                    ->maxLength(300),
            ]);
    }

    /** @param  array<string, mixed>  $config */
    public static function toPreviewHtml(array $config): string
    {
        $url = app(ImageProcessor::class)->previewUrl($config['image'] ?? null);

        if ($url === null) {
            return '<p>Image unavailable.</p>';
        }

        $caption = filled($config['caption'] ?? null)
            ? '<figcaption>'.e($config['caption']).'</figcaption>'
            : '';

        return '<figure><img src="'.e($url).'" alt="'.e($config['alt'] ?? '').'" style="max-width:100%;height:auto">'.$caption.'</figure>';
    }
}