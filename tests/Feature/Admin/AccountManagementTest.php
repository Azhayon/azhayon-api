<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use App\Support\Accounts\AccountManager;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class AccountManagementTest extends TestCase
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

    private function editor(): User
    {
        return User::factory()->editor()->create();
    }

    private function reader(): User
    {
        return User::factory()->create();
    }

    private function accounts(): AccountManager
    {
        return app(AccountManager::class);
    }

    private function staffData(array $extra = []): array
    {
        return array_merge([
            'name' => ' Eve ',
            'email' => 'EVE@Example.test',
            'role' => 'editor',
            'password' => 'a-long-password-1',
        ], $extra);
    }

    public function test_admins_create_staff_accounts_with_the_chosen_role(): void
    {
        $this->actingAs($this->admin());

        $user = $this->accounts()->createStaff($this->staffData());

        $this->assertSame(UserRole::Editor, $user->fresh()->role);
        $this->assertSame('Eve', $user->name);
        $this->assertSame('eve@example.test', $user->email);
        $this->assertTrue(Hash::check('a-long-password-1', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->provider_name);
    }

    public function test_staff_creation_is_limited_to_admins_and_staff_roles(): void
    {
        $this->actingAs($this->admin());

        try {
            $this->accounts()->createStaff($this->staffData(['role' => 'user']));
            $this->fail('A reader account was created as staff.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->actingAs($this->editor());

        $this->expectException(AuthorizationException::class);
        $this->accounts()->createStaff($this->staffData());
    }

    public function test_the_create_staff_action_validates_its_input(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);
        $this->actingAs($this->admin());

        Livewire::test(ManageUsers::class)
            ->callAction('createStaff', data: $this->staffData(['email' => 'taken@example.test']))
            ->assertHasActionErrors(['email']);

        Livewire::test(ManageUsers::class)
            ->callAction('createStaff', data: $this->staffData(['password' => 'short1']))
            ->assertHasActionErrors(['password']);

        Livewire::test(ManageUsers::class)
            ->callAction('createStaff', data: $this->staffData(['email' => 'new@example.test']))
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('users', ['email' => 'new@example.test', 'role' => 'editor']);
    }

    public function test_banning_signs_the_user_out_and_unbanning_restores_access(): void
    {
        $reader = $this->reader();
        $reader->createToken('web');

        $this->actingAs($this->admin());

        $this->accounts()->ban($reader);
        $this->assertTrue($reader->fresh()->isBanned());
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->accounts()->unban($reader);
        $this->assertFalse($reader->fresh()->isBanned());
    }

    public function test_nobody_can_ban_themselves_and_editors_cannot_ban(): void
    {
        $admin = $this->admin();
        $reader = $this->reader();

        $this->actingAs($admin);
        $this->assertTrue(Gate::denies('ban', $admin));

        $this->actingAs($this->editor());
        $this->expectException(AuthorizationException::class);
        $this->accounts()->ban($reader);
    }

    public function test_roles_change_only_between_staff_roles_and_never_for_yourself(): void
    {
        $admin = $this->admin();
        $editor = $this->editor();
        $reader = $this->reader();

        $this->actingAs($admin);

        $this->accounts()->changeRole($editor, UserRole::Admin);
        $this->assertSame(UserRole::Admin, $editor->fresh()->role);

        $this->assertTrue(Gate::denies('changeRole', $admin));
        $this->assertTrue(Gate::denies('changeRole', $reader));

        try {
            $this->accounts()->changeRole($reader, UserRole::Editor);
            $this->fail('A reader was promoted.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->accounts()->changeRole($editor, UserRole::User);
    }

    public function test_two_factor_can_be_reset_for_other_staff_only(): void
    {
        $admin = $this->admin();
        $editor = $this->editor();
        $editor->forceFill([
            'app_authentication_secret' => 'JBSWY3DPEHPK3PXP',
            'app_authentication_recovery_codes' => ['code-one', 'code-two'],
        ])->save();

        $this->actingAs($admin);

        $this->accounts()->resetTwoFactor($editor);

        $row = DB::table('users')->where('id', $editor->id)->first(['app_authentication_secret', 'app_authentication_recovery_codes']);
        $this->assertNull($row->app_authentication_secret);
        $this->assertNull($row->app_authentication_recovery_codes);

        $this->assertTrue(Gate::denies('resetTwoFactor', $admin));
        $this->assertTrue(Gate::denies('resetTwoFactor', $this->reader()));
    }

    public function test_deletion_rules(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin);

        $this->assertTrue(Gate::denies('delete', $admin));
        $this->assertTrue(Gate::allows('delete', $this->reader()));

        $this->actingAs($this->editor());
        $this->assertTrue(Gate::denies('viewAny', User::class));
    }

    public function test_the_users_page_renders_and_labels_accounts(): void
    {
        $admin = $this->admin();
        $banned = $this->reader();
        $banned->forceFill(['banned_at' => now()])->save();
        $editor = $this->editor();
        $editor->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();

        $this->actingAs($admin);

        Livewire::test(ManageUsers::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$admin, $banned, $editor]);

        $this->assertSame('Banned', $banned->fresh()->account_status);
        $this->assertSame('Active', $admin->fresh()->account_status);
        $this->assertSame('On', $editor->fresh()->two_factor_status);
        $this->assertSame('Off', $admin->fresh()->two_factor_status);
        $this->assertSame('—', $banned->fresh()->two_factor_status);
    }
}