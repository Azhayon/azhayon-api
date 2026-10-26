<?php

namespace App\Filament\Actions;

use App\Models\Post;
use App\Support\Preview\PreviewLink;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class PreviewLinkAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'preview';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Preview')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->visible(fn (?Model $record): bool => $record instanceof Post && Gate::allows('update', $record))
            ->modalHeading('Preview link')
            ->modalDescription('Shows the last saved version of this post on the real site, even as a draft. Anyone with the link can see it for the next hour. It is hidden from search engines.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->fillForm(fn (Post $record): array => ['url' => PreviewLink::for($record)])
            ->schema([
                TextInput::make('url')
                    ->label('Address to copy')
                    ->readOnly()
                    ->copyable()
                    ->extraInputAttributes(['onclick' => 'this.select()'])
                    ->helperText('Click the address to select it, copy it, and open it in a new tab. A new link is made each time you open this window.'),
            ]);
    }
}