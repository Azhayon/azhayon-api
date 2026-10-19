<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\ManageAds;
use App\Models\User;
use App\Support\Settings\AdSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'provider_name' => null, 'provider_id' => null]);
    }

    private function valid(array $overrides = []): array
    {
        return array_replace_recursive([
            'enabled' => true,
            'client' => 'ca-pub-1234567890123456',
            'placements' => [
                'sidebar' => ['enabled' => true, 'slot' => '1234567890'],
                'in_article' => ['enabled' => false, 'slot' => null],
                'anchor' => ['enabled' => false, 'slot' => null],
            ],
        ], $overrides);
    }

    public function test_everything_is_off_by_default(): void
    {
        $this->assertSame(AdSettings::defaults(), AdSettings::get());
        $this->assertFalse(AdSettings::get()['enabled']);
    }

    public function test_normalising_keeps_only_valid_values(): void
    {
        $result = AdSettings::normalize([
            'enabled' => 'yes',
            'client' => 'not-a-publisher-id',
            'junk' => 'dropped',
            'placements' => [
                'sidebar' => ['enabled' => true, 'slot' => 'abc'],
                'in_article' => ['enabled' => true, 'slot' => 1234567],
                'bogus' => ['enabled' => true, 'slot' => '1234567'],
            ],
        ]);

        $this->assertNull($result['client']);
        $this->assertFalse($result['enabled']); // no valid publisher ID, so ads stay off
        $this->assertArrayNotHasKey('junk', $result);
        $this->assertArrayNotHasKey('bogus', $result['placements']);
        $this->assertFalse($result['placements']['sidebar']['enabled']); // invalid slot
        $this->assertNull($result['placements']['sidebar']['slot']);
        $this->assertSame('1234567', $result['placements']['in_article']['slot']);
        $this->assertTrue($result['placements']['in_article']['enabled']);
    }

    public function test_settings_round_trip(): void
    {
        AdSettings::save($this->valid());

        $this->assertSame($this->valid(), AdSettings::get());
    }

    public function test_the_page_saves_valid_settings(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(ManageAds::class)
            ->assertSuccessful()
            ->fillForm($this->valid())
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($this->valid(), AdSettings::get());
    }

    public function test_the_page_refuses_ads_without_the_ids_they_need(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(ManageAds::class)
            ->fillForm($this->valid(['client' => null]))
            ->call('save')
            ->assertHasFormErrors(['client']);

        Livewire::test(ManageAds::class)
            ->fillForm($this->valid(['placements' => ['sidebar' => ['enabled' => true, 'slot' => null]]]))
            ->call('save')
            ->assertHasFormErrors(['placements.sidebar.slot']);

        $this->assertSame(AdSettings::defaults(), AdSettings::get());
    }

    public function test_only_admins_can_open_the_ad_settings(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        $this->assertFalse(ManageAds::canAccess());

        $this->actingAs($this->admin());
        $this->assertTrue(ManageAds::canAccess());
    }
}