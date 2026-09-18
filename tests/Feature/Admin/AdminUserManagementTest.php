<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_another_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this
            ->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'Novo Admin',
                'email' => 'novo.admin@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

        $response->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', [
            'email' => 'novo.admin@example.com',
            'role' => 'admin',
        ]);
    }

    public function test_admin_can_update_another_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $otherAdmin = User::factory()->create(['role' => 'admin', 'name' => 'Old Name']);

        $response = $this
            ->actingAs($admin)
            ->put(route('admin.users.update', $otherAdmin), [
                'name' => 'Updated Name',
                'email' => $otherAdmin->email,
            ]);

        $response->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', [
            'id' => $otherAdmin->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'admin']);

        $response = $this
            ->actingAs($admin)
            ->delete(route('admin.users.destroy', $admin));

        $response->assertSessionHasErrors('id');

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
        ]);
    }

    public function test_admin_can_delete_another_admin_but_not_the_last_one(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $otherAdmin = User::factory()->create(['role' => 'admin']);

        $this
            ->actingAs($admin)
            ->delete(route('admin.users.destroy', $otherAdmin))
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseMissing('users', ['id' => $otherAdmin->id]);

        // $admin is now the only admin left; deleting themselves is blocked
        // by both the self-deletion guard and the last-admin guard.
        $response = $this
            ->actingAs($admin)
            ->delete(route('admin.users.destroy', $admin));

        $response->assertSessionHasErrors('id');
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_client_cannot_access_admin_users_routes(): void
    {
        $clientUser = User::factory()->create(['role' => 'client']);

        $this
            ->actingAs($clientUser)
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }
}
