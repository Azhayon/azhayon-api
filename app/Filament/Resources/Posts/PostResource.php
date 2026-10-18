<?php

namespace App\Filament\Resources\Posts;

use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Support\ImageUpload;
use App\Models\Post;
use App\Models\Redirect;
use BackedEnum;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use UnitEnum;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'title';


    private static function enumValue(mixed $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }

    private static function canPublish(): bool
    {
        return Gate::allows('publish', new Post);
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        $options = collect(PostStatus::cases())
            ->mapWithKeys(fn (PostStatus $status) => [$status->value => ucfirst($status->value)]);

        return self::canPublish() ? $options->all() : $options->only(PostStatus::Draft->value)->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Post')
                ->columnSpanFull()
                ->tabs([
                    Tab::make('Content')
                        ->columns(2)
                        ->schema([
                            TextInput::make('title')
                                ->required()
                                ->maxLength(200)
                                ->live(onBlur: true)
                                ->afterStateUpdated(function (string $operation, ?string $state, Set $set): void {
                                    if ($operation === 'create') {
                                        $set('slug', Str::slug((string) $state));
                                    }
                                })
                                ->columnSpanFull(),
                            TextInput::make('subtitle')
                                ->maxLength(255)
                                ->columnSpanFull(),
                            TextInput::make('slug')
                                ->required()
                                ->maxLength(220)
                                ->unique(ignoreRecord: true)
                                ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                                ->validationMessages(['regex' => 'Use lowercase letters, numbers and single hyphens only.'])
                                ->rules([
                                    fn (?Post $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                        $prefix = rtrim((string) config('azhayon.paths.post'), '/');
                                        $redirect = Redirect::where('from_path', $prefix.'/'.$value)->first();

                                        if ($redirect && (! $record || $redirect->to_path !== $prefix.'/'.$record->slug)) {
                                            $fail('That URL is reserved by an existing redirect. Remove the redirect first.');
                                        }
                                    },
                                ])
                                ->helperText('Changing the slug of a live post automatically adds a 301 redirect from the old URL.')
                                ->columnSpanFull(),
                            Textarea::make('excerpt')
                                ->rows(3)
                                ->maxLength(300)
                                ->helperText('Shown on cards and used as the description when the SEO field is empty.')
                                ->columnSpanFull(),
                            Select::make('post_type')
                                ->options(collect(PostType::cases())->mapWithKeys(
                                    fn (PostType $type) => [$type->value => ucfirst($type->value)]
                                )->all())
                                ->default(PostType::News->value)
                                ->required()
                                ->live()
                                ->native(false),
                            TextInput::make('review_score')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(10)
                                ->step(0.1)
                                ->visible(fn (Get $get): bool => self::enumValue($get('post_type')) === PostType::Review->value)
                                ->helperText('Out of 10. Used for review rich results in search.'),
                            Select::make('category_id')
                                ->relationship('category', 'name')
                                ->required()
                                ->searchable()
                                ->preload()
                                ->native(false),
                            Select::make('series_id')
                                ->relationship('series', 'title')
                                ->searchable()
                                ->preload()
                                ->native(false),
                            TextInput::make('subject_title')
                                ->maxLength(200)
                                ->helperText('The anime, movie or manga this post is about, e.g. "Jujutsu Kaisen". Powers search by title.'),
                            Select::make('tags')
                                ->relationship('tags', 'name')
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->native(false)
                                ->columnSpanFull(),
                        ]),

                    Tab::make('Image')
                        ->schema([
                            ImageUpload::make('featured_image')
                                ->label('Featured image')
                                ->helperText('JPEG, PNG or WebP, up to 5 MB. Converted to WebP automatically. 1200 px wide or more looks best.'),
                            TextInput::make('featured_image_alt')
                                ->label('Alt text')
                                ->maxLength(255)
                                ->required(fn (Get $get): bool => filled($get('featured_image')))
                                ->helperText('Describe the image for screen readers and search. Required when there is an image.'),
                        ]),

                    Tab::make('SEO')
                        ->schema([
                            TextInput::make('meta_title')
                                ->maxLength(255)
                                ->helperText('Optional. Defaults to the title. Around 50 to 60 characters displays fully in Google.'),
                            Textarea::make('meta_description')
                                ->rows(3)
                                ->maxLength(320)
                                ->helperText('Optional. Around 120 to 160 characters displays fully in Google.'),
                            ImageUpload::make('og_image')
                                ->label('Social share image')
                                ->helperText('Optional. 1200 × 630 works best. Defaults to the featured image.'),
                            TextInput::make('canonical_url')
                                ->url()
                                ->maxLength(2048)
                                ->helperText('Leave empty unless this post republishes content that lives at another address.'),
                            Toggle::make('noindex')
                                ->label('Hide from search engines (noindex)'),
                        ]),

                    Tab::make('Publishing')
                        ->columns(2)
                        ->schema([
                            Select::make('status')
                                ->options(fn (): array => self::statusOptions())
                                ->default(PostStatus::Draft->value)
                                ->native(false)
                                ->live()
                                ->disabled(fn (): bool => ! self::canPublish())
                                ->helperText(fn (): ?string => self::canPublish() ? null : 'Only admins can publish or schedule posts.'),
                            DateTimePicker::make('published_at')
                                ->label('Publish at')
                                ->timezone(config('azhayon.admin_timezone'))
                                ->seconds(false)
                                ->disabled(fn (): bool => ! self::canPublish())
                                ->required(fn (Get $get): bool => self::enumValue($get('status')) === PostStatus::Scheduled->value)
                                ->helperText('Times are in '.config('azhayon.admin_timezone').'. Scheduled posts go live within a minute of this time. Leave empty to publish immediately.'),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('featured_thumb')
                    ->label('Image')
                    ->disk('uploads')
                    ->visibility('public')
                    ->square()
                    ->imageSize(48),
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(60),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ucfirst(self::enumValue($state)))
                    ->color(fn ($state): string => match (self::enumValue($state)) {
                        PostStatus::Published->value => 'success',
                        PostStatus::Scheduled->value => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('post_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ucfirst(self::enumValue($state)))
                    ->sortable(),
                TextColumn::make('category.name')->sortable(),
                TextColumn::make('author.name')->toggleable(),
                TextColumn::make('published_at')
                    ->label('Published')
                    ->dateTime(timezone: config('azhayon.admin_timezone'))
                    ->sortable(),
                TextColumn::make('view_count')
                    ->label('Views')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->dateTime(timezone: config('azhayon.admin_timezone'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(PostStatus::cases())->mapWithKeys(fn (PostStatus $s) => [$s->value => ucfirst($s->value)])->all()
                ),
                SelectFilter::make('post_type')->label('Type')->options(
                    collect(PostType::cases())->mapWithKeys(fn (PostType $t) => [$t->value => ucfirst($t->value)])->all()
                ),
                SelectFilter::make('category')->relationship('category', 'name'),
                TrashedFilter::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with(['category', 'author']);

        $user = auth()->user();

        // Admins see everything. Editors see published posts plus their own drafts.
        if ($user && ! $user->isAdmin()) {
            $query->withoutTrashed()->where(function (Builder $query) use ($user): void {
                $query->where('author_id', $user->id)
                    ->orWhere('status', PostStatus::Published->value);
            });
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPosts::route('/'),
            'create' => CreatePost::route('/create'),
            'edit' => EditPost::route('/{record}/edit'),
        ];
    }
}