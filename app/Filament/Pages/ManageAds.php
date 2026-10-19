<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Support\Settings\AdSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class ManageAds extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Site';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Ads';

    protected string $view = 'filament.pages.manage-ads';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    // Custom pages do not apply resource policies automatically, so we do it here.
    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', Setting::class);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill(AdSettings::get());
    }

    public function form(Schema $schema): Schema
    {
        $anyEnabled = fn (Get $get): bool => (bool) $get('enabled')
            || collect(AdSettings::PLACEMENTS)->contains(fn (string $placement) => (bool) $get("placements.{$placement}.enabled"));

        $placements = [
            'sidebar' => ['Sidebar ad', 'Shown beside the article on large screens.'],
            'in_article' => ['In-article ads', 'Inserted between paragraphs (at most 3 per post).'],
            'anchor' => ['Anchor ad', 'A slim sticky banner at the bottom of the screen.'],
        ];

        $sections = [];

        foreach ($placements as $key => [$label, $description]) {
            $sections[] = Section::make($label)
                ->description($description)
                ->schema([
                    Toggle::make("placements.{$key}.enabled")
                        ->label('Show this ad'),
                    TextInput::make("placements.{$key}.slot")
                        ->label('Ad unit ID')
                        ->regex('/^\d{6,20}$/')
                        ->validationMessages(['regex' => 'The ad unit ID is a number of 6 to 20 digits.'])
                        ->required(fn (Get $get): bool => (bool) $get("placements.{$key}.enabled"))
                        ->helperText('From your AdSense dashboard: Ads, By ad unit.'),
                ]);
        }

        return $schema
            ->components([
                Form::make([
                    Section::make('Google AdSense')
                        ->schema([
                            Toggle::make('enabled')
                                ->label('Show ads on the site')
                                ->helperText('Master switch. Turn it off to remove every ad instantly, for example while AdSense reviews the site.'),
                            TextInput::make('client')
                                ->label('Publisher ID')
                                ->placeholder('ca-pub-1234567890123456')
                                ->regex('/^ca-pub-\d{16}$/')
                                ->validationMessages(['regex' => 'The publisher ID looks like ca-pub- followed by 16 digits.'])
                                ->required($anyEnabled)
                                ->helperText('Not secret: it appears in the page source of every AdSense site. Auto ads and vignettes are managed in your AdSense dashboard, not here.'),
                        ]),
                    ...$sections,
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        Gate::authorize('update', new Setting);

        AdSettings::save($this->form->getState());

        Notification::make()
            ->success()
            ->title('Saved')
            ->send();
    }
}