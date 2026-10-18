<?php

namespace App\Filament\Resources\Redirects;

use App\Filament\Resources\Redirects\Pages\ManageRedirects;
use App\Models\Post;
use App\Models\Redirect;
use BackedEnum;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class RedirectResource extends Resource
{
    protected static ?string $model = Redirect::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnRight;

    protected static string|UnitEnum|null $navigationGroup = 'Site';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'from_path';

    /** Excludes the record being edited from "does another one exist" checks. */
    private static function others(?Redirect $record): Builder
    {
        return Redirect::query()->when($record, fn (Builder $query) => $query->whereKeyNot($record->getKey()));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('from_path')
                ->label('From (old URL path)')
                ->required()
                ->maxLength(255)
                ->regex('#^/[^\s?\#]*$#')
                ->validationMessages(['regex' => 'Start with / and use a path only (no spaces, ? or #).'])
                ->rules([
                    fn (?Redirect $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        $path = Redirect::normalizePath((string) $value);

                        if ($path === '/') {
                            $fail('The home page cannot be redirected.');

                            return;
                        }

                        if (self::others($record)->where('from_path', $path)->exists()) {
                            $fail('A redirect from this URL already exists.');

                            return;
                        }

                        $prefix = rtrim((string) config('azhayon.paths.post'), '/').'/';

                        if (str_starts_with($path, $prefix)) {
                            $slug = substr($path, strlen($prefix));

                            if ($slug !== '' && ! str_contains($slug, '/') && Post::published()->where('slug', $slug)->exists()) {
                                $fail('That URL belongs to a published post. A redirect would hide it.');

                                return;
                            }
                        }

                        if (self::others($record)->where('to_path', $path)->exists()) {
                            $fail('Another redirect already points to this URL. Update it first to avoid a redirect chain.');
                        }
                    },
                ])
                ->helperText('The address visitors used to reach, e.g. /blog/old-slug'),
            TextInput::make('to_path')
                ->label('To (new URL)')
                ->required()
                ->maxLength(2048)
                ->rules([
                    fn (?Redirect $record, \Filament\Schemas\Components\Utilities\Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record, $get): void {
                        $value = trim((string) $value);
                        $isPath = str_starts_with($value, '/') && ! str_starts_with($value, '//') && ! preg_match('/\s/', $value);
                        $isUrl = filter_var($value, FILTER_VALIDATE_URL) !== false
                            && in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true);

                        if (! $isPath && ! $isUrl) {
                            $fail('Enter a site path starting with / or a full http(s) address.');

                            return;
                        }

                        if ($isPath) {
                            $target = Redirect::normalizePath($value);

                            if ($target === Redirect::normalizePath((string) $get('from_path'))) {
                                $fail('A redirect cannot point to itself.');

                                return;
                            }

                            if (self::others($record)->where('from_path', $target)->exists()) {
                                $fail('That destination is itself redirected. Point to the final URL to avoid redirect chains.');
                            }
                        }
                    },
                ])
                ->helperText('A site path like /blog/new-slug, or a full https:// address.'),
            Select::make('status_code')
                ->label('Type')
                ->options([
                    301 => '301 Permanent (recommended)',
                    302 => '302 Temporary',
                ])
                ->default(301)
                ->required()
                ->native(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('from_path')->label('From')->searchable()->sortable(),
                TextColumn::make('to_path')->label('To')->searchable()->limit(60),
                TextColumn::make('status_code')->label('Type')->badge(),
                TextColumn::make('hits')->numeric()->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime(timezone: config('azhayon.admin_timezone'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordActions([
                EditAction::make(),
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
            'index' => ManageRedirects::route('/'),
        ];
    }
}