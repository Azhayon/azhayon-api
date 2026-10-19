<?php

namespace App\Filament\Resources\ContentHealth\Pages;

use App\Filament\Resources\ContentHealth\ContentHealthResource;
use Filament\Resources\Pages\ListRecords;

class ListContentHealth extends ListRecords
{
    protected static string $resource = ContentHealthResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}