<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Setting;
use App\Support\Settings\AdSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function validAds(array $overrides = []): array
    {
        return array_replace_recursive([
            'enabled' => true,
            'client' => 'ca-pub-1234567890123456',
            'placements' => [
                'sidebar' => ['enabled' => true, 'slot' => '1234567890'],
                'in_article' => ['enabled' => false, 'slot' => '9999999'],
                'anchor' => ['enabled' => false, 'slot' => null],
            ],
        ], $overrides);
    }

    public function test_the_defaults_describe_the_site_and_list_categories_alphabetically(): void
    {
        Category::create(['name' => 'Manga', 'slug' => 'manga']);
        Category::create(['name' => 'Anime', 'slug' => 'anime']);

        $this->getJson('/api/v1/site')
            ->assertOk()
            ->assertJsonPath('data.name', 'Azhayon Media')
            ->assertJsonPath('data.url', 'http://localhost:3000')
            ->assertJsonPath('data.ads', ['enabled' => false])
            ->assertJsonPath('data.categories', [
                ['name' => 'Anime', 'slug' => 'anime'],
                ['name' => 'Manga', 'slug' => 'manga'],
            ]);
    }

    public function test_stored_site_settings_override_the_defaults(): void
    {
        Setting::put('site', ['name' => 'Azhayon', 'tagline' => 'Watch, read, repeat.']);

        $this->getJson('/api/v1/site')
            ->assertJsonPath('data.name', 'Azhayon')
            ->assertJsonPath('data.tagline', 'Watch, read, repeat.');
    }

    public function test_nothing_about_the_ad_account_is_published_while_ads_are_off(): void
    {
        AdSettings::save($this->validAds(['enabled' => false]));

        $response = $this->getJson('/api/v1/site')->assertOk();

        $response->assertJsonPath('data.ads', ['enabled' => false]);
        $this->assertStringNotContainsString('ca-pub', (string) $response->getContent());
        $this->assertStringNotContainsString('1234567890', (string) $response->getContent());
    }

    public function test_enabled_ads_expose_only_what_the_frontend_needs(): void
    {
        AdSettings::save($this->validAds());

        $this->getJson('/api/v1/site')
            ->assertOk()
            ->assertJsonPath('data.ads.enabled', true)
            ->assertJsonPath('data.ads.client', 'ca-pub-1234567890123456')
            ->assertJsonPath('data.ads.placements.sidebar', ['enabled' => true, 'slot' => '1234567890'])
            ->assertJsonPath('data.ads.placements.in_article', ['enabled' => false, 'slot' => null])
            ->assertJsonPath('data.ads.rules', ['in_article_every' => 4, 'in_article_max' => 3]);
    }

    public function test_changes_show_up_even_though_responses_are_cached(): void
    {
        $this->getJson('/api/v1/site')->assertJsonPath('data.categories', []);

        Category::create(['name' => 'Movies', 'slug' => 'movies']);

        $this->getJson('/api/v1/site')->assertJsonPath('data.categories.0.slug', 'movies');

        AdSettings::save($this->validAds());
        $this->getJson('/api/v1/site')->assertJsonPath('data.ads.enabled', true);

        AdSettings::save($this->validAds(['enabled' => false])); // the kill switch
        $this->getJson('/api/v1/site')->assertJsonPath('data.ads', ['enabled' => false]);
    }
}