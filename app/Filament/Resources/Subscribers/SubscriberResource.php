<?php

namespace App\Filament\Resources\Subscribers;

use App\Enums\SubscriberStatus;
use App\Filament\Resources\Subscribers\Pages\ManageSubscribers;
use App\Models\Subscriber;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class SubscriberResource extends Resource
{
    protected static ?string $model = Subscriber::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Community';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'email';

    // Subscriptions come from the public sign-up form (with email confirmation), never from here.
    public static function canCreate(): bool
    {
        return false;
    }

    private static function enumValue(mixed $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')->searchable()->sortable()->copyable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ucfirst(self::enumValue($state)))
                    ->color(fn ($state): string => match (self::enumValue($state)) {
                        SubscriberStatus::Confirmed->value => 'success',
                        SubscriberStatus::Unsubscribed->value => 'gray',
                        default => 'warning',
                    })
                    ->sortable(),
                TextColumn::make('confirmed_at')
                    ->dateTime(timezone: config('azhayon.admin_timezone'))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Signed up')
                    ->dateTime(timezone: config('azhayon.admin_timezone'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(SubscriberStatus::cases())->mapWithKeys(
                    fn (SubscriberStatus $status) => [$status->value => ucfirst($status->value)]
                )->all()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSubscribers::route('/'),
        ];
    }
}