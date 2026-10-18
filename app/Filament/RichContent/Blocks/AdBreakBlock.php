<?php

namespace App\Filament\RichContent\Blocks;

use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Support\Icons\Heroicon;

class AdBreakBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'ad-break';
    }

    public static function getLabel(): string
    {
        return 'Ad break';
    }

    public static function getIcon(): Heroicon
    {
        return Heroicon::Megaphone;
    }

    /** @param  array<string, mixed>  $config */
    public static function toPreviewHtml(array $config): string
    {
        return '<div style="border:1px dashed #888;border-radius:.5rem;padding:.75rem;text-align:center">'
            .'Ad break: an ad slot is placed here. Adding one turns off automatic ad placement for this post.</div>';
    }
}