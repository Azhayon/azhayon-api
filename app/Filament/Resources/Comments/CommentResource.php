<?php

namespace App\Filament\Resources\Comments;

use App\Enums\CommentStatus;
use App\Filament\Resources\Comments\Pages\ManageComments;
use App\Models\Comment;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class CommentResource extends Resource
{
    protected static ?string $model = Comment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Community';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'body';

    // Comments come from readers only; there is no "new comment" in the admin.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = Comment::where('status', CommentStatus::Pending->value)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    private static function enumValue(mixed $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }

    /** Every status change goes through here, so the permission is always re-checked. */
    public static function setStatus(Comment $comment, CommentStatus $status): void
    {
        Gate::authorize('moderate', $comment);

        $comment->forceFill(['status' => $status])->save();
    }

    private static function moderationAction(string $name, string $label, Heroicon $icon, string $color, CommentStatus $status): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->visible(fn (Comment $record): bool => ! $record->trashed()
                && $record->status !== $status
                && Gate::allows('moderate', $record))
            ->action(fn (Comment $record) => self::setStatus($record, $status));
    }

    private static function bulkModeration(string $name, string $label, Heroicon $icon, CommentStatus $status): BulkAction
    {
        return BulkAction::make($name)
            ->label($label)
            ->icon($icon)
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => $records->each(
                fn (Comment $comment) => self::setStatus($comment, $status)
            ));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('body')
                    ->limit(90)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('display_name')
                    ->label('Author'),
                TextColumn::make('post.title')
                    ->label('Post')
                    ->limit(35),
                IconColumn::make('is_spoiler')
                    ->label('Spoiler')
                    ->boolean(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ucfirst(self::enumValue($state)))
                    ->color(fn ($state): string => match (self::enumValue($state)) {
                        CommentStatus::Approved->value => 'success',
                        CommentStatus::Spam->value => 'danger',
                        default => 'warning',
                    })
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Posted')
                    ->dateTime(timezone: config('azhayon.admin_timezone'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(CommentStatus::cases())->mapWithKeys(
                        fn (CommentStatus $status) => [$status->value => ucfirst($status->value)]
                    )->all())
                    ->default(CommentStatus::Pending->value),
                TrashedFilter::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                self::moderationAction('approve', 'Approve', Heroicon::OutlinedCheckCircle, 'success', CommentStatus::Approved),
                self::moderationAction('spam', 'Mark as spam', Heroicon::OutlinedNoSymbol, 'danger', CommentStatus::Spam),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::bulkModeration('approveSelected', 'Approve selected', Heroicon::OutlinedCheckCircle, CommentStatus::Approved),
                    self::bulkModeration('spamSelected', 'Mark selected as spam', Heroicon::OutlinedNoSymbol, CommentStatus::Spam),
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with([
                'user',
                'post' => fn ($query) => $query->withTrashed(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageComments::route('/'),
        ];
    }
}