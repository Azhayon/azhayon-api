<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\ManageAds;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Comments\CommentResource;
use App\Filament\Resources\ContentHealth\ContentHealthResource;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\Redirects\RedirectResource;
use App\Filament\Resources\Series\SeriesResource;
use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Filament\Resources\Tags\TagResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelPermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** Staff with two-factor already set up, so the "must enrol" redirect does not apply. */
    private function makeStaff(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'provider_name' => null,
            'provider_id' => null,
            'password' => 'password-for-tests',
            'app_authentication_secret' => 'JBSWY3DPEHPK3PXP',
        ]);
    }

    /** @return array<string, string> */
    private function staffScreens(): array
    {
        return [
            'dashboard' => Filament::getPanel('admin')->getUrl(),
            'posts' => PostResource::getUrl('index'),
            'categories' => CategoryResource::getUrl('index'),
            'tags' => TagResource::getUrl('index'),
            'series' => SeriesResource::getUrl('index'),
            'comments' => CommentResource::getUrl('index'),
            'content health' => ContentHealthResource::getUrl('index'),
        ];
    }

    /** @return array<string, string> */
    private function adminOnlyScreens(): array
    {
        return [
            'users' => UserResource::getUrl('index'),
            'redirects' => RedirectResource::getUrl('index'),
            'subscribers' => SubscriberResource::getUrl('index'),
            'ads' => ManageAds::getUrl(),
        ];
    }

    /** @param  array<string, string>  $screens */
    private function assertScreens(User $user, array $screens, int $expected): void
    {
        $this->actingAs($user);

        foreach ($screens as $name => $url) {
            $this->assertSame($expected, $this->get($url)->getStatusCode(), "{$name} ({$url}) as {$user->role->value}");
        }
    }

    public function test_admins_can_open_every_screen(): void
    {
        $admin = $this->makeStaff('admin');

        $this->assertScreens($admin, $this->staffScreens(), 200);
        $this->assertScreens($admin, $this->adminOnlyScreens(), 200);
    }

    public function test_editors_get_the_content_screens_but_not_the_admin_only_ones(): void
    {
        $editor = $this->makeStaff('editor');

        $this->assertScreens($editor, $this->staffScreens(), 200);
        $this->assertScreens($editor, $this->adminOnlyScreens(), 403);
    }

    public function test_readers_cannot_open_any_screen(): void
    {
        $reader = User::factory()->create();

        $this->assertScreens($reader, $this->staffScreens() + $this->adminOnlyScreens(), 403);
    }

    public function test_guests_are_sent_to_the_login_page_from_every_screen(): void
    {
        foreach ($this->staffScreens() + $this->adminOnlyScreens() as $name => $url) {
            $this->get($url)->assertRedirectContains('/admin/login');
        }
    }
}