<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_cannot_open_admin_pages(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
    }

    public function test_admin_sees_user_list(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['name' => 'นางสาวสมใจ ใจดี']);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('นางสาวสมใจ ใจดี');
    }

    public function test_admin_can_change_role_and_suspend_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->put(route('admin.users.update', $user), ['role' => 'executive', 'is_active' => '0'])
            ->assertSessionHas('status');

        $user->refresh();
        $this->assertSame('executive', $user->role);
        $this->assertFalse($user->is_active);
    }

    public function test_admin_cannot_demote_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('admin.users.update', $admin), ['role' => 'staff', 'is_active' => '1'])
            ->assertSessionHas('error');

        $this->assertSame('admin', $admin->fresh()->role);
    }
}
