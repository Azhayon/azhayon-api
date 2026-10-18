<?php

namespace App\Filament\RichContent\Blocks;

use App\Support\Embeds\YouTube;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

class YouTubeBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'youtube';
    }

    public static function getLabel(): string
    {
        return 'YouTube video';
    }

    public static function getIcon(): Heroicon
    {
        return Heroicon::PlayCircle;
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalHeading('YouTube video')
            ->modalSubmitActionLabel('Insert video')
            ->schema([
                TextInput::make('video')
                    ->label('YouTube link or video ID')
                    ->required()
                    ->rules([
                        fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            if (YouTube::extractId((string) $value) === null) {
                                $fail('Enter a valid YouTube link or 11-character video ID.');
                            }
                        },
                    ])
                    ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : YouTube::extractId($state))
                    ->helperText('Only the video ID is stored. Readers load the player only when they press play.'),
                TextInput::make('title')
                    ->label('Video title')
                    ->maxLength(150)
                    ->helperText('Used as the accessible name of the player. Optional but recommended.'),
            ]);
    }

    /** @param  array<string, mixed>  $config */
    public static function toPreviewHtml(array $config): string
    {
        $id = YouTube::extractId((string) ($config['video'] ?? ''));

        if ($id === null) {
            return '<p>Invalid YouTube video.</p>';
        }

        return '<figure><img src="https://i.ytimg.com/vi/'.e($id).'/hqdefault.jpg" alt="" style="max-width:100%;height:auto">'
            .'<figcaption>YouTube: '.e($config['title'] ?? $id).'</figcaption></figure>';
    }
}