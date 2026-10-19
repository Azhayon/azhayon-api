<?php

namespace App\Filament\Resources\ContentHealth;

use App\Filament\Resources\ContentHealth\Pages\ListContentHealth;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class ContentHealthResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $slug = 'content-health';

    protected static ?string $navigationLabel = 'Content health';

    protected static ?string $modelLabel = 'post';

    protected static ?string $pluralModelLabel = 'posts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'title';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->wrap(),
                TextColumn::make('category.name')->label('Category'),
                TextColumn::make('published_at')
                    ->label('Published')
                    ->dateTime(timezone: config('azhayon.admin_timezone'))
                    ->sortable(),
                TextColumn::make('audit_messages')
                    ->label('Issues')
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->wrap()
                    ->placeholder('No issues'),
            ])
            ->filters([
                Filter::make('no_description')
                    ->label('No description')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query
                        ->where(fn (Builder $q) => $q->whereNull('meta_description')->orWhere('meta_description', ''))
                        ->where(fn (Builder $q) => $q->whereNull('excerpt')->orWhere('excerpt', ''))),
                Filter::make('no_image')
                    ->label('No featured image')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query
                        ->where(fn (Builder $q) => $q->whereNull('featured_image')->orWhere('featured_image', ''))),
                Filter::make('no_tags')
                    ->label('No tags')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->doesntHave('tags')),
                Filter::make('noindex')
                    ->label('Hidden from search (noindex)')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('noindex', true)),
            ])
            ->defaultSort('published_at', 'desc')
            ->recordUrl(fn (Post $record): ?string => Gate::allows('update', $record)
                ? PostResource::getUrl('edit', ['record' => $record])
                : null);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->published()
            ->withCount('tags')
            ->with('category');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContentHealth::route('/'),
        ];
    }
}