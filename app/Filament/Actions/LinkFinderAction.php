<?php

namespace App\Filament\Actions;

use App\Models\Post;
use App\Support\Links\LinkSuggester;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Component;

class LinkFinderAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'linkFinder';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Find a post to link to')
            ->icon(Heroicon::OutlinedLink)
            ->color('gray')
            ->modalHeading('Find a post to link to')
            ->modalDescription('Pick a post and copy its address. Then select words in the editor, press the link button and paste it.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->schema([
                Select::make('post')
                    ->label('Post')
                    ->placeholder('Suggested posts, or type to search')
                    ->searchable()
                    ->preload()
                    ->live()
                    ->options(fn (Component $livewire): array => self::suggestions($livewire))
                    ->getSearchResultsUsing(fn (string $search): array => self::search($search))
                    ->getOptionLabelUsing(fn (?string $value): ?string => self::labelFor($value))
                    ->afterStateUpdated(fn (?string $state, Set $set) => $set(
                        'url',
                        $state ? rtrim((string) config('azhayon.frontend_url'), '/').$state : null,
                    ))
                    ->helperText('Suggestions share a series, tags, subject or category with the post you are editing.'),
                TextInput::make('url')
                    ->label('Address to copy')
                    ->readOnly()
                    ->extraInputAttributes(['onclick' => 'this.select()'])
                    ->helperText('Click the address to select it, copy it, then paste it into the link dialog. It is saved as a site-relative link automatically.'),
            ]);
    }

    private static function path(string $slug): string
    {
        return rtrim((string) config('azhayon.paths.post'), '/').'/'.$slug;
    }

    /** @return array<string, string> */
    private static function suggestions(Component $livewire): array
    {
        $data = property_exists($livewire, 'data') && is_array($livewire->data) ? $livewire->data : [];
        $record = property_exists($livewire, 'record') && $livewire->record instanceof Model ? $livewire->record : null;

        return collect(app(LinkSuggester::class)->suggest($data, $record?->getKey()))
            ->mapWithKeys(fn (array $post): array => [$post['path'] => $post['title']])
            ->all();
    }

    /** @return array<string, string> */
    private static function search(string $search): array
    {
        $term = addcslashes(trim($search), '%_\\');

        return Post::published()
            ->where('title', 'like', "%{$term}%")
            ->orderByDesc('published_at')
            ->limit(15)
            ->get(['id', 'title', 'slug'])
            ->mapWithKeys(fn (Post $post): array => [self::path($post->slug) => $post->title])
            ->all();
    }

    private static function labelFor(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $slug = Str::after($path, rtrim((string) config('azhayon.paths.post'), '/').'/');

        return Post::published()->where('slug', $slug)->value('title');
    }
}