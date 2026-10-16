<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Staff with two-factor already set up, so the "must enrol" redirect doesn't apply. */
    private function staff(string $role = 'admin', array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'provider_name' => null,
            'provider_id' => null,
            'password' => 'password-for-tests',
            'app_authentication_secret' => 'JBSWY3DPEHPK3PXP',
            'banned_at' => null,
            'deleted_at' => null,
        ], $extra));
    }

    public function test_login_page_is_reachable(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/admin')->assertRedirectContains('/admin/login');
    }

    public function test_admins_can_enter_the_panel(): void
    {
        $this->actingAs($this->staff('admin'))->get('/admin')->assertOk();
    }

    public function test_editors_can_enter_the_panel(): void
    {
        $this->actingAs($this->staff('editor'))->get('/admin')->assertOk();
    }

    public function test_readers_cannot_enter_the_panel(): void
    {
        /** @var User $reader */
        $reader = User::factory()->create([
            'role' => 'user',
            'banned_at' => null,
            'deleted_at' => null,
        ]);

        $this->actingAs($reader)->get('/admin')->assertForbidden();
    }

    public function test_banned_staff_cannot_enter_the_panel(): void
    {
        $banned = $this->staff('admin', ['banned_at' => now()]);

        $this->actingAs($banned)->get('/admin')->assertForbidden();
    }

    public function test_staff_without_two_factor_are_not_let_straight_in(): void
    {
        $staff = $this->staff('admin', ['app_authentication_secret' => null]);

        $this->actingAs($staff)->get('/admin')->assertRedirect();
    }

    public function test_api_and_admin_responses_are_noindex(): void
    {
        foreach (['/api/v1/health', '/admin/login', '/does-not-exist'] as $path) {
            $this->get($path)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }
    }
}