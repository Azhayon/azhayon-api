<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Resources\Posts\PostResource;
use App\Filament\Actions\LinkFinderAction;
use App\Support\Cache\ContentVersion;
use Filament\Resources\Pages\CreateRecord;

class CreatePost extends CreateRecord
{
    protected static string $resource = PostResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['author_id'] = auth()->id();

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [LinkFinderAction::make()];
    }

    protected function afterCreate(): void
    {
        ContentVersion::bump();
    }
}
