<?php

namespace App\Filament\RichContent\Blocks;

use App\Filament\Support\ImageUpload;
use App\Support\Images\ImageProcessor;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

class SpoilerBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'spoiler';
    }

    public static function getLabel(): string
    {
        return 'Spoiler';
    }

    public static function getIcon(): Heroicon
    {
        return Heroicon::EyeSlash;
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalHeading('Spoiler')
            ->modalSubmitActionLabel('Insert spoiler')
            ->schema([
                TextInput::make('label')
                    ->default('Spoiler')
                    ->maxLength(60)
                    ->helperText('What readers see on the button, e.g. "Spoiler: episode 12 ending".'),
                Textarea::make('text')
                    ->label('Hidden text')
                    ->required()
                    ->rows(5)
                    ->maxLength(2000),
                ImageUpload::make('image')
                    ->label('Hidden image (optional)'),
                TextInput::make('image_alt')
                    ->label('Alt text for the hidden image')
                    ->maxLength(255)
                    ->required(fn (Get $get): bool => filled($get('image'))),
            ]);
    }

    /** @param  array<string, mixed>  $config */
    public static function toPreviewHtml(array $config): string
    {
        $url = app(ImageProcessor::class)->previewUrl($config['image'] ?? null);
        $image = $url ? '<img src="'.e($url).'" alt="'.e($config['image_alt'] ?? '').'" style="max-width:240px;height:auto">' : '';

        return '<div style="border:1px dashed #888;border-radius:.5rem;padding:1rem">'
            .'<strong>'.e($config['label'] ?? 'Spoiler').'</strong>'
            .'<p>'.e($config['text'] ?? '').'</p>'
            .$image
            .'</div>';
    }
}