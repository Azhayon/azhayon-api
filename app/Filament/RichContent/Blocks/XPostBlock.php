<?php

namespace App\Filament\RichContent\Blocks;

use App\Support\Embeds\XPost;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

class XPostBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'x-post';
    }

    public static function getLabel(): string
    {
        return 'X post';
    }

    public static function getIcon(): Heroicon
    {
        return Heroicon::AtSymbol;
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalHeading('X post')
            ->modalSubmitActionLabel('Insert post')
            ->schema([
                TextInput::make('url')
                    ->label('Link to the post')
                    ->required()
                    ->rules([
                        fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            if (XPost::parse((string) $value) === null) {
                                $fail('Enter a link like https://x.com/username/status/1234567890.');
                            }
                        },
                    ])
                    ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : (XPost::parse($state)['url'] ?? null))
                    ->helperText('Shown as a lightweight card that links to the post. X\'s own scripts are never loaded.'),
                Textarea::make('text')
                    ->label('Quote (optional)')
                    ->rows(3)
                    ->maxLength(500)
                    ->helperText('Paste the post\'s text to show it on the card.'),
            ]);
    }

    /** @param  array<string, mixed>  $config */
    public static function toPreviewHtml(array $config): string
    {
        $post = XPost::parse((string) ($config['url'] ?? ''));

        if ($post === null) {
            return '<p>Invalid X post link.</p>';
        }

        $quote = filled($config['text'] ?? null) ? '<blockquote>'.e($config['text']).'</blockquote>' : '';

        return '<div style="border:1px solid #888;border-radius:.5rem;padding:1rem">'
            .'<strong>Post by @'.e($post['handle']).'</strong>'
            .$quote
            .'<a href="'.e($post['url']).'">View on X</a></div>';
    }
}