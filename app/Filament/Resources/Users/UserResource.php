<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use App\Support\Accounts\AccountManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Community';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    // Staff accounts are created with the "New staff account" button on the list page.
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
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->copyable()->placeholder('No email'),
                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ucfirst(self::enumValue($state)))
                    ->color(fn ($state): string => match (self::enumValue($state)) {
                        UserRole::Admin->value => 'danger',
                        UserRole::Editor->value => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('provider_name')
                    ->label('Sign-in')
                    ->formatStateUsing(fn ($state) => $state ? ucfirst((string) $state) : 'Password')
                    ->placeholder('Password'),
                TextColumn::make('two_factor_status')->label('2FA'),
                TextColumn::make('account_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Active' => 'success',
                        'Banned' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('posts_count')->counts('posts')->label('Posts'),
                TextColumn::make('comments_count')->counts('comments')->label('Comments'),
                TextColumn::make('created_at')
                    ->label('Joined')
                    ->dateTime(timezone: config('azhayon.admin_timezone'))
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('role')->options([
                    'admin' => 'Admin',
                    'editor' => 'Editor',
                    'user' => 'Reader',
                ]),
                TrashedFilter::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('changeRole')
                    ->label('Change role')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->visible(fn (User $record): bool => Gate::allows('changeRole', $record))
                    ->fillForm(fn (User $record): array => ['role' => self::enumValue($record->role)])
                    ->schema([
                        Select::make('role')
                            ->options(['editor' => 'Editor', 'admin' => 'Admin'])
                            ->required()
                            ->native(false),
                    ])
                    ->action(fn (User $record, array $data) => app(AccountManager::class)
                        ->changeRole($record, UserRole::from($data['role']))),
                Action::make('ban')
                    ->label('Ban')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('They will be signed out immediately and cannot sign in again until unbanned.')
                    ->visible(fn (User $record): bool => ! $record->trashed()
                        && ! $record->isBanned()
                        && Gate::allows('ban', $record))
                    ->action(fn (User $record) => app(AccountManager::class)->ban($record)),
                Action::make('unban')
                    ->label('Unban')
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => ! $record->trashed()
                        && $record->isBanned()
                        && Gate::allows('ban', $record))
                    ->action(fn (User $record) => app(AccountManager::class)->unban($record)),
                Action::make('resetTwoFactor')
                    ->label('Reset two-factor')
                    ->icon(Heroicon::OutlinedKey)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('They will be asked to set up two-factor authentication again at their next sign-in.')
                    ->visible(fn (User $record): bool => ! $record->trashed()
                        && Gate::allows('resetTwoFactor', $record))
                    ->action(fn (User $record) => app(AccountManager::class)->resetTwoFactor($record)),
                DeleteAction::make(),
                RestoreAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}