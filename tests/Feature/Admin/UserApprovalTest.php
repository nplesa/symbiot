<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Users;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class UserApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_registration_is_pending_and_stores_ip_and_country(): void
    {
        Http::fake(['ipwho.is/*' => Http::response(['success' => true, 'country_code' => 'RO'])]);

        $this->withServerVariables(['REMOTE_ADDR' => '86.120.10.5'])->post('/register', [
            'name' => 'Nou',
            'email' => 'nou@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $user = User::where('email', 'nou@example.com')->firstOrFail();

        $this->assertNull($user->approved_at);
        $this->assertFalse($user->is_admin);
        $this->assertSame('86.120.10.5', $user->registration_ip);
        $this->assertSame('RO', $user->country);
    }

    public function test_pending_user_is_redirected_and_cannot_use_app_or_api(): void
    {
        $user = User::factory()->pending()->create();

        $this->actingAs($user)->get('/home')->assertRedirect('/pending-approval');
        $this->actingAs($user)->get('/trasee')->assertRedirect('/pending-approval');
        $this->actingAs($user)->get('/pending-approval')->assertOk();
        $this->actingAs($user)->getJson('/api/location/city')->assertForbidden();
    }

    public function test_approved_user_can_use_app(): void
    {
        $this->actingAs(User::factory()->create())->get('/home')->assertOk();
    }

    public function test_only_admin_can_open_users_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/users')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/admin/users')->assertOk();
    }

    public function test_admin_sees_pending_and_existing_users_with_correct_actions(): void
    {
        User::factory()->pending()->create(['name' => 'Asteapta', 'registration_ip' => '1.2.3.4']);
        User::factory()->create();
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(Users::class)
            ->assertSee('Asteapta')
            ->assertSee('1.2.3.4')
            ->assertSee('ACCEPT')
            ->assertSee('GHOST')
            ->assertSee('EDITARE');
    }

    public function test_admin_can_accept_and_delete_users(): void
    {
        $admin = User::factory()->admin()->create();
        $pending = User::factory()->pending()->create();
        $other = User::factory()->create();

        Livewire::actingAs($admin)->test(Users::class)
            ->call('accept', $pending->id)
            ->call('delete', $other->id);

        $this->assertTrue($pending->fresh()->isApproved());
        $this->assertModelMissing($other);
    }

    public function test_admin_cannot_delete_self_and_non_admin_cannot_act(): void
    {
        $admin = User::factory()->admin()->create();
        $pending = User::factory()->pending()->create();

        Livewire::actingAs($admin)->test(Users::class)->call('delete', $admin->id)->assertForbidden();
        $component = Livewire::actingAs($admin)->test(Users::class);
        $this->actingAs(User::factory()->create());
        $component->call('accept', $pending->id)->assertForbidden();

        $this->assertFalse($pending->fresh()->isApproved());
    }

    public function test_admin_can_edit_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        Livewire::actingAs($admin)->test(Users::class)
            ->call('edit', $user->id)
            ->set('editName', 'Nume Nou')
            ->set('editEmail', 'nou-email@example.com')
            ->call('save')
            ->assertSet('editingId', null);

        $this->assertSame('Nume Nou', $user->fresh()->name);
        $this->assertSame('nou-email@example.com', $user->fresh()->email);
    }

    public function test_ghost_logs_in_as_user_and_can_return(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $pending = User::factory()->pending()->create();

        $this->actingAs($admin)->post("/admin/users/{$pending->id}/ghost")->assertForbidden();
        $this->actingAs($admin)->post("/admin/users/{$user->id}/ghost")->assertRedirect('/home');
        $this->assertAuthenticatedAs($user);

        $this->get('/admin/users')->assertForbidden();
        $this->post('/ghost/leave')->assertRedirect('/admin/users');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_non_ghost_session_cannot_use_leave(): void
    {
        $this->actingAs(User::factory()->create())->post('/ghost/leave')->assertForbidden();
    }
}
