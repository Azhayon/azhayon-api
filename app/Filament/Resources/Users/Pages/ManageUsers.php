<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\Accounts\AccountManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;

class ManageUsers extends ManageRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createStaff')
                ->label('New staff account')
                ->icon(Heroicon::OutlinedUserPlus)
                ->visible(fn (): bool => Gate::allows('create', User::class))
                ->modalHeading('New staff account')
                ->modalDescription('Staff sign in with a password and must set up two-factor authentication at their first sign-in.')
                ->modalSubmitActionLabel('Create account')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(100),
                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(table: 'users', column: 'email'),
                    Select::make('role')
                        ->options(['editor' => 'Editor', 'admin' => 'Admin'])
                        ->default('editor')
                        ->required()
                        ->native(false),
                    TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->required()
                        ->rule(Password::min(12)->letters()->numbers())
                        ->helperText('At least 12 characters, with letters and numbers.'),
                ])
                ->action(fn (array $data) => app(AccountManager::class)->createStaff($data)),
        ];
    }
}