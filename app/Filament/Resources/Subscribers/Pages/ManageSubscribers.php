<?php

namespace App\Filament\Resources\Subscribers\Pages;

use App\Filament\Resources\Subscribers\SubscriberResource;
use Filament\Resources\Pages\ManageRecords;

class ManageSubscribers extends ManageRecords
{
    protected static string $resource = SubscriberResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}