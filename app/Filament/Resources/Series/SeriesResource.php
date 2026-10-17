<?php

namespace App\Filament\Resources\Series;

use App\Filament\Resources\Series\Pages\ManageSeries;
use App\Models\Series;
use BackedEnum;
use App\Filament\Support\ImageUpload;
use Filament\Tables\Columns\ImageColumn;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

class SeriesResource extends Resource
{
    protected static ?string $model = Series::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $modelLabel = 'series';

    protected static ?string $pluralModelLabel = 'series';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->required()
                ->maxLength(200)
                ->live(onBlur: true)
                ->afterStateUpdated(function (string $operation, ?string $state, Set $set): void {
                    if ($operation === 'create') {
                        $set('slug', Str::slug((string) $state));
                    }
                }),
            TextInput::make('slug')
                ->required()
                ->maxLength(220)
                ->unique(ignoreRecord: true)
                ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                ->validationMessages(['regex' => 'Use lowercase letters, numbers and single hyphens only.']),
            Textarea::make('description')
                ->rows(4)
                ->maxLength(5000)
                ->helperText('Shown on the franchise hub page.')
                ->columnSpanFull(),
            ImageUpload::make('cover_image')
                ->label('Cover image')
                ->helperText('JPEG, PNG or WebP, up to 5 MB, at least 320 px wide. It is converted to WebP automatically.')
                ->columnSpanFull(),
            TextInput::make('meta_title')
                ->maxLength(255)
                ->helperText('Optional. Defaults to the title. Around 50 to 60 characters works best.'),
            Textarea::make('meta_description')
                ->rows(3)
                ->maxLength(320)
                ->helperText('Optional. Around 120 to 160 characters works best.')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('cover_thumb')
                    ->label('Cover')
                    ->disk('uploads')
                    ->visibility('public')
                    ->square()
                    ->imageSize(48),
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('slug')->searchable()->sortable(),
                TextColumn::make('posts_count')->counts('posts')->label('Posts')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('title')
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
            'index' => ManageSeries::route('/'),
        ];
    }
}